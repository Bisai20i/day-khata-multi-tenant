<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Concerns\DescribesStockMovements;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemStockMovement;
use App\Models\ItemSubcategory;
use App\Models\Store;
use App\Support\Money\Quantity;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Chronological audit trail of every individual stock movement in a date
 * range - the inventory-side sibling of AccountingReportController::
 * dayBook(). Unlike StockSummary/StockValuation (aggregates per item), this
 * lists one row per ItemStockMovement so a user can trace exactly what
 * happened to stock over time.
 *
 * Audit T15-11: `category_id`/`subcategory_id`/`brand_id` additionally
 * narrow the register to one item group's movements, via a `whereHas` on
 * the `item` relation (movements carry only `item_id`, not the group
 * columns, so this cannot be a plain `where()`) - the same "pivot from a
 * group aggregate into its lines" drill-down added to the item-wise
 * reports, for the register's own audience (stock, not sales/purchase
 * value).
 */
class StockMovementRegisterController extends Controller
{
    use DescribesStockMovements;

    public function index(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $itemId = $request->integer('item_id') ?: null;
        $storeId = $request->integer('store_id') ?: null;
        $categoryId = $request->integer('category_id') ?: null;
        $subcategoryId = $request->integer('subcategory_id') ?: null;
        $brandId = $request->integer('brand_id') ?: null;

        $movements = ItemStockMovement::query()
            ->where('cancelled', false)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->when(
                $categoryId || $subcategoryId || $brandId,
                fn ($query) => $query->whereHas('item', function ($itemQuery) use ($categoryId, $subcategoryId, $brandId) {
                    $itemQuery
                        ->when($categoryId, fn ($q) => $q->where('item_category_id', $categoryId))
                        ->when($subcategoryId, fn ($q) => $q->where('item_subcategory_id', $subcategoryId))
                        ->when($brandId, fn ($q) => $q->where('brand_id', $brandId));
                })
            )
            ->with(['item:id,name,unit', 'store:id,name'])
            ->with($this->stockMovementReferenceEagerLoad())
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return Inertia::render('Tenant/Reports/StockMovementRegister', [
            'movements' => $movements->map(fn (ItemStockMovement $movement) => [
                'date' => $movement->date->toDateString(),
                'itemId' => $movement->item_id,
                'itemName' => $movement->item->name,
                'storeName' => $movement->store?->name,
                'unit' => $movement->item->unit,
                'movementType' => $this->movementTypeLabel($movement->movement_type),
                'quantity' => $this->signedStockQuantity($movement)->toString(),
                'unitCostRate' => $movement->unit_cost_rate === null ? null : Quantity::of($movement->unit_cost_rate)->toString(),
                'reference' => $this->referenceDescription($movement->reference, $movement->narration),
            ])->values(),
            'items' => Item::query()->orderBy('name')->get(['id', 'name']),
            'categories' => ItemCategory::query()->orderBy('name')->get(['id', 'name']),
            'subcategories' => ItemSubcategory::query()->orderBy('name')->get(['id', 'name', 'item_category_id']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'itemId' => $itemId,
            'storeId' => $storeId,
            'categoryId' => $categoryId,
            'subcategoryId' => $subcategoryId,
            'brandId' => $brandId,
        ]);
    }

    /**
     * Defaults to the current open fiscal year's date range when no
     * explicit `from`/`to` query params are given, falling back to
     * month-to-date if no fiscal year exists yet. Duplicated from
     * SalesPurchaseReportController::resolveDateRange() rather than shared,
     * matching this app's existing per-controller-file convention.
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
