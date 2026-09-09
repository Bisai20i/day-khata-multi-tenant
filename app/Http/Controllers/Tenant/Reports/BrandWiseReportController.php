<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brand-wise stock rollup - the multi-tenant equivalent of legacy
 * day_khata's Companywisestock/singleCompanywisestock
 * (reportsController.php), grouped by Brand (Item.brand_id) instead of
 * ItemCategory. Structurally identical to
 * CategoryWiseReportController::stockByCategory() - the exact same
 * weighted-average-cost, as-of-cutoff algorithm - just flat: a Brand has no
 * "subcategory" nested beneath it the way an ItemCategory does, so there's
 * no second nesting level here. Only the stock report is built (legacy's
 * Company/brand concept was never wired into a sales/purchase-by-brand
 * report to begin with - see the audit note in BrandController's docblock).
 */
class BrandWiseReportController extends Controller
{
    /**
     * Every Brand is always represented, even with zero stock, so a brand
     * with no stockable items still shows as a zero row rather than
     * silently vanishing. A trailing "Unbranded" row covers items with no
     * brand_id, but only when they actually carry nonzero stock/valuation -
     * matching buildCategoryStockRows()'s "Uncategorized" convention.
     */
    public function stockByBrand(Request $request): Response
    {
        $asOf = Carbon::parse($request->string('as_of')->toString() ?: now()->toDateString())->endOfDay();
        $storeId = $request->integer('store_id') ?: null;

        $brands = Brand::query()->orderBy('name')->get();
        $aggregates = $this->brandAggregatesFromStockMovements($asOf, $storeId);

        ['rows' => $rows, 'grandTotal' => $grandTotal] = $this->buildBrandStockRows($brands, $aggregates);

        return Inertia::render('Tenant/Reports/StockByBrand', [
            'asOf' => $asOf->toDateString(),
            'rows' => $rows,
            'grandTotalValuation' => $grandTotal['valuation'],
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'storeId' => $storeId,
        ]);
    }

    /**
     * Per-item weighted-average valuation (same algorithm as
     * CategoryWiseReportController::categoryAggregatesFromStockMovements())
     * summed into brand buckets.
     *
     * @return array<string, array{quantity: float, valuation: float}> keyed by brand_id, empty string in place of the brand_id when the item has none
     */
    private function brandAggregatesFromStockMovements(Carbon $asOf, ?int $storeId): array
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

            $key = (string) $item->brand_id;
            $aggregates[$key] ??= ['quantity' => 0.0, 'valuation' => 0.0];
            $aggregates[$key]['quantity'] += $closing;
            $aggregates[$key]['valuation'] += $valuation;
        });

        return $aggregates;
    }

    /**
     * Exact mirror of buildCategoryStockRows()'s {quantity, valuation}
     * shape and "only show the leftover bucket when it's nonzero" rule,
     * minus the subcategory nesting level - a Brand has none.
     *
     * @param  Collection<int, Brand>  $brands
     * @param  array<string, array{quantity: float, valuation: float}>  $aggregates
     * @return array{rows: array<int, array<string, mixed>>, grandTotal: array{valuation: float}}
     */
    private function buildBrandStockRows(Collection $brands, array $aggregates): array
    {
        $rows = [];
        $grandValuation = 0.0;

        foreach ($brands as $brand) {
            $aggregate = $aggregates[(string) $brand->id] ?? ['quantity' => 0.0, 'valuation' => 0.0];

            $rows[] = [
                'brandId' => $brand->id,
                'brandName' => $brand->name,
                'quantity' => round($aggregate['quantity'], 4),
                'valuation' => round($aggregate['valuation'], 2),
                'avgCost' => $this->weightedAverage($aggregate['valuation'], $aggregate['quantity']),
            ];

            $grandValuation += $aggregate['valuation'];
        }

        $unassigned = $aggregates[''] ?? ['quantity' => 0.0, 'valuation' => 0.0];

        if (abs($unassigned['quantity']) > 0.00001 || abs($unassigned['valuation']) > 0.001) {
            $rows[] = [
                'brandId' => null,
                'brandName' => 'Unbranded',
                'quantity' => round($unassigned['quantity'], 4),
                'valuation' => round($unassigned['valuation'], 2),
                'avgCost' => $this->weightedAverage($unassigned['valuation'], $unassigned['quantity']),
            ];

            $grandValuation += $unassigned['valuation'];
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
}
