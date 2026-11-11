<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FiscalYearArchive;
use App\Support\Branches\BranchContext;
use App\Support\Branches\SeriesNumber;
use App\Support\FiscalYear\FiscalYearArchiver;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only browsing of an already-archived fiscal year's cold-storage
 * ledger (see App\Support\FiscalYear\FiscalYearArchiver). Every query here
 * runs against FiscalYearArchiver::connectionFor()'s `PRAGMA query_only`
 * connection, never the live tenant database - nothing in this controller
 * writes anything.
 */
class ArchivedFiscalYearController extends Controller
{
    public function show(FiscalYearArchive $fiscalYearArchive): Response
    {
        $connection = FiscalYearArchiver::connectionFor($fiscalYearArchive);

        // The integer cast keyword differs by driver (SIGNED on MySQL,
        // INTEGER on SQLite); the archive connection's own driver decides.
        $cast = DB::connection($connection)->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        // Archives written since BR20 carry the series code of a per_branch
        // year's vouchers; older files (all shared years) have no such column.
        $hasSeries = $this->hasSeriesColumn($connection);
        $seriesColumn = $hasSeries ? 'v.series_code' : 'NULL as series_code';

        $branchIds = $this->visibleBranchIds($connection);

        $vouchers = DB::connection($connection)
            ->table('journal_vouchers as v')
            // For a restricted user only their branches' lines count towards
            // a voucher's totals, as withSum('lines') does on the live list.
            ->leftJoin('journal_voucher_lines as l', function (JoinClause $join) use ($branchIds): void {
                $join->on('l.journal_voucher_id', '=', 'v.id');

                if ($branchIds !== null) {
                    $join->whereIn('l.branch_id', $branchIds);
                }
            })
            ->when($branchIds !== null, fn (Builder $query) => $this->constrainVouchers($query, 'v', $branchIds))
            ->groupBy(...['v.id', 'v.voucher_type', 'v.voucher_number', ...($hasSeries ? ['v.series_code'] : []), 'v.date', 'v.narration', 'v.reason', 'v.created_by_name'])
            ->orderBy('v.date')
            ->when($hasSeries, fn ($query) => $query->orderBy('v.series_code'))
            ->orderBy('v.voucher_number')
            ->selectRaw("v.id, v.voucher_type, v.voucher_number, {$seriesColumn}, v.date, v.narration, v.reason, v.created_by_name, COALESCE(SUM(CAST(ROUND(l.debit * 100) AS {$cast})), 0) as total_debit_scaled, COALESCE(SUM(CAST(ROUND(l.credit * 100) AS {$cast})), 0) as total_credit_scaled")
            ->get()
            ->map(fn ($voucher) => [
                'id' => $voucher->id,
                'voucherType' => $voucher->voucher_type,
                'voucherNumber' => $voucher->voucher_number,
                'seriesNumber' => $this->seriesNumber($voucher),
                'date' => $voucher->date,
                'narration' => $voucher->narration,
                'reason' => $voucher->reason,
                'createdByName' => $voucher->created_by_name,
                'totalDebit' => $this->fromScaled($voucher->total_debit_scaled)->toString(),
                'totalCredit' => $this->fromScaled($voucher->total_credit_scaled)->toString(),
            ]);

        return Inertia::render('Tenant/Accounting/FiscalYearArchive/Show', [
            'fiscalYear' => $this->fiscalYearProp($fiscalYearArchive),
            'archive' => $this->archiveProp($fiscalYearArchive, $connection, $branchIds),
            'vouchers' => $vouchers,
        ]);
    }

    public function voucher(FiscalYearArchive $fiscalYearArchive, int $voucherId): Response
    {
        $connection = FiscalYearArchiver::connectionFor($fiscalYearArchive);

        $branchIds = $this->visibleBranchIds($connection);

        // A voucher a restricted user cannot see is a 404, as the live
        // voucher's scoped route binding is.
        $voucher = DB::connection($connection)->table('journal_vouchers')
            ->where('id', $voucherId)
            ->when($branchIds !== null, fn (Builder $query) => $this->constrainVouchers($query, 'journal_vouchers', $branchIds))
            ->first();

        if (! $voucher) {
            abort(404);
        }

        $lines = DB::connection($connection)
            ->table('journal_voucher_lines')
            ->where('journal_voucher_id', $voucherId)
            // Only the user's branches' lines, as the live voucher's scoped
            // `lines` relation shows them.
            ->when($branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $branchIds))
            ->orderBy('id')
            ->get()
            ->map(fn ($line) => [
                'id' => $line->id,
                'accountCode' => $line->account_code,
                'accountName' => $line->account_name,
                'debit' => Money::of($line->debit)->toString(),
                'credit' => Money::of($line->credit)->toString(),
                'narration' => $line->narration,
            ]);

