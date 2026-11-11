<?php

namespace App\Support\Branches\Audit;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Http\Controllers\Tenant\Admin\CompanySettingController;
use App\Models\FiscalYear;
use App\Models\VoucherSequence;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Numbering (plan 12, section 5.8, invariant I7): every (fiscal year,
 * voucher type, series_branch_id) series is gapless and its counter row
 * (voucher_sequences.last_number) equals the highest number handed out;
 * series keys follow the year's numbering scope.
 *
 * Journal vouchers carry the numbers of every type except
 * VoucherType::ProductionEntry, whose numbers live on production_entries
 * (always the shared series, BR20); both are checked the same way. A series
 * may legitimately start above 1 only where an admin can set a starting
 * number (VoucherSequence::setStartingNumber()): the shared series, in a
 * year not numbered per branch, of one of the document types the company
 * settings offer it for (CompanySettingController::NUMBERED_SERIES). That
 * leaves no other trace, so those are listed as a WARN to confirm by hand;
 * any other series starting above 1, or counting numbers no document
 * carries, has lost numbers and FAILs.
 *
 * Every figure is one grouped query; gap positions are found in SQL with
 * an anti-join on the unique series index, never by loading numbers.
 */
final class NumberingChecks
{
    public function __construct(private readonly AuditContext $context) {}

    /**
     * @return list<BranchAuditResult>
     */
    public function run(): array
    {
        $series = $this->seriesFigures();
        $sequences = $this->sequences();

        return [
            $this->gapless($series),
            $this->counters($series, $sequences),
            $this->seriesKeys($series, $sequences),
            $this->startingNumbers($series),
            $this->missingSeriesCodes(),
        ];
    }

    /**
     * @param  Collection<string, object>  $series
     */
    private function gapless(Collection $series): BranchAuditResult
    {
        $findings = new AuditFindings;

        foreach ($series as $row) {
            $total = (int) $row->total;
            $first = (int) $row->first_number;
            $last = (int) $row->last_number;
            $span = $last - $first + 1;

            $missingStart = $first > 1 && ! $this->mayHaveStartingNumber($row);

            if ($total === (int) $row->distinct_numbers && $span === $total && $first >= 1 && ! $missingStart) {
                continue;
            }

            $problems = [];

            if ($total !== (int) $row->distinct_numbers) {
                $problems[] = ($total - (int) $row->distinct_numbers).' duplicate number(s)';
            }

            if ($span !== (int) $row->distinct_numbers) {
                $after = $this->gapStarts($row, $last);
                $problems[] = ($span - (int) $row->distinct_numbers).' missing number(s)'
                    .($after === [] ? '' : ' (missing after no. '.implode(', ', $after).')');
            }

            if ($first < 1) {
                $problems[] = "a number below 1 ({$first})";
            }

            if ($missingStart) {
                $problems[] = 'numbers 1 to '.($first - 1).' are missing (no starting number can be set for this series)';
            }

            $findings->addOne(sprintf('%s: %d number(s) from %d to %d, %s', $this->seriesLabel($row), $total, $first, $last, implode('; ', $problems)));
        }

        return $findings->result(
            'numbering.gapless',
            'I7',
            'Every series is gapless',
            BranchAuditResult::FAIL,
            "All {$series->count()} series are gapless with no duplicate.",
            ':count series with a gap, a duplicate number or missing first numbers.',
        );
    }

    /**
     * Each series' counter equals its highest number. A counter behind the
     * highest number would hand out a duplicate next; one ahead has skipped
     * numbers. A counter with no numbered document yet may sit above zero
     * only as a starting number (mayHaveStartingNumber()); anywhere else it
     * has burned numbers no document carries.
     *
     * @param  Collection<string, object>  $series
     * @param  Collection<string, object>  $sequences
     */
    private function counters(Collection $series, Collection $sequences): BranchAuditResult
    {
        $findings = new AuditFindings;

        foreach ($series as $key => $row) {
            $sequence = $sequences->get($key);

            if ($sequence === null) {
                $findings->addOne("{$this->seriesLabel($row)}: numbers up to {$row->last_number} exist but there is no counter row");

                continue;
            }

            if ((int) $sequence->last_number !== (int) $row->last_number) {
                $findings->addOne("{$this->seriesLabel($row)}: counter is at {$sequence->last_number}, highest number is {$row->last_number}");
            }
        }

        foreach ($sequences as $key => $sequence) {
            if ($series->has($key) || (int) $sequence->last_number === 0 || $this->mayHaveStartingNumber($sequence)) {
                continue;
            }

            $findings->addOne("{$this->seriesLabel($sequence)}: counter is at {$sequence->last_number} but no document carries a number (numbers burned)");
        }

        return $findings->result(
            'numbering.counters',
            'I7',
            'Series counters match the numbers issued',
            BranchAuditResult::FAIL,
            "All {$sequences->count()} counter(s) equal the highest number of their series.",
            ':count counter(s) out of step with their series.',
        );
    }

