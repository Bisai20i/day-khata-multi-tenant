<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PurchaseLine;
use App\Models\SaleLine;
use App\Models\Store;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Category-wise rollups over sales, purchases and stock - siblings of the
 * per-item registers and the Stock Summary, grouped by ItemCategory (with an
 * ItemSubcategory breakdown nested inside each category row).
 *
 * Two rules from the 2026-09-11 audit shape every figure here:
 *
 * - **Values come from stored decimals and StockCosting, never a float.**
 *   Stock value is App\Support\Inventory\StockCosting (C10), the one place
 *   stock is valued; the old per-category weighted average rounded the cost
 *   to 4 decimals before multiplying and counted transfer-in rows as fresh
 *   stock, so a category total disagreed with the Balance Sheet.
 * - **Quantities are never added across units.** Summing 12 Boxes and 30
 *   Kilograms into "42" is not a number anyone can use, so every quantity
 *   total is a per-base-unit breakdown: line quantities are converted to
 *   base units with the line's own `unit_conversion_factor` and grouped by
 *   the item's base unit.
 */
class CategoryWiseReportController extends Controller
{
    /**
     * Every ItemCategory (and its subcategories) is always represented, even
     * with zero activity, so a category with no sales in the range still
     * shows as a zero row rather than silently vanishing.
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
     * On-hand quantity and value as of a single date, grouped by
     * category/subcategory - StockCosting::valuationRows() summed into
     * buckets instead of listed per item.
     */
    public function stockByCategory(Request $request): Response
    {
        $asOf = $this->resolveAsOf($request);
        $storeId = $request->integer('store_id') ?: null;

        $categories = ItemCategory::query()->with('subcategories')->orderBy('name')->get();
        $aggregates = $this->categoryAggregatesFromStock($asOf, $storeId);

        ['rows' => $rows, 'grandTotal' => $grandTotal] = $this->buildCategoryValueRows($categories, $aggregates);

        return Inertia::render('Tenant/Reports/StockByCategory', [
            'asOf' => $asOf,
            'rows' => $rows,
            'grandTotal' => $grandTotal,
            'grandTotalValuation' => $grandTotal['value'],
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'storeId' => $storeId,
        ]);
    }

    /**
     * Posted-only sale line totals grouped by category/subcategory within
     * the date range, with quantities in base units keyed by unit.
     *
     * @return array<string, array{value: Money, quantities: array<string, Quantity>}> keyed "{categoryId}:{subcategoryId}"
     */
    private function categoryAggregatesFromSaleLines(string $from, string $to, ?int $storeId): array
    {
        $rows = SaleLine::query()
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->join('items', 'items.id', '=', 'sale_lines.item_id')
            ->where('sales.status', 'posted')
            ->whereDate('sales.date', '>=', $from)
            ->whereDate('sales.date', '<=', $to)
            ->when($storeId !== null, fn (Builder $query) => $query->where('sales.store_id', $storeId))
            ->get([
                'items.item_category_id as category_id',
                'items.item_subcategory_id as subcategory_id',
                'items.unit as unit',
                'sale_lines.quantity as quantity',
                'sale_lines.unit_conversion_factor as unit_conversion_factor',
                'sale_lines.line_total as line_total',
            ]);

        return $this->bucket($rows);
    }

    /**
     * Exact mirror of categoryAggregatesFromSaleLines() using
     * Purchase/PurchaseLine.
     *
     * @return array<string, array{value: Money, quantities: array<string, Quantity>}> keyed "{categoryId}:{subcategoryId}"
     */
    private function categoryAggregatesFromPurchaseLines(string $from, string $to, ?int $storeId): array
    {
        $rows = PurchaseLine::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->join('items', 'items.id', '=', 'purchase_lines.item_id')
            ->where('purchases.status', 'posted')
            ->whereDate('purchases.date', '>=', $from)
            ->whereDate('purchases.date', '<=', $to)
            ->when($storeId !== null, fn (Builder $query) => $query->where('purchases.store_id', $storeId))
            ->get([
                'items.item_category_id as category_id',
                'items.item_subcategory_id as subcategory_id',
                'items.unit as unit',
                'purchase_lines.quantity as quantity',
                'purchase_lines.unit_conversion_factor as unit_conversion_factor',
                'purchase_lines.line_total as line_total',
            ]);

        return $this->bucket($rows);
    }

