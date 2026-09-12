<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Item;
use App\Models\Store;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
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
 * CategoryWiseReportController::stockByCategory(), just flat: a Brand has no
 * "subcategory" nested beneath it the way an ItemCategory does. Only the
 * stock report is built (legacy's Company/brand concept was never wired
 * into a sales/purchase-by-brand report to begin with - see the audit note
 * in BrandController's docblock).
 *
 * Values are read through App\Support\Inventory\StockCosting (C10), the one
 * place stock is valued, and quantities are kept per base unit rather than
 * added across units - see CategoryWiseReportController's docblock for both.
 */
class BrandWiseReportController extends Controller
{
    /**
     * Every Brand is always represented, even with zero stock, so a brand
     * with no stockable items still shows as a zero row rather than silently
     * vanishing. A trailing "Unbranded" row covers items with no brand_id,
     * but only when they actually carry stock or value - matching the
     * "Uncategorized" convention on the category report.
     */
    public function stockByBrand(Request $request): Response
    {
        $asOf = $this->resolveAsOf($request);
        $storeId = $request->integer('store_id') ?: null;

        $brands = Brand::query()->orderBy('name')->get();
        $aggregates = $this->brandAggregatesFromStock($asOf, $storeId);

        ['rows' => $rows, 'grandTotal' => $grandTotal] = $this->buildBrandStockRows($brands, $aggregates);

        return Inertia::render('Tenant/Reports/StockByBrand', [
            'asOf' => $asOf,
            'rows' => $rows,
            'grandTotal' => $grandTotal,
            'grandTotalValuation' => $grandTotal['value'],
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'storeId' => $storeId,
        ]);
    }

    /**
     * @return array<string, array{value: Money, quantities: array<string, Quantity>}> keyed by brand_id, empty string for items with none
     */
    private function brandAggregatesFromStock(string $asOf, ?int $storeId): array
    {
        $items = Item::query()
            ->where('is_stockable', true)
            ->get(['id', 'brand_id', 'unit'])
            ->keyBy('id');

        $aggregates = [];

        foreach (StockCosting::valuationRows($asOf, $storeId) as $row) {
            $item = $items->get($row['item_id']);

            if ($item === null) {
                continue;
            }

            $key = (string) $item->brand_id;
            $unit = (string) ($item->unit ?? '');

            $aggregates[$key] ??= ['value' => Money::zero(), 'quantities' => []];
            $aggregates[$key]['quantities'][$unit] ??= Quantity::zero();

            $aggregates[$key]['value'] = $aggregates[$key]['value']->plus($row['value']);
            $aggregates[$key]['quantities'][$unit] = $aggregates[$key]['quantities'][$unit]->plus($row['quantity']);
        }

        return $aggregates;
    }

    /**
     * @param  Collection<int, Brand>  $brands
     * @param  array<string, array{value: Money, quantities: array<string, Quantity>}>  $aggregates
     * @return array{rows: array<int, array<string, mixed>>, grandTotal: array{value: string, quantities: array<int, array{unit: string, quantity: string}>}}
     */
    private function buildBrandStockRows(Collection $brands, array $aggregates): array
    {
        $rows = [];
        $grandValue = Money::zero();
        $grandQuantities = [];

        foreach ($brands as $brand) {
            $aggregate = $aggregates[(string) $brand->id] ?? null;

            $rows[] = [
                'brandId' => $brand->id,
                'brandName' => $brand->name,
                'value' => ($aggregate['value'] ?? Money::zero())->toString(),
                'quantities' => $this->presentQuantities($aggregate['quantities'] ?? []),
            ];

            if ($aggregate !== null) {
                $grandValue = $grandValue->plus($aggregate['value']);
                $grandQuantities = $this->mergeQuantities($grandQuantities, $aggregate['quantities']);
            }
        }

        $unbranded = $aggregates[''] ?? null;

        if ($unbranded !== null && ! $this->isEmptyAggregate($unbranded)) {
            $rows[] = [
                'brandId' => null,
                'brandName' => 'Unbranded',
                'value' => $unbranded['value']->toString(),
                'quantities' => $this->presentQuantities($unbranded['quantities']),
            ];

            $grandValue = $grandValue->plus($unbranded['value']);
            $grandQuantities = $this->mergeQuantities($grandQuantities, $unbranded['quantities']);
        }

        return [
            'rows' => $rows,
            'grandTotal' => [
                'value' => $grandValue->toString(),
                'quantities' => $this->presentQuantities($grandQuantities),
            ],
        ];
    }

    /**
     * @param  array<string, Quantity>  $into
     * @param  array<string, Quantity>  $from
     * @return array<string, Quantity>
     */
    private function mergeQuantities(array $into, array $from): array
    {
        foreach ($from as $unit => $quantity) {
            $into[$unit] = ($into[$unit] ?? Quantity::zero())->plus($quantity);
        }

        return $into;
    }

    /**
     * @param  array<string, Quantity>  $quantities
     * @return array<int, array{unit: string, quantity: string}>
     */
    private function presentQuantities(array $quantities): array
    {
        $presented = [];

        foreach ($quantities as $unit => $quantity) {
            if ($quantity->isZero()) {
                continue;
            }

            $presented[] = ['unit' => $unit, 'quantity' => $quantity->toString()];
        }

        usort($presented, fn (array $a, array $b) => strcasecmp($a['unit'], $b['unit']));

        return $presented;
    }

    /**
     * @param  array{value: Money, quantities: array<string, Quantity>}  $aggregate
     */
    private function isEmptyAggregate(array $aggregate): bool
    {
        if (! $aggregate['value']->isZero()) {
            return false;
        }

        foreach ($aggregate['quantities'] as $quantity) {
            if (! $quantity->isZero()) {
                return false;
            }
        }

        return true;
    }

    private function resolveAsOf(Request $request): string
    {
        $asOf = $request->string('as_of')->toString();

        return $asOf !== '' ? Carbon::parse($asOf)->toDateString() : Carbon::now()->toDateString();
    }
}
