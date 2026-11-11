<?php

namespace App\Support\Branches\Audit;

use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchAudit;
use App\Support\Branches\BranchContext;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The small, company-wide facts every audit check needs (branches, fiscal
 * years, Main, the clearing account, the audit date), read once, plus the
 * SQL and labelling helpers the checks share.
 *
 * $clearingAccountId is null both when the chart has no ASA25 and when the
 * account holding that code is a tenant's own account that had it before
 * the code was reserved (BranchAccounts::codeConflictMessage(), BR22); the
 * latter also sets $clearingConflict, so the checks can say which.
 *
 * Reads go through the query builder (DB::table), so no Eloquent access
 * scope can narrow them; BranchAudit runs everything inside
 * BranchContext::unrestricted() as well. Every sum of a decimal column is
 * a scaled-integer SUM (paisa / 1/10000 units), the same SQLite REAL
 * affinity reason Item::currentStockByItem() gives, so no float ever
 * decides a check.
 */
final class AuditContext
{
    /**
     * Column listings already read, per table.
     *
     * @var array<string, list<string>>
     */
    private array $columns = [];

    /**
     * @param  array<int, object{id: int, name: string, code: ?string, is_main: bool, is_active: bool, series_code: ?string, default_store_id: ?int}>  $branches
     * @param  array<int, object{id: int, name: string, status: string, start_date: string, end_date: string, numbering_scope: string, has_vouchers: bool}>  $fiscalYears  oldest first
     */
    public function __construct(
        public readonly array $branches,
        public readonly array $fiscalYears,
        public readonly ?int $mainBranchId,
        public readonly ?int $clearingAccountId,
        public readonly string $asOf,
        public readonly ?string $clearingConflict = null,
    ) {}

    public static function load(string $asOf): self
    {
        $branches = [];

        foreach (DB::table('branches')->orderBy('id')->get() as $row) {
            $branches[(int) $row->id] = (object) [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'code' => $row->code === null ? null : (string) $row->code,
                'is_main' => (bool) $row->is_main,
                'is_active' => (bool) $row->is_active,
                'series_code' => $row->series_code === null ? null : (string) $row->series_code,
                'default_store_id' => $row->default_store_id === null ? null : (int) $row->default_store_id,
            ];
        }

        $withVouchers = array_fill_keys(
            DB::table('journal_vouchers')->distinct()->pluck('fiscal_year_id')->map(fn (mixed $id): int => (int) $id)->all(),
            true,
        );

        $fiscalYears = [];

        foreach (DB::table('fiscal_years')->orderBy('start_date')->orderBy('id')->get() as $row) {
            $fiscalYears[(int) $row->id] = (object) [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'status' => (string) $row->status,
                'start_date' => substr((string) $row->start_date, 0, 10),
                'end_date' => substr((string) $row->end_date, 0, 10),
                'numbering_scope' => (string) ($row->numbering_scope ?? 'shared'),
                'has_vouchers' => isset($withVouchers[(int) $row->id]),
            ];
        }

        [$clearingId, $clearingConflict] = self::controlAccount(BranchAccounts::CLEARING_ACCOUNT_CODE);

        return new self(
            $branches,
            $fiscalYears,
            BranchContext::mainBranchIdOrNull(),
            $clearingId,
            $asOf,
            $clearingConflict,
        );
    }

    /**
     * The inter-branch control account with $code: [its id, null] when it is
     * the real control account, [null, why] when the code belongs to one of
     * the tenant's own accounts (named otherwise, BranchAccounts::
     * codeConflictMessage()), and [null, null] when no account has the code.
     *
     * @return array{0: ?int, 1: ?string}
     */
    public static function controlAccount(string $code): array
    {
        $account = DB::table('accounts')->where('code', $code)->orderBy('id')->first(['id', 'name']);

        if ($account === null) {
            return [null, null];
        }

        if (BranchAccounts::codeConflictMessage($code, (string) $account->name) !== null) {
            return [null, "code {$code} is used by your own account \"{$account->name}\"; inter-branch postings are refused until it is recoded"];
        }

        return [(int) $account->id, null];
    }

    /**
     * Fiscal years with at least one journal voucher, oldest first. A year
     * created ahead of time but never posted into has nothing to audit.
     *
     * @return list<object{id: int, name: string, status: string, start_date: string, end_date: string, numbering_scope: string, has_vouchers: bool}>
     */
    public function postedFiscalYears(): array
    {
        return array_values(array_filter($this->fiscalYears, fn (object $year): bool => $year->has_vouchers));
    }

    public function branchName(mixed $branchId): string
    {
        if ($branchId === null) {
            return '(no branch)';
        }

        $branch = $this->branches[(int) $branchId] ?? null;

        return $branch === null ? "unknown branch #{$branchId}" : "{$branch->name} (#{$branch->id})";
    }

    public function fiscalYearName(mixed $fiscalYearId): string
    {
        $year = $this->fiscalYears[(int) $fiscalYearId] ?? null;

        return $year === null ? "fiscal year #{$fiscalYearId}" : $year->name;
    }

    /**
     * "JV #id (type no. n, FY name)" for each voucher id, one query.
     *
     * @param  list<int>  $voucherIds
     * @return array<int, string>
     */
    public function voucherLabels(array $voucherIds): array
    {
        if ($voucherIds === []) {
            return [];
        }

        $labels = [];

        foreach (DB::table('journal_vouchers')->whereIn('id', $voucherIds)->get(['id', 'voucher_type', 'voucher_number', 'series_branch_id', 'fiscal_year_id']) as $row) {
            $series = (int) $row->series_branch_id === 0 ? '' : ' series '.$this->branchName((int) $row->series_branch_id);
            $labels[(int) $row->id] = "JV #{$row->id} ({$row->voucher_type} no. {$row->voucher_number}{$series}, FY {$this->fiscalYearName($row->fiscal_year_id)})";
        }

        foreach ($voucherIds as $id) {
            $labels[$id] ??= "JV #{$id}";
        }

        return $labels;
    }

    public function hasColumn(string $table, string $column): bool
    {
        $this->columns[$table] ??= Schema::hasTable($table) ? Schema::getColumnListing($table) : [];

        return in_array($column, $this->columns[$table], true);
    }

    /**
     * A 2-decimal column as whole paisa (NULL as 0).
     */
    public static function paisa(string $expression): string
    {
        return 'CAST(ROUND(COALESCE('.$expression.', 0) * 100) AS '.self::integerType().')';
    }

    public static function integerType(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';
    }

    public static function money(mixed $scaled): Money
    {
        return Money::of(BigDecimal::of((int) $scaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }

    /**
     * How many distinct values of $column the query matches, and the first
     * BranchAudit::LIST_LIMIT of them in ascending order. Two queries, no
     * row is loaded beyond the listed ids.
     *
     * @return array{0: int, 1: list<int>}
     */
    public static function distinctIds(Builder $query, string $column): array
    {
        $count = (clone $query)->distinct()->count($column);

        if ($count === 0) {
            return [0, []];
        }

        $ids = (clone $query)
            ->select($column)
            ->distinct()
            ->orderBy($column)
            ->limit(BranchAudit::LIST_LIMIT)
            ->pluck($column)
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return [$count, $ids];
    }
}
