<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\StockMovementType;
use App\Exports\StockAgeingExport;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Store;
use App\Support\AsOfDate;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use App\Support\Reports\StockItemFilters;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Stock (item) ageing: how long the stock on hand has been sitting, bucketed
 * 0-30 / 31-60 / 61-90 / 90+ days as of one date.
 *
 * Age is FIFO-derived: the on-hand quantity (the same figure the Stock
 * Valuation report shows) is allocated NEWEST-first against the item's
 * inbound movement rows, because the oldest goods are assumed sold first, so
 * what is left on the shelf is the most recent receipts. Each lot's age is
 * `as_of - movement date`.
 *
 * Value is NOT re-costed per lot. The item's value comes from
 * StockCosting::valuationRows() (weighted average, CONTRACTS C10) and is
 * split across the age buckets in proportion to quantity with
 * Money::allocate() (largest remainder), so the grand total reconciles with
 * the Stock Valuation report to the paisa for the same date and store.
 *
 * Quantity that no inbound row explains (negative stock, or a remainder with
 * no recorded receipt) goes to the "Unaged" column so a row always sums to
 * its quantity and value; it is never silently dropped.
 *
 * Transfers: in the all-stores view TransferIn rows are ignored as lots (the
 * goods are already counted by their original receipt and the TransferOut
 * leg nets them off); with a store selected a transfer is a real receipt for
 * that store and counts as a lot.
 *
 * Non-stockable items (services, capital items) never carry stock and are
 * excluded; the page says so.
 *
 * Branches (plan 12, BR15): `branch` / `branches[]` age only the covered
 * branches' stores (values from BranchStock::valuationRows()); a transfer
 * from a store outside them is a lot, one between their own stores is not.
 * A `store_id` outside them is dropped. Not multi-branch: unchanged, plus
 * `branchReport: null`.
 *
 * Filters (legacy group / sub-group / brand / item): StockItemFilters narrows
 * the items before ageing. `oldestDays` is the age of the oldest receipt still
 * on hand for the item (null when no dated lot is on hand). index() and
 * export() share report() so the download always matches the screen.
 */
class StockAgeingReportController extends Controller
{
    private const BUCKETS = ['d0_30', 'd31_60', 'd61_90', 'd90_plus'];

    /** Item ids per inbound-lot query, under SQLite's bound-parameter limit. */
    private const ITEM_CHUNK = 500;

    public function index(Request $request): Response
    {
        $report = $this->report($request);

        return Inertia::render('Tenant/Reports/StockAgeing', [
            ...$report['page'],
            ...StockItemFilters::options(),
            'categoryId' => $report['filters']['item_category_id'] ?? null,
            'subcategoryId' => $report['filters']['item_subcategory_id'] ?? null,
            'brandId' => $report['filters']['brand_id'] ?? null,
            'search' => $report['filters']['search'] ?? '',
            'stores' => BranchStock::storeOptions($report['branchFilter'], Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'branchReport' => $report['branchFilter']->payload(),
        ]);
    }

    /**
     * Same rows and totals as index(), streamed as a real .xlsx.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $report = $this->report($request);
        $branchFilter = $report['branchFilter'];

        return Excel::download(
            new StockAgeingExport(
                $report['page']['rows']->all(),
                $report['page']['totals'],
                $report['page']['asOf'],
                $branchFilter->isActive() ? $branchFilter->label() : null,
            ),
            "stock-ageing-{$report['page']['asOf']}.xlsx",
        );
    }

    /**
     * @return array{page: array<string, mixed>, filters: array<string, int|string>, branchFilter: BranchFilter}
     */
    private function report(Request $request): array
    {
        $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            ...StockItemFilters::rules(),
        ]);

        $asOfDate = AsOfDate::resolveCarbon($request->input('as_of'));
        $asOf = $asOfDate->toDateString();
        $filter = BranchFilter::fromRequest($request);
        $filters = StockItemFilters::fromRequest($request);
        $storeId = $request->integer('store_id') ?: null;
        $storeId = $filter->allowedStoreId($storeId);
        $stockScope = $filter->stockScope($storeId);

        $valuation = BranchStock::valuationRows($filter, $asOf, $storeId, $filters);

        $positiveIds = $valuation
            ->filter(fn (array $row) => $row['quantity']->isPositive())
            ->pluck('item_id')
            ->all();

        $lots = $this->inboundLotsByItem($positiveIds, $asOf, $stockScope);
        $details = Item::query()
            ->whereIn('id', $valuation->pluck('item_id')->all())
            ->get(['id', 'barcode'])
            ->keyBy('id');

