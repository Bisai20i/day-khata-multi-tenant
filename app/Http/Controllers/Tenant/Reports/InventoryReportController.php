<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Store;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Branches\StockInTransit;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-item stock movement summary over a date range: opening quantity (net
 * signed movements strictly before the range), quantity in and out within
 * the range, closing quantity, and the closing value.
 *
 * Quantities are summed in SQL as scaled integers (SQLite gives a DECIMAL
 * column REAL affinity, so a plain SUM() there is a float) and carried as
 * App\Support\Money\Quantity at 4 decimals. Values come from
 * App\Support\Inventory\StockCosting (C10), the one place stock is valued -
 * this controller used to run its own weighted average, which rounded the
 * cost to 4 decimals before multiplying and counted transfer-in rows as
 * fresh stock (audit P0-17), so it disagreed with both the Balance Sheet
 * and the year-end closing entry.
 *
 * Transfers are excluded from the in/out columns of the all-stores view for
 * the same reason: relocating your own goods between stores is not stock
 * coming in or going out of the business, and counting both legs made the
 * movement columns look like double the real activity. With a single store
 * selected a transfer IS a real movement for that store, so it counts.
 *
 * Branches (plan 12, BR15): `branch` / `branches[]` narrow every column to
 * the covered branches' stores (BranchFilter::stockScope()); values come
 * from BranchStock::valuationRows() (year-end convention); a transfer
 * counts in or out only when it crosses the covered stores' edge. A
 * `store_id` outside the covered branches is dropped. Not multi-branch:
 * unchanged, plus `branchReport: null` and `inTransit: null`.
 *
 * In transit (BR19): a transfer between branches takes its TransferOut leg
 * at dispatch and its TransferIn leg at receipt, so while it is in transit
 * Closing qty is lower than Opening + In - Out wherever both ends are inside
 * the view (the all-stores view leaves transfers out of In and Out). Each
 * row then carries `transit`: per bucket (StockInTransit::WITHIN, the
 * difference; OUTGOING, already in Qty out; INCOMING, not yet in the view)
 * the quantity and the value fixed at dispatch, as at `to`. `inTransit` is
 * null unless some bucket has goods in it.
 */