        return Inertia::render('Tenant/Accounting/FiscalYearArchive/VoucherDetail', [
            'fiscalYear' => $this->fiscalYearProp($fiscalYearArchive),
            'archive' => $this->archiveProp($fiscalYearArchive, $connection, $branchIds),
            'voucher' => [
                'id' => $voucher->id,
                'voucherType' => $voucher->voucher_type,
                'voucherNumber' => $voucher->voucher_number,
                'seriesNumber' => $this->seriesNumber($voucher),
                'date' => $voucher->date,
                'narration' => $voucher->narration,
                'reason' => $voucher->reason,
                'createdByName' => $voucher->created_by_name,
            ],
            'lines' => $lines,
        ]);
    }

    /**
     * The branches the acting user may see in this archive, or null for no
     * limit (the owner, an all-branch user: every archive, old or new, reads
     * exactly as before).
     *
     * A restricted user needs the branch columns FiscalYearArchiver writes
     * since plan 12 section 5.7; an older file has none, cannot say which
     * branch a voucher belonged to, and is refused (fail closed) rather than
     * shown whole.
     *
     * @return list<int>|null
     */
    private function visibleBranchIds(string $connection): ?array
    {
        $branchIds = BranchContext::scopeBranchIds();

        if ($branchIds === null) {
            return null;
        }

        $schema = Schema::connection($connection);

        abort_unless(
            $schema->hasColumn('journal_vouchers', 'branch_id') && $schema->hasColumn('journal_voucher_lines', 'branch_id'),
            403,
            'This archive predates branch tracking and can only be browsed by a user with access to all branches.',
        );

        return $branchIds;
    }

    /**
     * The live JournalVoucher::applyBranchAccessConstraint() predicate, on
     * the archive's copy: a voucher is visible when its origin branch, or
     * the branch of any of its lines, is one of $branchIds.
     *
     * @param  list<int>  $branchIds
     */
    private function constrainVouchers(Builder $query, string $voucherTable, array $branchIds): Builder
    {
        return $query->where(fn (Builder $visible) => $visible
            ->whereIn("{$voucherTable}.branch_id", $branchIds)
            ->orWhereExists(fn (Builder $lines) => $lines
                ->selectRaw('1')
                ->from('journal_voucher_lines as branch_lines')
                ->whereColumn('branch_lines.journal_voucher_id', "{$voucherTable}.id")
                ->whereIn('branch_lines.branch_id', $branchIds)));
    }

    /**
     * Whether the archive file records series codes (files written since
     * BR20; earlier ones are shared-year archives without the column).
     */
    private function hasSeriesColumn(string $connection): bool
    {
        return Schema::connection($connection)->hasColumn('journal_vouchers', 'series_code');
    }

    /**
     * `{series_code}-{n}` for a voucher archived from a per_branch year,
     * where the bare number repeats once per branch; null for a shared-year
     * voucher, which the browser keeps showing as its bare number.
     */
    private function seriesNumber(object $voucher): ?string
    {
        $code = trim((string) ($voucher->series_code ?? ''));

        return $code === '' ? null : SeriesNumber::format((int) $voucher->voucher_number, $code);
    }

    /**
     * An archive file is always SQLite, which gives its decimal columns REAL
     * affinity - so a plain SUM() over an archived voucher's lines comes back
     * as a float and an ordinary total reads as 2261.1000000000004. The SUM
     * is taken on scaled integers instead and divided back here, which is
     * lossless at the two decimals a Money holds.
     */
    private function fromScaled(int|float|string $scaled): Money
    {
        return Money::of(BigDecimal::of((int) $scaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }

    /**
     * @return array{id: int, name: string, bsLabel: string, startDate: string, endDate: string}
     */
    private function fiscalYearProp(FiscalYearArchive $fiscalYearArchive): array
    {
        $fiscalYear = $fiscalYearArchive->fiscalYear;

        return [
            'id' => $fiscalYear->id,
            'name' => $fiscalYear->name,
            'bsLabel' => $fiscalYear->bsLabel,
            'startDate' => $fiscalYear->start_date->toDateString(),
            'endDate' => $fiscalYear->end_date->toDateString(),
        ];
    }

    /**
     * The counts are the archive's stored totals for an unrestricted user.
     * A restricted user gets the counts of what they can see instead, so the
     * header agrees with the list below it and says nothing about other
     * branches' volume. Every line in one of their branches belongs to a
     * voucher they can see (that is the visibility rule), so the line count
     * needs no join.
     *
     * @param  list<int>|null  $branchIds
     * @return array{id: int, archivedAt: string, archivedBy: string, voucherCount: int, lineCount: int}
     */
    private function archiveProp(FiscalYearArchive $fiscalYearArchive, string $connection, ?array $branchIds): array
    {
        $voucherCount = $fiscalYearArchive->voucher_count;
        $lineCount = $fiscalYearArchive->line_count;

        if ($branchIds !== null) {
            $voucherCount = $this->constrainVouchers(DB::connection($connection)->table('journal_vouchers'), 'journal_vouchers', $branchIds)->count();
            $lineCount = DB::connection($connection)->table('journal_voucher_lines')->whereIn('branch_id', $branchIds)->count();
        }

        return [
            'id' => $fiscalYearArchive->id,
            'archivedAt' => $fiscalYearArchive->archived_at->toDateTimeString(),
            'archivedBy' => $fiscalYearArchive->archiver?->name ?? 'Unknown',
            'voucherCount' => $voucherCount,
            'lineCount' => $lineCount,
        ];
    }
}
