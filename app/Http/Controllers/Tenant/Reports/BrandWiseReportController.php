<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Exports\StockByBrandExport;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Item;
use App\Models\Store;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        $filter = BranchFilter::fromRequest($request);
        $report = $this->stockByBrandReport($request, $filter);

        return Inertia::render('Tenant/Reports/StockByBrand', [
            'asOf' => $report['asOf'],
            'rows' => $report['rows'],
            'grandTotal' => $report['grandTotal'],
            'grandTotalValuation' => $report['grandTotal']['value'],
            'stores' => BranchStock::storeOptions($filter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'storeId' => $report['storeId'],
            'branchReport' => $filter->payload(),
        ]);
    }

    /**
     * Same rows and grand total as stockByBrand(), streamed as a real .xlsx.
     */
    public function stockByBrandExport(Request $request): BinaryFileResponse
    {
        $filter = BranchFilter::fromRequest($request);
        $report = $this->stockByBrandReport($request, $filter);

        return Excel::download(
            new StockByBrandExport(
                $report['rows'],
                $report['grandTotal'],
                $report['asOf'],
                $filter->isActive() ? $filter->label() : null,
                $report['storeId'] !== null ? Store::query()->whereKey($report['storeId'])->value('name') : null,
            ),
            "stock-by-brand-as-of-{$report['asOf']}.xlsx",
        );
    }

    /**
     * The items behind one Stock by Brand row (legacy's
     * singleCompanywisestock). `brand_id` is a brand id or `none` for the
     * "Unbranded" row. Rows are StockCosting's own valuation rows (C10), so
     * the total equals the parent row's value for the same
     * as-of/store/branch query. Only the value is totalled; quantities are
     * per item in its own unit.
     */
    public function stockByBrandItems(Request $request): Response
    {
        $request->validate([
            'brand_id' => ['required', 'regex:/^(none|[1-9][0-9]*)$/'],
        ]);

        $filter = BranchFilter::fromRequest($request);
        ['asOf' => $asOf, 'storeId' => $storeId] = $this->resolveStockQuery($request, $filter);

        $brandParam = $request->string('brand_id')->toString();
        $brand = $brandParam === 'none' ? null : Brand::query()->findOrFail((int) $brandParam);

        $rows = BranchStock::valuationRows($filter, $asOf, $storeId, $brand !== null ? ['brand_id' => $brand->id] : []);

        if ($brand === null) {
            // StockCosting's brand_id filter cannot select "no brand", so the
            // Unbranded list is narrowed here by an item lookup instead.
            $unbrandedItemIds = Item::query()
                ->where('is_stockable', true)
                ->whereNull('brand_id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->flip();

            $rows = $rows->filter(fn (array $row): bool => $unbrandedItemIds->has((int) $row['item_id']))->values();
        }

        return Inertia::render('Tenant/Reports/StockByBrandItems', [
            'asOf' => $asOf,
            'storeId' => $storeId,
            'storeName' => $storeId !== null ? Store::query()->whereKey($storeId)->value('name') : null,
            'brandId' => $brand?->id,
            'groupName' => $brand?->name ?? 'Unbranded',
            'rows' => $rows->map(fn (array $row): array => [
                'itemId' => $row['item_id'],
                'itemName' => $row['name'],
                'unit' => (string) ($row['unit'] ?? ''),
                'quantity' => $row['quantity']->toString(),
                'averageCost' => $row['average_cost'],
                'value' => $row['value']->toString(),
            ])->values()->all(),
            'totalValue' => Money::sum($rows->pluck('value'))->toString(),
            'branchReport' => $filter->payload(),
        ]);
    }

    /**
     * The one source of Stock by Brand's rows and grand total, shared by the
     * page and its Excel export so both always agree.
     *
     * @return array{asOf: string, storeId: int|null, rows: array<int, array<string, mixed>>, grandTotal: array{value: string, quantities: array<int, array{unit: string, quantity: string}>}}
     */
    private function stockByBrandReport(Request $request, BranchFilter $filter): array
    {
        ['asOf' => $asOf, 'storeId' => $storeId] = $this->resolveStockQuery($request, $filter);

        $brands = Brand::query()->orderBy('name')->get();
        $aggregates = $this->brandAggregatesFromStock($asOf, $storeId, $filter);

        ['rows' => $rows, 'grandTotal' => $grandTotal] = $this->buildBrandStockRows($brands, $aggregates);

        return [
            'asOf' => $asOf,
            'storeId' => $storeId,
            'rows' => $rows,
            'grandTotal' => $grandTotal,
        ];
    }

    /**
     * Narrowed to the covered branches' stores by BranchStock (BR15); one
     * store or the whole company exactly as before otherwise.
     *
     * @return array<string, array{value: Money, quantities: array<string, Quantity>}> keyed by brand_id, empty string for items with none
     */
    private function brandAggregatesFromStock(string $asOf, ?int $storeId, BranchFilter $filter): array
    {
        $items = Item::query()
            ->where('is_stockable', true)
            ->get(['id', 'brand_id', 'unit'])
            ->keyBy('id');

        $aggregates = [];

        foreach (BranchStock::valuationRows($filter, $asOf, $storeId) as $row) {
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

    /**
     * Strict as-of/store validation for the report, its drill-down and its
     * export: a malformed date is a validation error rather than a
     * Carbon::parse() 500, and the download name only ever holds a clean
     * Y-m-d. A store outside the chosen branches is dropped (BR15).
     *
     * @return array{asOf: string, storeId: int|null}
     */
    private function resolveStockQuery(Request $request, BranchFilter $filter): array
    {
        $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ]);

        $asOf = $request->string('as_of')->toString();

        return [
            'asOf' => $asOf !== '' ? $asOf : Carbon::now()->toDateString(),
            'storeId' => $filter->allowedStoreId($request->integer('store_id') ?: null),
        ];
    }
}