class InventoryReportController extends Controller
{
    public function stockSummary(Request $request): Response
    {
        $openFiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        $from = ($request->date('from') ?? $openFiscalYear?->start_date ?? now()->subDays(30))->toDateString();
        $to = ($request->date('to') ?? $openFiscalYear?->end_date ?? now())->toDateString();
        $filter = BranchFilter::fromRequest($request);
        $storeId = $filter->allowedStoreId($request->integer('store_id') ?: null);
        $stockScope = $filter->stockScope($storeId);

        $items = Item::query()
            ->where('is_stockable', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit']);

        $itemIds = $items->modelKeys();
        $dayBefore = Carbon::parse($from)->subDay()->toDateString();

        $opening = Item::currentStockByItem($itemIds, $stockScope, $dayBefore);
        $closing = Item::currentStockByItem($itemIds, $stockScope, $to);
        $movement = $this->movementTotals($itemIds, $from, $to, $stockScope);
        $values = BranchStock::valuationRows($filter, $to, $storeId, ['include_empty' => true])->keyBy('item_id');

        // BR19: goods between branches on the last day of the window. Only
        // read for a multi-branch company, and the columns only exist when
        // something is in transit, so every other payload is unchanged.
        $inTransit = $filter->isActive() ? StockInTransit::byItem($stockScope, $to) : [];
        $transitBuckets = $this->transitBuckets($inTransit);

        $rows = $items
            ->map(function (Item $item) use ($opening, $closing, $movement, $values, $inTransit, $transitBuckets) {
                $itemOpening = $opening[$item->getKey()] ?? Quantity::zero();
                $itemClosing = $closing[$item->getKey()] ?? Quantity::zero();
                $itemMovement = $movement[$item->getKey()] ?? ['in' => Quantity::zero(), 'out' => Quantity::zero()];
                $valuation = $values->get($item->getKey());

                return [
                    'itemId' => $item->getKey(),
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'opening' => $itemOpening->toString(),
                    'qtyIn' => $itemMovement['in']->toString(),
                    'qtyOut' => $itemMovement['out']->toString(),
                    'closing' => $itemClosing->toString(),
                    'avgCost' => $valuation['average_cost'] ?? Quantity::zero()->toString(),
                    'valuation' => ($valuation['value'] ?? Money::zero())->toString(),
                    ...$this->transitFigures($inTransit[$item->getKey()] ?? [], $transitBuckets),
                ];
            })
            // An item that neither held nor moved anything in the window is
            // noise on a 5,000-item catalog; exact zero checks only. Goods
            // in transit keep their item listed.
            ->reject(fn (array $row) => Quantity::of($row['opening'])->isZero()
                && Quantity::of($row['closing'])->isZero()
                && Quantity::of($row['qtyIn'])->isZero()
                && Quantity::of($row['qtyOut'])->isZero()
                && ! isset($inTransit[$row['itemId']]))
            ->values();

        return Inertia::render('Tenant/Reports/StockSummary', [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'grandTotalValuation' => Money::sum($rows->map(fn (array $row) => Money::of($row['valuation'])))->toString(),
            'stores' => BranchStock::storeOptions($filter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'storeId' => $storeId,
            'branchReport' => $filter->payload(),
            'inTransit' => $transitBuckets === [] ? null : [
                'buckets' => $transitBuckets,
                'totals' => $this->transitTotals($inTransit, $transitBuckets),
            ],
        ]);
    }

    /**
     * The in-transit buckets that have anything in them, in display order
     * (StockInTransit::WITHIN, OUTGOING, INCOMING).
     *
     * @param  array<int, array<string, array{quantity: Quantity, value: Money}>>  $inTransit
     * @return list<string>
     */
    private function transitBuckets(array $inTransit): array
    {
        return array_values(array_filter(
            [StockInTransit::WITHIN, StockInTransit::OUTGOING, StockInTransit::INCOMING],
            fn (string $bucket): bool => array_filter($inTransit, fn (array $buckets): bool => isset($buckets[$bucket])) !== [],
        ));
    }

    /**
     * A row's in-transit figures, one `transit` entry per shown bucket
     * (zero where the item has none). Nothing at all when no bucket is shown,
     * so the row keeps its exact shape.
     *
     * @param  array<string, array{quantity: Quantity, value: Money}>  $itemTransit
     * @param  list<string>  $buckets
     * @return array{transit?: array<string, array{quantity: string, value: string}>}
     */
    private function transitFigures(array $itemTransit, array $buckets): array
    {
        if ($buckets === []) {
            return [];
        }

        $figures = [];

        foreach ($buckets as $bucket) {
            $figures[$bucket] = [
                'quantity' => ($itemTransit[$bucket]['quantity'] ?? Quantity::zero())->toString(),
                'value' => ($itemTransit[$bucket]['value'] ?? Money::zero())->toString(),
            ];
        }

        return ['transit' => $figures];
    }

    /**
     * Value per shown bucket over every item (quantities are in different
     * units and are not totalled).
     *
     * @param  array<int, array<string, array{quantity: Quantity, value: Money}>>  $inTransit
     * @param  list<string>  $buckets
     * @return array<string, string>
     */
    private function transitTotals(array $inTransit, array $buckets): array
    {
        $totals = [];

        foreach ($buckets as $bucket) {
            $totals[$bucket] = Money::sum(array_map(
                fn (array $itemBuckets): Money => $itemBuckets[$bucket]['value'] ?? Money::zero(),
                $inTransit,
            ))->toString();
        }

        return $totals;
    }

    /**
     * Quantity in and quantity out per item within the window, summed in SQL
     * as scaled integers for the reason Item::currentStockByItem() documents.
     *
     * $storeId is one store, the stores of the covered branches (BR15) or
     * null for all. For a set of stores a transfer counts only when it
     * crosses the set's edge (BranchStock::onlyCrossingTransfers()): moving
     * goods between two stores of the same branch is no more stock in or out
     * of that branch than it is of the company.
     *
     * @param  array<int, int|string>  $itemIds
     * @param  int|list<int>|null  $storeId
     * @return array<int, array{in: Quantity, out: Quantity}>
     */
    private function movementTotals(array $itemIds, string $from, string $to, int|array|null $storeId): array
    {
        $ids = array_values(array_unique(array_map('intval', $itemIds)));

        if ($ids === []) {
            return [];
        }

        $inTypes = $this->movementTypes(direction: 1, storeScoped: $storeId !== null);
        $outTypes = $this->movementTypes(direction: -1, storeScoped: $storeId !== null);

        if ($inTypes === [] && $outTypes === []) {
            return [];
        }

        $cast = (new ItemStockMovement)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';
        $scaled = "CAST(ROUND(quantity * 10000) AS {$cast})";
        $inPlaceholders = implode(', ', array_fill(0, max(count($inTypes), 1), '?'));
        $outPlaceholders = implode(', ', array_fill(0, max(count($outTypes), 1), '?'));

        $rows = ItemStockMovement::query()
            ->selectRaw(
                "item_id,
                 SUM(CASE WHEN movement_type IN ({$inPlaceholders}) THEN {$scaled} ELSE 0 END) as in_scaled,
                 SUM(CASE WHEN movement_type IN ({$outPlaceholders}) THEN {$scaled} ELSE 0 END) as out_scaled",
                [...($inTypes ?: ['']), ...($outTypes ?: [''])],
            )
            ->whereIn('item_id', $ids)
            ->where('cancelled', false)
            ->whereIn('movement_type', [...$inTypes, ...$outTypes])
            ->when(is_int($storeId), fn (Builder $query) => $query->where('store_id', $storeId))
            ->when(is_array($storeId), fn (Builder $query) => BranchStock::onlyCrossingTransfers($query->whereIn('store_id', $storeId), $storeId))
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->groupBy('item_id')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->item_id] = [
                'in' => $this->unscale((int) $row->in_scaled),
                'out' => $this->unscale((int) $row->out_scaled),
            ];
        }

        return $totals;
    }

    /**
     * @return list<string>
     */
    private function movementTypes(int $direction, bool $storeScoped): array
    {
        return array_values(array_map(
            fn (StockMovementType $type) => $type->value,
            array_filter(
                StockMovementType::cases(),
                fn (StockMovementType $type) => $type->direction() === $direction
                    && ($storeScoped || ! in_array($type, [StockMovementType::TransferIn, StockMovementType::TransferOut], true)),
            ),
        ));
    }

    private function unscale(int $scaled): Quantity
    {
        return Quantity::of(BigDecimal::of($scaled)->dividedBy(10000, 4, RoundingMode::Unnecessary));
    }
}
