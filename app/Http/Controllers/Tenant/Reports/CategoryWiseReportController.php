<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemStockMovement;
use App\Models\PurchaseLine;
use App\Models\SaleLine;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Category-wise rollups over Sale/Purchase/stock data - siblings of the
 * per-item Sales/Purchase registers and the per-item Stock Summary, but
 * grouped by ItemCategory (with an ItemSubcategory breakdown nested inside
 * each category row) instead of listed per invoice/item. Read-only, no new
 * tables.
 */
class CategoryWiseReportController extends Controller
{
    /**
     * Every ItemCategory (and its subcategories) is always represented,
     * even with zero activity, so a category with no sales in the range
     * still shows as a zero row rather than silently vanishing.
     */
    public function salesByCategory(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        $categories = ItemCategory::query()->with('subcategories')->orderBy('name')->get();
        $aggregates = $this->categoryAggregatesFromSaleLines($from, $to, $storeId);

        ['rows' => $rows, 'grandTotal' => $grandTotal] = $this->buildCategoryValueRows($categories, $aggregates);

        return Inertia::render('Tenant/Reports/SalesByCategory', [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'grandTotal' => $grandTotal,
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'storeId' => $storeId,
        ]);
    }

    /**
     * Exact mirror of salesByCategory() using Purchase/PurchaseLine.
     */
    public function purchaseByCategory(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        $categories = ItemCategory::query()->with('subcategories')->orderBy('name')->get();
        $aggregates = $this->categoryAggregatesFromPurchaseLines($from, $to, $storeId);

        ['rows' => $rows, 'grandTotal' => $grandTotal] = $this->buildCategoryValueRows($categories, $aggregates);

        return Inertia::render('Tenant/Reports/PurchaseByCategory', [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'grandTotal' => $grandTotal,
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'storeId' => $storeId,
        ]);
    }

    /**
     * On-hand quantity and valuation as of a single date (not a from/to
     * range), grouped by category/subcategory. Reuses the exact
     * weighted-average-cost and "closing quantity as of a date" algorithm
     * from InventoryReportController::stockSummary() - see that method's
     * docblock - just summed per category instead of listed per item.
     */
    public function stockByCategory(Request $request): Response
    {
        $asOf = Carbon::parse($request->string('as_of')->toString() ?: now()->toDateString())->endOfDay();
        $storeId = $request->integer('store_id') ?: null;

        $categories = ItemCategory::query()->with('subcategories')->orderBy('name')->get();
        $aggregates = $this->categoryAggregatesFromStockMovements($asOf, $storeId);

        ['rows' => $rows, 'grandTotal' => $grandTotal] = $this->buildCategoryStockRows($categories, $aggregates);

        return Inertia::render('Tenant/Reports/StockByCategory', [
            'asOf' => $asOf->toDateString(),
            'rows' => $rows,
            'grandTotalValuation' => $grandTotal['valuation'],
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'storeId' => $storeId,
        ]);
    }

    /**
     * Posted-only sale line totals grouped by category/subcategory within
     * the date range.
     *
     * @return array<string, array{quantity: float, value: float}> keyed "{categoryId}:{subcategoryId}" (empty string in place of the subcategoryId when the item has none)
     */
    private function categoryAggregatesFromSaleLines(string $from, string $to, ?int $storeId): array
    {
        return SaleLine::query()
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->join('items', 'items.id', '=', 'sale_lines.item_id')
            ->where('sales.status', 'posted')
            ->whereBetween('sales.date', [$from, $to])
            ->when($storeId !== null, fn ($query) => $query->where('sales.store_id', $storeId))
            ->get([
                'items.item_category_id as category_id',
                'items.item_subcategory_id as subcategory_id',
                'sale_lines.quantity as quantity',
                'sale_lines.line_total as line_total',
            ])
            ->groupBy(fn ($row) => "{$row->category_id}:{$row->subcategory_id}")
            ->map(fn (Collection $rows) => [
                'quantity' => (float) $rows->sum('quantity'),
                'value' => (float) $rows->sum('line_total'),
            ])
            ->all();
    }