        $rows = $valuation->map(function (array $row) use ($lots, $details, $asOfDate): array {
            $quantity = $row['quantity'];
            $remaining = $quantity->isPositive() ? $quantity : Quantity::zero();
            $bucketQty = array_fill_keys(self::BUCKETS, Quantity::zero());
            $oldestDays = null;

            foreach ($lots[$row['item_id']] ?? [] as $lot) {
                if (! $remaining->isPositive()) {
                    break;
                }

                $take = $lot['quantity']->isGreaterThan($remaining) ? $remaining : $lot['quantity'];
                $days = (int) abs($lot['date']->copy()->startOfDay()->diffInDays($asOfDate->copy()->startOfDay()));
                $bucket = $this->bucketFor($days);
                $bucketQty[$bucket] = $bucketQty[$bucket]->plus($take);
                $remaining = $remaining->minus($take);
                $oldestDays = $oldestDays === null ? $days : max($oldestDays, $days);
            }

            // Whatever no lot explains: all of a negative quantity, or a
            // positive remainder with no recorded receipt.
            $unagedQty = $quantity->minus(Quantity::sum(array_values($bucketQty)));
            $weights = [...array_values($bucketQty), $unagedQty->isPositive() ? $unagedQty : Quantity::zero()];
            $values = $this->splitValue($row['value'], $weights);

            $out = [
                'itemId' => $row['item_id'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'barcode' => $details[$row['item_id']]->barcode ?? null,
                'hsCode' => $row['hs_code'],
                'quantity' => $quantity->toString(),
                'value' => $row['value']->toString(),
                'oldestDays' => $oldestDays,
                'unagedQuantity' => $unagedQty->toString(),
                'unagedValue' => $values[4]->toString(),
            ];

            foreach (self::BUCKETS as $index => $bucket) {
                $out[$bucket.'Qty'] = $bucketQty[$bucket]->toString();
                $out[$bucket.'Value'] = $values[$index]->toString();
            }

            return $out;
        })
            ->sort(fn (array $a, array $b) => Money::of($b['value'])->compareTo(Money::of($a['value'])))
            ->values();

        $totals = [];

        foreach (['quantity', 'unagedQuantity', ...array_map(fn (string $b) => $b.'Qty', self::BUCKETS)] as $key) {
            $totals[$key] = Quantity::sum($rows->map(fn (array $r) => Quantity::of($r[$key])))->toString();
        }

        foreach (['value', 'unagedValue', ...array_map(fn (string $b) => $b.'Value', self::BUCKETS)] as $key) {
            $totals[$key] = Money::sum($rows->map(fn (array $r) => Money::of($r[$key])))->toString();
        }

        // The branch view's and a filtered view's total is its own rows'
        // (same convention); otherwise the figure the report always showed.
        $valuationTotal = is_array($stockScope) || $filters !== []
            ? Money::sum($valuation->map(fn (array $row) => $row['value']))
            : StockCosting::totalClosingValue($asOf, $storeId);

        return [
            'filters' => $filters,
            'branchFilter' => $filter,
            'page' => [
                'asOf' => $asOf,
                'rows' => $rows,
                'totals' => $totals,
                'valuationTotal' => $valuationTotal->toString(),
                'negativeStockCount' => $rows->filter(fn (array $r) => Quantity::of($r['quantity'])->isNegative())->count(),
                'excludedNonStockCount' => Item::query()
                    ->where('is_stockable', false)
                    ->when(isset($filters['item_category_id']), fn (Builder $q) => $q->where('item_category_id', $filters['item_category_id']))
                    ->when(isset($filters['item_subcategory_id']), fn (Builder $q) => $q->where('item_subcategory_id', $filters['item_subcategory_id']))
                    ->when(isset($filters['brand_id']), fn (Builder $q) => $q->where('brand_id', $filters['brand_id']))
                    ->when(isset($filters['search']), fn (Builder $q) => $q->where('name', 'like', '%'.$filters['search'].'%'))
                    ->count(),
                'storeId' => $storeId,
            ],
        ];
    }

    private function bucketFor(int $days): string
    {
        return match (true) {
            $days <= 30 => 'd0_30',
            $days <= 60 => 'd31_60',
            $days <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }

    /**
     * Split a value across [4 bucket quantities, unaged quantity] by
     * quantity weight. All-zero weights (negative stock) put the whole value
     * on Unaged.
     *
     * @param  list<Quantity>  $weights
     * @return list<Money>
     */
    private function splitValue(Money $value, array $weights): array
    {
        $zero = Money::zero();

        if ($value->isZero()) {
            return array_fill(0, count($weights), $zero);
        }

        if (! Quantity::sum($weights)->isPositive()) {
            return [$zero, $zero, $zero, $zero, $value];
        }

        return $value->allocate($weights);
    }

    /**
     * Inbound lots per item, newest first. $storeId is one store, the
     * covered branches' stores (BR15: a TransferIn is a lot only when it
     * comes from outside the set, BranchStock::onlyCrossingTransfers()) or
     * null for all stores.
     *
     * @param  list<int>  $itemIds
     * @param  int|list<int>|null  $storeId
     * @return array<int, list<array{date: CarbonInterface, quantity: Quantity}>>
     */
    private function inboundLotsByItem(array $itemIds, string $asOf, int|array|null $storeId): array
    {
        if ($itemIds === []) {
            return [];
        }

        $inTypes = collect(StockMovementType::cases())
            ->filter(fn (StockMovementType $type) => $type->direction() === 1)
            ->when($storeId === null, fn (Collection $types) => $types->reject(fn (StockMovementType $type) => $type === StockMovementType::TransferIn))
            ->map(fn (StockMovementType $type) => $type->value)
            ->values()
            ->all();

        $lots = [];

        // Chunked by item so the whereIn stays under SQLite's bound-parameter
        // limit and each query hydrates only one chunk's models. Each item's
        // lots all fall in one chunk, so its newest-first order holds.
        foreach (array_chunk($itemIds, self::ITEM_CHUNK) as $chunk) {
            ItemStockMovement::query()
                ->whereIn('item_id', $chunk)
                ->whereIn('movement_type', $inTypes)
                ->where('cancelled', false)
                ->whereDate('date', '<=', $asOf)
                ->when(is_int($storeId), fn ($query) => $query->where('store_id', $storeId))
                ->when(is_array($storeId), fn ($query) => BranchStock::onlyCrossingTransfers($query->whereIn('store_id', $storeId), $storeId))
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get(['id', 'item_id', 'quantity', 'date'])
                ->each(function (ItemStockMovement $movement) use (&$lots): void {
                    $lots[$movement->item_id][] = ['date' => $movement->date, 'quantity' => Quantity::of($movement->quantity)];
                });
        }

        return $lots;
    }
}