    /**
     * Sums raw line rows into category/subcategory buckets. Quantities are
     * converted to base units (`quantity x unit_conversion_factor`, one
     * rounding at 4 decimals, exactly what the stock movement recorded) and
     * kept per unit so two different units never land in one number.
     *
     * @param  Collection<int, Model>  $rows
     * @return array<string, array{value: Money, quantities: array<string, Quantity>}>
     */
    private function bucket(Collection $rows): array
    {
        $aggregates = [];

        foreach ($rows as $row) {
            $key = "{$row->category_id}:{$row->subcategory_id}";
            $unit = (string) ($row->unit ?? '');

            $aggregates[$key] ??= ['value' => Money::zero(), 'quantities' => []];
            $aggregates[$key]['quantities'][$unit] ??= Quantity::zero();

            $aggregates[$key]['value'] = $aggregates[$key]['value']->plus(Money::of($row->line_total));
            $aggregates[$key]['quantities'][$unit] = $aggregates[$key]['quantities'][$unit]->plus(
                Quantity::round(
                    Quantity::of($row->quantity)->toBigDecimal()
                        ->multipliedBy(Quantity::of($row->unit_conversion_factor ?? '1')->toBigDecimal())
                )
            );
        }

        return $aggregates;
    }

    /**
     * Closing stock quantity and value per category/subcategory, read through
     * StockCosting (C10) so a category total can never disagree with the
     * Balance Sheet's Stock in Hand.
     *
     * @return array<string, array{value: Money, quantities: array<string, Quantity>}>
     */
    private function categoryAggregatesFromStock(string $asOf, ?int $storeId): array
    {
        $items = Item::query()
            ->where('is_stockable', true)
            ->get(['id', 'item_category_id', 'item_subcategory_id', 'unit'])
            ->keyBy('id');

        $aggregates = [];

        foreach (StockCosting::valuationRows($asOf, $storeId) as $row) {
            $item = $items->get($row['item_id']);

            if ($item === null) {
                continue;
            }

            $key = "{$item->item_category_id}:{$item->item_subcategory_id}";
            $unit = (string) ($item->unit ?? '');

            $aggregates[$key] ??= ['value' => Money::zero(), 'quantities' => []];
            $aggregates[$key]['quantities'][$unit] ??= Quantity::zero();

            $aggregates[$key]['value'] = $aggregates[$key]['value']->plus($row['value']);
            $aggregates[$key]['quantities'][$unit] = $aggregates[$key]['quantities'][$unit]->plus($row['quantity']);
        }

        return $aggregates;
    }

    /**
     * Nests every ItemCategory/ItemSubcategory into rows carrying a value and
     * a per-unit quantity breakdown, defaulting to zero wherever the
     * aggregates map has no entry. Items without a subcategory roll into the
     * category total and surface as an "Uncategorized" row only when they
     * actually contributed something, so the grand total always equals the
     * sum of every shown row.
     *
     * @param  Collection<int, ItemCategory>  $categories
     * @param  array<string, array{value: Money, quantities: array<string, Quantity>}>  $aggregates
     * @return array{rows: array<int, array<string, mixed>>, grandTotal: array{value: string, quantities: array<int, array{unit: string, quantity: string}>}}
     */
    private function buildCategoryValueRows(Collection $categories, array $aggregates): array
    {
        $rows = [];
        $grandValue = Money::zero();
        $grandQuantities = [];

        foreach ($categories as $category) {
            $categoryValue = Money::zero();
            $categoryQuantities = [];
            $subcategoryRows = [];

            foreach ($category->subcategories as $subcategory) {
                $aggregate = $aggregates["{$category->id}:{$subcategory->id}"] ?? null;

                $subcategoryRows[] = [
                    'subcategoryId' => $subcategory->id,
                    'subcategoryName' => $subcategory->name,
                    'value' => ($aggregate['value'] ?? Money::zero())->toString(),
                    'quantities' => $this->presentQuantities($aggregate['quantities'] ?? []),
                ];

                if ($aggregate !== null) {
                    $categoryValue = $categoryValue->plus($aggregate['value']);
                    $categoryQuantities = $this->mergeQuantities($categoryQuantities, $aggregate['quantities']);
                }
            }

            $unassigned = $aggregates["{$category->id}:"] ?? null;

            if ($unassigned !== null && ! $this->isEmptyAggregate($unassigned)) {
                $categoryValue = $categoryValue->plus($unassigned['value']);
                $categoryQuantities = $this->mergeQuantities($categoryQuantities, $unassigned['quantities']);

                $subcategoryRows[] = [
                    'subcategoryId' => null,
                    'subcategoryName' => 'Uncategorized',
                    'value' => $unassigned['value']->toString(),
                    'quantities' => $this->presentQuantities($unassigned['quantities']),
                ];
            }

            $rows[] = [
                'categoryId' => $category->id,
                'categoryName' => $category->name,
                'value' => $categoryValue->toString(),
                'quantities' => $this->presentQuantities($categoryQuantities),
                'subcategories' => $subcategoryRows,
            ];

            $grandValue = $grandValue->plus($categoryValue);
            $grandQuantities = $this->mergeQuantities($grandQuantities, $categoryQuantities);
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

    /**
     * Defaults to the current open fiscal year's date range when no
     * explicit `from`/`to` query params are given, falling back to
     * month-to-date if no fiscal year exists yet. Identical logic to
     * SalesPurchaseReportController::resolveDateRange() - duplicated rather
     * than shared across controllers, matching this app's existing
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