    /**
     * Exact mirror of categoryAggregatesFromSaleLines() using
     * Purchase/PurchaseLine.
     *
     * @return array<string, array{quantity: float, value: float}> keyed "{categoryId}:{subcategoryId}" (empty string in place of the subcategoryId when the item has none)
     */
    private function categoryAggregatesFromPurchaseLines(string $from, string $to, ?int $storeId): array
    {
        return PurchaseLine::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->join('items', 'items.id', '=', 'purchase_lines.item_id')
            ->where('purchases.status', 'posted')
            ->whereBetween('purchases.date', [$from, $to])
            ->when($storeId !== null, fn ($query) => $query->where('purchases.store_id', $storeId))
            ->get([
                'items.item_category_id as category_id',
                'items.item_subcategory_id as subcategory_id',
                'purchase_lines.quantity as quantity',
                'purchase_lines.line_total as line_total',
            ])
            ->groupBy(fn ($row) => "{$row->category_id}:{$row->subcategory_id}")
            ->map(fn (Collection $rows) => [
                'quantity' => (float) $rows->sum('quantity'),
                'value' => (float) $rows->sum('line_total'),
            ])
            ->all();
    }

    /**
     * Per-item weighted-average valuation (same algorithm as
     * InventoryReportController::stockSummary(), collapsed to a single
     * as-of cutoff instead of a from/to range) summed into category/
     * subcategory buckets.
     *
     * @return array<string, array{quantity: float, valuation: float}> keyed "{categoryId}:{subcategoryId}" (empty string in place of the subcategoryId when the item has none)
     */
    private function categoryAggregatesFromStockMovements(Carbon $asOf, ?int $storeId): array
    {
        $aggregates = [];

        Item::query()->where('is_stockable', true)->orderBy('name')->each(function (Item $item) use ($asOf, $storeId, &$aggregates): void {
            $movements = $item->stockMovements()->where('cancelled', false)
                ->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId))
                ->get();

            $closing = (float) $movements
                ->filter(fn (ItemStockMovement $movement) => $movement->date->lte($asOf))
                ->sum(fn (ItemStockMovement $movement) => (float) $movement->quantity * $movement->movement_type->direction());

            $costBasis = $movements->filter(
                fn (ItemStockMovement $movement) => $movement->movement_type->direction() === 1
                    && $movement->date->lte($asOf)
                    && $movement->unit_cost_rate !== null,
            );

            $costQuantity = (float) $costBasis->sum(fn (ItemStockMovement $movement) => (float) $movement->quantity);
            $costValue = (float) $costBasis->sum(fn (ItemStockMovement $movement) => (float) $movement->quantity * (float) $movement->unit_cost_rate);
            $avgCost = $costQuantity > 0 ? round($costValue / $costQuantity, 4) : 0.0;
            $valuation = round($avgCost * $closing, 2);

            $key = "{$item->item_category_id}:{$item->item_subcategory_id}";
            $aggregates[$key] ??= ['quantity' => 0.0, 'valuation' => 0.0];
            $aggregates[$key]['quantity'] += $closing;
            $aggregates[$key]['valuation'] += $valuation;
        });

        return $aggregates;
    }

    /**
     * Nests every ItemCategory/ItemSubcategory into rows carrying
     * {quantity, value}, defaulting to zero wherever the aggregates map has
     * no entry for that category/subcategory. Items without a subcategory
     * roll into the category total and surface as an "Uncategorized" row
     * only when they actually contributed nonzero activity, so the grand
     * total always equals the sum of every shown row.
     *
     * @param  Collection<int, ItemCategory>  $categories
     * @param  array<string, array{quantity: float, value: float}>  $aggregates
     * @return array{rows: array<int, array<string, mixed>>, grandTotal: array{quantity: float, value: float}}
     */
    private function buildCategoryValueRows(Collection $categories, array $aggregates): array
    {
        $rows = [];
        $grandQuantity = 0.0;
        $grandValue = 0.0;

        foreach ($categories as $category) {
            $categoryQuantity = 0.0;
            $categoryValue = 0.0;
            $subcategoryRows = [];

            foreach ($category->subcategories as $subcategory) {
                $aggregate = $aggregates["{$category->id}:{$subcategory->id}"] ?? ['quantity' => 0.0, 'value' => 0.0];

                $categoryQuantity += $aggregate['quantity'];
                $categoryValue += $aggregate['value'];

                $subcategoryRows[] = [
                    'subcategoryId' => $subcategory->id,
                    'subcategoryName' => $subcategory->name,
                    'quantity' => round($aggregate['quantity'], 4),
                    'value' => round($aggregate['value'], 2),
                ];
            }

            $unassigned = $aggregates["{$category->id}:"] ?? ['quantity' => 0.0, 'value' => 0.0];

            if (abs($unassigned['quantity']) > 0.00001 || abs($unassigned['value']) > 0.001) {
                $categoryQuantity += $unassigned['quantity'];
                $categoryValue += $unassigned['value'];

                $subcategoryRows[] = [
                    'subcategoryId' => null,
                    'subcategoryName' => 'Uncategorized',
                    'quantity' => round($unassigned['quantity'], 4),
                    'value' => round($unassigned['value'], 2),
                ];
            }

            $rows[] = [
                'categoryId' => $category->id,
                'categoryName' => $category->name,
                'quantity' => round($categoryQuantity, 4),
                'value' => round($categoryValue, 2),
                'subcategories' => $subcategoryRows,
            ];

            $grandQuantity += $categoryQuantity;
            $grandValue += $categoryValue;
        }

        return [
            'rows' => $rows,
            'grandTotal' => [
                'quantity' => round($grandQuantity, 4),
                'value' => round($grandValue, 2),
            ],
        ];
    }

    /**
     * Exact mirror of buildCategoryValueRows() for the stock report's
     * {quantity, valuation} shape, with a derived (never independently
     * summed) weighted-average cost per row for display.
     *
     * @param  Collection<int, ItemCategory>  $categories
     * @param  array<string, array{quantity: float, valuation: float}>  $aggregates
     * @return array{rows: array<int, array<string, mixed>>, grandTotal: array{valuation: float}}
     */
    private function buildCategoryStockRows(Collection $categories, array $aggregates): array
    {
        $rows = [];
        $grandValuation = 0.0;

        foreach ($categories as $category) {
            $categoryQuantity = 0.0;
            $categoryValuation = 0.0;
            $subcategoryRows = [];

            foreach ($category->subcategories as $subcategory) {
                $aggregate = $aggregates["{$category->id}:{$subcategory->id}"] ?? ['quantity' => 0.0, 'valuation' => 0.0];

                $categoryQuantity += $aggregate['quantity'];
                $categoryValuation += $aggregate['valuation'];

                $subcategoryRows[] = [
                    'subcategoryId' => $subcategory->id,
                    'subcategoryName' => $subcategory->name,
                    'quantity' => round($aggregate['quantity'], 4),
                    'valuation' => round($aggregate['valuation'], 2),
                    'avgCost' => $this->weightedAverage($aggregate['valuation'], $aggregate['quantity']),
                ];
            }

            $unassigned = $aggregates["{$category->id}:"] ?? ['quantity' => 0.0, 'valuation' => 0.0];

            if (abs($unassigned['quantity']) > 0.00001 || abs($unassigned['valuation']) > 0.001) {
                $categoryQuantity += $unassigned['quantity'];
                $categoryValuation += $unassigned['valuation'];

                $subcategoryRows[] = [
                    'subcategoryId' => null,
                    'subcategoryName' => 'Uncategorized',
                    'quantity' => round($unassigned['quantity'], 4),
                    'valuation' => round($unassigned['valuation'], 2),
                    'avgCost' => $this->weightedAverage($unassigned['valuation'], $unassigned['quantity']),
                ];
            }

            $rows[] = [
                'categoryId' => $category->id,
                'categoryName' => $category->name,
                'quantity' => round($categoryQuantity, 4),
                'valuation' => round($categoryValuation, 2),
                'avgCost' => $this->weightedAverage($categoryValuation, $categoryQuantity),
                'subcategories' => $subcategoryRows,
            ];

            $grandValuation += $categoryValuation;
        }

        return [
            'rows' => $rows,
            'grandTotal' => [
                'valuation' => round($grandValuation, 2),
            ],
        ];
    }

    /**
     * Derived display-only average cost (valuation / quantity) - never
     * summed independently across rows, unlike quantity/valuation.
     */
    private function weightedAverage(float $valuation, float $quantity): float
    {
        return abs($quantity) > 0.00001 ? round($valuation / $quantity, 4) : 0.0;
    }

    /**
     * Defaults to the current open fiscal year's date range when no
     * explicit `from`/`to` query params are given, falling back to
     * month-to-date if no fiscal year exists yet. Identical logic to
     * SalesPurchaseReportController::resolveDateRange() - duplicated
     * rather than shared across controllers, matching this app's existing
     * per-controller-file convention (see mem.md gotcha #5).
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(Request $request): array
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        if ($from !== '' && $to !== '') {
            return [$from, $to];
        }

        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        if ($fiscalYear) {
            return [$fiscalYear->start_date->toDateString(), $fiscalYear->end_date->toDateString()];
        }

        return [now()->startOfMonth()->toDateString(), now()->toDateString()];
    }
}
