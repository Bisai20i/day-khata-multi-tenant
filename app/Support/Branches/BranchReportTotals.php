<?php

namespace App\Support\Branches;

use App\Models\JournalVoucherLine;
use App\Models\Store;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The branch-shaped reads behind the accounting reports (plan 12, BR13):
 * ledger nets per (account, branch) in one grouped query, and periodic
 * closing stock for a set of branches.
 *
 * Both follow the conventions the year-end close already uses, so a branch's
 * figures on screen are the figures FiscalYear::close() would post for it.
 */
final class BranchReportTotals
{
    /**
     * Net (debit - credit) per account and branch, in ONE grouped query,
     * never a query per branch.
     *
     * $voucherConstraint is the report's own fiscal-year and date rule and
     * runs inside whereHas('journalVoucher'), exactly as the company-wide
     * aggregators apply it; the branch predicate goes straight onto the
     * lines table (plan 12, section 8). Scaled-integer SUM for the reason
     * AccountingReportController::periodTotalsByAccount() gives: SQLite
     * gives a decimal column REAL affinity and a plain SUM() comes back as a
     * float. BranchAccessScope still applies, so a restricted user only ever
     * gets their own branches' lines.
     *
     * @param  Closure(Builder): mixed  $voucherConstraint
     * @param  list<int>|null  $branchIds  Null adds no branch predicate.
     * @param  list<int>|null  $accountIds  Null for every account.
     * @return array<int, array<int, Money>> account id => branch id => net
     */
    public static function netByAccountAndBranch(Closure $voucherConstraint, ?array $branchIds, ?array $accountIds = null): array
    {
        $cast = JournalVoucherLine::query()->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        $query = JournalVoucherLine::query()
            ->whereHas('journalVoucher', $voucherConstraint)
            ->when($accountIds !== null, fn (Builder $lines) => $lines->whereIn('journal_voucher_lines.account_id', $accountIds));

        $rows = BranchFilter::narrowLines($query, $branchIds)
            ->selectRaw(
                "account_id, branch_id,
                 COALESCE(SUM(CAST(ROUND(debit * 100) AS {$cast})), 0) - COALESCE(SUM(CAST(ROUND(credit * 100) AS {$cast})), 0) as net_scaled"
            )
            ->groupBy('account_id', 'branch_id')
            ->toBase()
            ->get();

        $nets = [];

        foreach ($rows as $row) {
            $nets[(int) $row->account_id][(int) $row->branch_id] = Money::of(
                BigDecimal::of((int) $row->net_scaled)->dividedBy(100, 2, RoundingMode::Unnecessary)
            );
        }

        return $nets;
    }

    /**
     * Every branch id that appears in a netByAccountAndBranch() result.
     *
     * @param  array<int, array<int, Money>>  $nets
     * @return list<int>
     */
    public static function branchIdsIn(array $nets): array
    {
        $ids = [];

        foreach ($nets as $byBranch) {
            foreach (array_keys($byBranch) as $branchId) {
                $ids[$branchId] = $branchId;
            }
        }

        return array_values($ids);
    }

    /**
     * Periodic (trading) closing stock of a set of branches, as at $asOf.
     *
     * Same convention as FiscalYear::periodicClosingStockByBranch() at year
     * end (plan 12, section 5.5): a branch other than Main is the sum of its
     * stores' StockCosting::totalPeriodicClosingValue() (quantity per store,
     * company average cost); Main is the company figure minus every other
     * branch's stores, so it absorbs the per-store rounding and any movement
     * without a store, and the branches always add up to the company figure
     * exactly (invariant I4). Hence a set containing Main is the company
     * figure minus the stores of the branches outside the set. Trading stock
     * only, as in AccountingReportController::stockPosition() (MC9).
     *
     * @param  list<int>  $branchIds
     */
    public static function periodicClosingValue(string $asOf, array $branchIds): Money
    {
        $storeBranches = self::storeBranches();
        $mainBranchId = BranchContext::mainBranchIdOrNull();

        if ($mainBranchId !== null && in_array($mainBranchId, $branchIds, true)) {
            $outsideStoreIds = array_keys(array_filter(
                $storeBranches,
                fn (int $branchId): bool => ! in_array($branchId, $branchIds, true),
            ));

            return self::valuation($asOf)->minus(self::storesValue($asOf, $outsideStoreIds));
        }

        $insideStoreIds = array_keys(array_filter(
            $storeBranches,
            fn (int $branchId): bool => in_array($branchId, $branchIds, true),
        ));

        return self::storesValue($asOf, $insideStoreIds);
    }

    /**
     * periodicClosingValue() for each branch of $branchIds on its own, keyed
     * by branch id, valuing each store at most once.
     *
     * @param  list<int>  $branchIds
     * @return array<int, Money>
     */
    public static function periodicClosingValuesByBranch(string $asOf, array $branchIds): array
    {
        $storeBranches = self::storeBranches();
        $mainBranchId = BranchContext::mainBranchIdOrNull();
        $withMain = $mainBranchId !== null && in_array($mainBranchId, $branchIds, true);

        $storeValues = [];

        foreach ($storeBranches as $storeId => $branchId) {
            $needed = $branchId !== $mainBranchId && ($withMain || in_array($branchId, $branchIds, true));

            if ($needed) {
                $storeValues[$storeId] = self::valuation($asOf, $storeId);
            }
        }

        $values = [];

        foreach ($branchIds as $branchId) {
            if ($branchId === $mainBranchId) {
                // Every non-Main store was valued above because Main is in the set.
                $values[$branchId] = self::valuation($asOf)->minus(Money::sum($storeValues));

                continue;
            }

            $values[$branchId] = Money::sum(array_map(
                fn (int $storeId): Money => $storeValues[$storeId],
                array_keys(array_filter($storeBranches, fn (int $owner): bool => $owner === $branchId)),
            ));
        }

        return $values;
    }

    /**
     * Ids of the branches that own at least one store (their closing stock
     * can be non-zero even with no ledger line).
     *
     * @return list<int>
     */
    public static function branchIdsWithStores(): array
    {
        return array_values(array_unique(self::storeBranches()));
    }

    /**
     * store id => branch id, every store (active or not, as at year end).
     *
     * @return array<int, int>
     */
    private static function storeBranches(): array
    {
        // Whole-truth read: the company's stores and their stock are facts,
        // not something to narrow to the viewer's branches (the lines are).
        return BranchContext::unrestricted(fn (): array => Store::query()
            ->orderBy('id')
            ->get(['id', 'branch_id'])
            ->mapWithKeys(fn (Store $store): array => [(int) $store->id => (int) $store->branch_id])
            ->all());
    }

    /**
     * @param  list<int>  $storeIds
     */
    private static function storesValue(string $asOf, array $storeIds): Money
    {
        return Money::sum(array_map(
            fn (int $storeId): Money => self::valuation($asOf, $storeId),
            $storeIds,
        ));
    }

    /**
     * StockCosting's periodic closing value (one store, or the company when
     * $storeId is null), read without the viewer's branch scope.
     */
    private static function valuation(string $asOf, ?int $storeId = null): Money
    {
        return BranchContext::unrestricted(fn (): Money => StockCosting::totalPeriodicClosingValue($asOf, $storeId));
    }
}
