<?php

namespace App\Support\Branches;

use App\Enums\StockMovementType;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * The branch side of the stock reports (plan 12, sections 5.5 and 5.9, BR15).
 *
 * Stock carries no branch column: a movement belongs to a store and a store
 * to exactly one branch (I5), so "branch X" on a stock report means "the
 * stores of X" (BranchFilter::storeIds()). Quantities are narrowed with one
 * whereIn on store_id, never a query per store; values keep the company-wide
 * average cost and the year-end convention (StockCosting::
 * branchValuationRows()), so a branch's trading stock here equals the
 * closing stock its statements show.
 *
 * Parity: with a filter that narrows nothing and no by-branch columns every
 * method hands the request straight to the code the report always ran.
 */
final class BranchStock
{
    /**
     * The valuation rows a stock report shows: one store when one was asked
     * for (already passed through BranchFilter::allowedStoreId()); the whole
     * company, exactly as before, when the filter narrows nothing and no
     * columns are asked for; otherwise the covered branches' stores, with
     * `by_branch` on every row when $withBranches.
     *
     * @param  array<string, mixed>  $filters  StockCosting::valuationRows() filters
     * @return Collection<int, array<string, mixed>>
     */
    public static function valuationRows(BranchFilter $filter, string $asOf, ?int $storeId, array $filters = [], bool $withBranches = false): Collection
    {
        if ($storeId !== null) {
            return StockCosting::valuationRows($asOf, $storeId, $filters);
        }

        $branchIds = $withBranches
            ? array_column(self::columns($filter), 'id')
            : $filter->effectiveIds();

        if ($branchIds === null) {
            return StockCosting::valuationRows($asOf, null, $filters);
        }

        return StockCosting::branchValuationRows(
            $asOf,
            $branchIds,
            $filter->storeBranches(),
            BranchContext::mainBranchIdOrNull(),
            $filters,
            $withBranches,
        );
    }

    /**
     * The by-branch columns of a stock report: the covered branches when
     * narrowed, otherwise every active branch plus any inactive one that
     * still owns a store (its stock is real), so the columns add up to the
     * company figure.
     *
     * @return list<array{id: int, name: string, code: string}>
     */
    public static function columns(BranchFilter $filter): array
    {
        return $filter->columns(array_values(array_unique($filter->storeBranches())));
    }

    /**
     * A row's `by_branch` figures as strings for the page, one entry per
     * column, zero where the branch holds nothing.
     *
     * @param  array<int, array{quantity: Quantity, value: Money}>  $byBranch
     * @param  list<array{id: int, name: string, code: string}>  $columns
     * @return array<int, array{quantity: string, value: string}>
     */
    public static function presentRow(array $byBranch, array $columns): array
    {
        $out = [];

        foreach ($columns as $column) {
            $figures = $byBranch[$column['id']] ?? ['quantity' => Quantity::zero(), 'value' => Money::zero()];
            $out[$column['id']] = ['quantity' => $figures['quantity']->toString(), 'value' => $figures['value']->toString()];
        }

        return $out;
    }

    /**
     * Value total per column over rows that carry `by_branch` (quantities
     * are not totalled: they are in different units).
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  list<array{id: int, name: string, code: string}>  $columns
     * @return array<int, string>
     */
    public static function valueTotals(iterable $rows, array $columns): array
    {
        $totals = [];

        foreach ($columns as $column) {
            $totals[$column['id']] = Money::zero();
        }

        foreach ($rows as $row) {
            foreach ($row['by_branch'] ?? [] as $branchId => $figures) {
                if (isset($totals[$branchId])) {
                    $totals[$branchId] = $totals[$branchId]->plus($figures['value']);
                }
            }
        }

        return array_map(fn (Money $total): string => $total->toString(), $totals);
    }

    /**
     * The store dropdown of a stock report: limited to the covered branches'
     * stores when the filter narrows (no extra query, BranchFilter has the
     * map already or reads it once), untouched otherwise.
     *
     * @param  Builder<Store>  $stores
     * @return Builder<Store>
     */
    public static function storeOptions(BranchFilter $filter, Builder $stores): Builder
    {
        $storeIds = $filter->storeIds();

        return $storeIds === null ? $stores : $stores->whereIn($stores->qualifyColumn('id'), $storeIds);
    }

    /**
     * Keeps, among the transfer legs of a movement query over a SET of
     * stores, only those that cross the set's edge: a TransferIn whose
     * source store is outside the set and a TransferOut whose destination is
     * outside it. A transfer between two stores of the same set relocates
     * the set's own goods, so like the all-stores view (which leaves every
     * transfer out) it is neither stock in nor stock out. Non-transfer rows
     * are untouched. A correlated NOT EXISTS on the transfer line, so the
     * outer query's columns and grouping are left alone.
     *
     * @template TBuilder of Builder|QueryBuilder
     *
     * @param  TBuilder  $movements  a query on item_stock_movements
     * @param  list<int>  $storeIds
     * @return TBuilder
     */
    public static function onlyCrossingTransfers(Builder|QueryBuilder $movements, array $storeIds, string $table = 'item_stock_movements'): Builder|QueryBuilder
    {
        $morph = (new StockTransferLine)->getMorphClass();
        $inside = fn (string $storeColumn) => function (QueryBuilder $sub) use ($table, $morph, $storeIds, $storeColumn): void {
            $sub->selectRaw('1')
                ->from('stock_transfer_lines')
                ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.stock_transfer_id')
                ->whereColumn('stock_transfer_lines.id', "{$table}.reference_id")
                ->where("{$table}.reference_type", $morph)
                ->whereIn("stock_transfers.{$storeColumn}", $storeIds);
        };

        return $movements->where(function ($query) use ($table, $inside): void {
            $query->whereNotIn("{$table}.movement_type", [StockMovementType::TransferIn->value, StockMovementType::TransferOut->value])
                ->orWhere(fn ($legs) => $legs->where("{$table}.movement_type", StockMovementType::TransferIn->value)->whereNotExists($inside('from_store_id')))
                ->orWhere(fn ($legs) => $legs->where("{$table}.movement_type", StockMovementType::TransferOut->value)->whereNotExists($inside('to_store_id')));
        });
    }
}