    /**
     * Series keys follow the year's scope: a shared year has only series
     * 0; a per_branch year numbers vouchers only in branch series, each of
     * an existing branch with a series code, and a voucher's series is its
     * own header branch.
     *
     * @param  Collection<string, object>  $series
     * @param  Collection<string, object>  $sequences
     */
    private function seriesKeys(Collection $series, Collection $sequences): BranchAuditResult
    {
        $findings = new AuditFindings;

        foreach ([$series, $sequences] as $rows) {
            foreach ($rows as $key => $row) {
                if ($rows === $sequences && $series->has($key)) {
                    continue;
                }

                $problem = $this->seriesKeyProblem($row, $rows === $sequences);

                if ($problem !== null) {
                    $findings->addOne("{$this->seriesLabel($row)}: {$problem}");
                }
            }
        }

        $foreign = DB::table('journal_vouchers')
            ->where('series_branch_id', '!=', VoucherSequence::SHARED_SERIES)
            ->whereColumn('series_branch_id', '!=', 'branch_id');
        [$count, $ids] = AuditContext::distinctIds($foreign, 'id');

        if ($count > 0) {
            $labels = $this->context->voucherLabels($ids);
            $findings->add($count, array_map(fn (int $id): string => "{$labels[$id]} is numbered in another branch's series than its header branch", $ids));
        }

        return $findings->result(
            'numbering.series_keys',
            'I7',
            'Series follow each year\'s numbering scope',
            BranchAuditResult::FAIL,
            'Shared years use only the shared series; per-branch years number each voucher in its own branch\'s coded series.',
            ':count series or voucher(s) keyed against their year\'s numbering scope.',
        );
    }

    /**
     * WARN: series that may have a starting number (mayHaveStartingNumber())
     * whose first number is above 1, which is only right if an admin set
     * one. Every other series starting above 1 already FAILs in gapless().
     *
     * @param  Collection<string, object>  $series
     */
    private function startingNumbers(Collection $series): BranchAuditResult
    {
        $findings = new AuditFindings;

        foreach ($series as $row) {
            if ((int) $row->first_number > 1 && $this->mayHaveStartingNumber($row)) {
                $findings->addOne("{$this->seriesLabel($row)} starts at {$row->first_number}");
            }
        }

        return $findings->result(
            'numbering.starting_numbers',
            'I7',
            'Series that start above 1',
            BranchAuditResult::WARN,
            'Every series starts at 1.',
            ':count shared series start above 1; confirm a starting number was set for each (otherwise their first numbers are missing).',
        );
    }

    /**
     * WARN: active branches without a series code in a per_branch year that
     * is open or has not been posted into yet; they cannot post there.
     */
    private function missingSeriesCodes(): BranchAuditResult
    {
        $findings = new AuditFindings;
        $uncoded = array_filter(
            $this->context->branches,
            fn (object $branch): bool => $branch->is_active && trim((string) $branch->series_code) === '',
        );

        foreach ($this->context->fiscalYears as $year) {
            if ($year->numbering_scope !== FiscalYear::NUMBERING_PER_BRANCH) {
                continue;
            }

            if ($year->status !== FiscalYearStatus::Open->value && $year->has_vouchers) {
                continue;
            }

            foreach ($uncoded as $branch) {
                $findings->addOne("FY {$year->name}: {$this->context->branchName($branch->id)} has no series code and cannot post");
            }
        }

        return $findings->result(
            'numbering.series_codes',
            'I7',
            'Branches have series codes where numbering is per branch',
            BranchAuditResult::WARN,
            'Every active branch has a series code in each open per-branch year (or no year numbers per branch).',
            ':count branch(es) without a series code in a per-branch year.',
        );
    }

    /**
     * Whether an admin can have given this series a starting number, so it
     * may begin above 1 (or its counter sit above 0 with no document yet):
     * VoucherSequence::setStartingNumber() only moves the shared series, it
     * refuses a year numbered per branch, and the settings offer it only for
     * the types in CompanySettingController::NUMBERED_SERIES (never for
     * production entries).
     */
    private function mayHaveStartingNumber(object $row): bool
    {
        if ((int) $row->series_branch_id !== VoucherSequence::SHARED_SERIES) {
            return false;
        }

        $year = $this->context->fiscalYears[(int) $row->fiscal_year_id] ?? null;

        if ($year === null || $year->numbering_scope === FiscalYear::NUMBERING_PER_BRANCH) {
            return false;
        }

        return in_array((string) $row->voucher_type, self::startableTypes(), true);
    }

    /**
     * Voucher types an admin can set a starting number for, from the one
     * list the settings page uses.
     *
     * @return list<string>
     */
    private static function startableTypes(): array
    {
        return array_values(array_map(
            fn (array $series): string => $series['type']->value,
            CompanySettingController::NUMBERED_SERIES,
        ));
    }

