<?php

namespace App\Support\Branches;

use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Party balances per branch for the Debtors and Creditors reports (plan 12,
 * section 5.9, BR14).
 *
 * A customer or supplier is one company account; its balance in a branch is
 * that account's journal lines tagged with the branch (journal_voucher_lines
 * .branch_id), the same predicate the ledger reports use. A party line is
 * tagged with the branch of the document it settles (section 5.3), so a
 * branch's receivable is what that branch's invoices still hold.
 *
 * Read in ONE grouped query over (account, branch) through
 * BranchReportTotals::netByAccountAndBranch(), scaled-integer SUM, zero
 * filled per column. BranchAccessScope still applies, so a restricted user
 * only ever sees their own branches' lines.
 *
 * How a party report uses it (SalesPurchaseReportController):
 *
 * - The single balance column: pass BranchFilter::lineBranchIds() into the
 *   report's own ledger-balance query (BranchFilter::narrowLines()), so the
 *   balance shown is the filtered one.
 * - `by_branch=1` (BranchFilter::byBranch()): withBranchColumns() adds
 *   `by_branch` to every row and returns the columns and per-column totals
 *   for the `branchReport` prop; groupsWithBranchTotals() adds per-branch
 *   subtotals to the "Group by area" groups.
 */
final class PartyBranchBalances
{
    /**
     * Balance per ledger account and branch, in the report's sign (credit
     * minus debit for a credit-normal party, debit minus credit otherwise),
     * inside one fiscal year and, when $asOf is set, up to that date.
     *
     * @param  array<int|string, int|string|null>  $accountIds
     * @param  list<int>|null  $branchIds  BranchFilter::lineBranchIds(); null adds no predicate.
     * @return array<int, array<int, Money>> account id => branch id => balance
     */
    public static function byAccountAndBranch(array $accountIds, ?int $fiscalYearId, ?string $asOf, bool $creditNormal, ?array $branchIds): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($accountIds))));

        if ($ids === [] || $fiscalYearId === null) {
            return [];
        }

        $nets = BranchReportTotals::netByAccountAndBranch(
            fn (Builder $voucher) => $voucher
                ->where('fiscal_year_id', $fiscalYearId)
                ->when($asOf !== null, fn (Builder $query) => $query->whereDate('date', '<=', $asOf)),
            $branchIds,
            $ids,
        );

        if (! $creditNormal) {
            return $nets;
        }

        return array_map(
            fn (array $byBranch): array => array_map(fn (Money $net): Money => $net->negated(), $byBranch),
            $nets,
        );
    }

    /**
     * Adds `by_branch` (branch id => decimal string, every column present,
     * zero where the branch has no line) to each party row, and returns the
     * columns and per-column totals.
     *
     * The columns are BranchFilter::columns() over the branches that carry
     * lines on these accounts, so with the same filter the row's `balance`
     * always equals the sum of its `by_branch` amounts.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  Rows carrying the party key in `id`.
     * @param  array<int|string, int|string|null>  $accountIdByPartyId  party id => ledger account id
     * @return array{rows: Collection<int, array<string, mixed>>, columns: list<array{id: int, name: string, code: string}>, totals: array<int, string>}
     */
    public static function withBranchColumns(BranchFilter $filter, Collection $rows, array $accountIdByPartyId, ?int $fiscalYearId, bool $creditNormal, ?string $asOf = null): array
    {
        $listedAccountIds = [];

        foreach ($rows as $row) {
            $accountId = $accountIdByPartyId[$row['id']] ?? null;

            if ($accountId !== null) {
                $listedAccountIds[] = (int) $accountId;
            }
        }

        $balances = self::byAccountAndBranch($listedAccountIds, $fiscalYearId, $asOf, $creditNormal, $filter->lineBranchIds());
        $columns = $filter->columns(BranchReportTotals::branchIdsIn($balances));

        $totals = [];

        foreach ($columns as $column) {
            $totals[$column['id']] = Money::zero();
        }

        $rows = $rows->map(function (array $row) use ($accountIdByPartyId, $balances, $columns, &$totals): array {
            $accountId = $accountIdByPartyId[$row['id']] ?? null;
            $byAccount = $accountId === null ? [] : ($balances[(int) $accountId] ?? []);
            $byBranch = [];

            foreach ($columns as $column) {
                $amount = $byAccount[$column['id']] ?? Money::zero();
                $totals[$column['id']] = $totals[$column['id']]->plus($amount);
                $byBranch[$column['id']] = $amount->toString();
            }

            return [...$row, 'by_branch' => $byBranch];
        })->values();

        return [
            'rows' => $rows,
            'columns' => $columns,
            'totals' => array_map(fn (Money $total): string => $total->toString(), $totals),
        ];
    }

    /**
     * Adds `by_branch_totals` (branch id => decimal string) to each "Group
     * by area" group whose rows went through withBranchColumns(). Null
     * groups (grouping off) stay null.
     *
     * @param  list<array{area: string, rows: list<array<string, mixed>>, totals: array<string, string>}>|null  $groups
     * @param  list<array{id: int, name: string, code: string}>  $columns
     * @return list<array<string, mixed>>|null
     */
    public static function groupsWithBranchTotals(?array $groups, array $columns): ?array
    {
        if ($groups === null) {
            return null;
        }

        return array_map(function (array $group) use ($columns): array {
            $totals = [];

            foreach ($columns as $column) {
                $totals[$column['id']] = Money::sum(array_map(
                    fn (array $row): Money => Money::of($row['by_branch'][$column['id']] ?? '0.00'),
                    $group['rows'],
                ))->toString();
            }

            return [...$group, 'by_branch_totals' => $totals];
        }, $groups);
    }
}