    private function seriesKeyProblem(object $row, bool $isCounter): ?string
    {
        $seriesBranchId = (int) $row->series_branch_id;
        $year = $this->context->fiscalYears[(int) $row->fiscal_year_id] ?? null;
        $perBranch = $year !== null && $year->numbering_scope === FiscalYear::NUMBERING_PER_BRANCH;
        $productionEntries = $row->voucher_type === VoucherType::ProductionEntry->value;

        if ($seriesBranchId === VoucherSequence::SHARED_SERIES) {
            if (! $perBranch || $productionEntries || ($isCounter && (int) $row->last_number === 0)) {
                return null;
            }

            return 'shared series used in a year numbered per branch';
        }

        if ($productionEntries) {
            return 'production entries are always numbered in the shared series';
        }

        if (! $perBranch) {
            return 'branch series used in a year with shared numbering';
        }

        $branch = $this->context->branches[$seriesBranchId] ?? null;

        if ($branch === null) {
            return 'series of a branch that does not exist';
        }

        return trim((string) $branch->series_code) === '' ? 'series of a branch without a series code' : null;
    }

    /**
     * Figures per series, keyed "fiscal year|type|series": journal vouchers
     * (every type but production entries) and production entries.
     *
     * @return Collection<string, object>
     */
    private function seriesFigures(): Collection
    {
        $vouchers = DB::table('journal_vouchers')
            ->where('voucher_type', '!=', VoucherType::ProductionEntry->value)
            ->groupBy('fiscal_year_id', 'voucher_type', 'series_branch_id')
            ->selectRaw('fiscal_year_id, voucher_type, series_branch_id, COUNT(*) as total, COUNT(DISTINCT voucher_number) as distinct_numbers, MIN(voucher_number) as first_number, MAX(voucher_number) as last_number')
            ->get();

        $entries = DB::table('production_entries')
            ->groupBy('fiscal_year_id')
            ->selectRaw('fiscal_year_id, COUNT(*) as total, COUNT(DISTINCT number) as distinct_numbers, MIN(number) as first_number, MAX(number) as last_number')
            ->get()
            ->map(fn (object $row): object => (object) [
                ...(array) $row,
                'voucher_type' => VoucherType::ProductionEntry->value,
                'series_branch_id' => VoucherSequence::SHARED_SERIES,
            ]);

        return $vouchers->concat($entries)->keyBy(fn (object $row): string => $this->key($row));
    }

    /**
     * @return Collection<string, object>
     */
    private function sequences(): Collection
    {
        return DB::table('voucher_sequences')
            ->get(['fiscal_year_id', 'voucher_type', 'series_branch_id', 'last_number'])
            ->keyBy(fn (object $row): string => $this->key($row));
    }

    /**
     * Up to five numbers after which the series has a hole, from the unique
     * series index: a number whose successor is missing, below the highest.
     *
     * @return list<int>
     */
    private function gapStarts(object $row, int $last): array
    {
        $productionEntries = $row->voucher_type === VoucherType::ProductionEntry->value;
        $table = $productionEntries ? 'production_entries' : 'journal_vouchers';
        $column = $productionEntries ? 'number' : 'voucher_number';

        $query = DB::table($table.' as a')
            ->where('a.fiscal_year_id', $row->fiscal_year_id)
            ->where('a.'.$column, '<', $last)
            ->whereNotExists(function (QueryBuilder $next) use ($table, $column, $productionEntries): void {
                $next->selectRaw('1')
                    ->from($table.' as b')
                    ->whereColumn('b.fiscal_year_id', 'a.fiscal_year_id')
                    ->whereRaw("b.{$column} = a.{$column} + 1");

                if (! $productionEntries) {
                    $next->whereColumn('b.voucher_type', 'a.voucher_type')
                        ->whereColumn('b.series_branch_id', 'a.series_branch_id');
                }
            });

        if (! $productionEntries) {
            $query->where('a.voucher_type', $row->voucher_type)->where('a.series_branch_id', $row->series_branch_id);
        }

        return $query->orderBy('a.'.$column)->limit(5)->pluck('a.'.$column)->map(fn (mixed $number): int => (int) $number)->all();
    }

    private function key(object $row): string
    {
        return ((int) $row->fiscal_year_id).'|'.((string) $row->voucher_type).'|'.((int) $row->series_branch_id);
    }

    private function seriesLabel(object $row): string
    {
        $series = (int) $row->series_branch_id === VoucherSequence::SHARED_SERIES
            ? 'shared series'
            : 'series of '.$this->context->branchName((int) $row->series_branch_id);

        return "FY {$this->context->fiscalYearName($row->fiscal_year_id)}, {$row->voucher_type}, {$series}";
    }
}
