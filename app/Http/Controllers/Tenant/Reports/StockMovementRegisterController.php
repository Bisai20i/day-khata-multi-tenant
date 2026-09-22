<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemStockMovement;
use App\Models\ItemSubcategory;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturnLine;
use App\Models\SaleLine;
use App\Models\SaleReturnLine;
use App\Models\StockAdjustmentLine;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
            ->with(['reference' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    SaleLine::class => ['sale.customer'],
                    PurchaseLine::class => ['purchase.supplier'],
                    SaleReturnLine::class => ['salesReturn'],
                    PurchaseReturnLine::class => ['purchaseReturn'],
                    StockAdjustmentLine::class => ['stockAdjustment'],
                    StockTransferLine::class => ['stockTransfer.fromStore', 'stockTransfer.toStore'],
                ]);
            }])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return Inertia::render('Tenant/Reports/StockMovementRegister', [
            'movements' => $movements->map(fn (ItemStockMovement $movement) => [
                'date' => $movement->date->toDateString(),
                'itemName' => $movement->item->name,
                'storeName' => $movement->store?->name,
                'unit' => $movement->item->unit,
                'movementType' => $this->movementTypeLabel($movement->movement_type),
                'quantity' => $this->signedQuantity($movement),
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
     * The movement's quantity with the direction folded in, as an exact
     * 4-decimal string. Never a float: a register is the audit trail stock
     * disputes get settled from, and 0.1 + 0.2 printing as
     * 0.30000000000000004 is exactly the class of bug this rewrite removed.
     */
    private function signedQuantity(ItemStockMovement $movement): string
    {
        $quantity = Quantity::of($movement->quantity);

        return ($movement->movement_type->direction() === -1 ? $quantity->negated() : $quantity)->toString();
    }

    private function movementTypeLabel(StockMovementType $type): string
    {
        return match ($type) {
            StockMovementType::Purchase => 'Purchase',
            StockMovementType::Sale => 'Sale',
            StockMovementType::PurchaseReturn => 'Purchase Return',
            StockMovementType::SaleReturn => 'Sale Return',
            StockMovementType::Opening => 'Opening',
            StockMovementType::AdjustmentIn => 'Adjustment In',
            StockMovementType::AdjustmentOut => 'Adjustment Out',
            StockMovementType::TransferIn => 'Transfer In',
            StockMovementType::TransferOut => 'Transfer Out',
            StockMovementType::ProductionIn => 'Production In',
            StockMovementType::ProductionOut => 'Production Out',
            StockMovementType::RefiningIn => 'Refining In',
            StockMovementType::RefiningOut => 'Refining Out',
            StockMovementType::RepackagingIn => 'Repackaging In',
            StockMovementType::RepackagingOut => 'Repackaging Out',
        };
    }

    /**
     * Human-readable description of what generated a movement, resolved
     * from the polymorphic `reference` relation set by
     * Item::recordStockMovement() at posting time (see Sale::post(),
     * Purchase::post(), SalesReturn::post(), PurchaseReturn::post(), and
     * StockAdjustment::post() for what each passes in).
     */
    private function referenceDescription(?Model $reference, ?string $narration): string
    {
        return match (true) {
            $reference instanceof SaleLine => 'Sale '.($reference->sale?->invoice_number ?? '#'.$reference->sale_id)
                .($reference->sale?->customer?->name ? ' · '.$reference->sale->customer->name : ''),
            $reference instanceof PurchaseLine => 'Purchase '.($reference->purchase?->bill_number ?? '#'.$reference->purchase_id)
                .($reference->purchase?->supplier?->name ? ' · '.$reference->purchase->supplier->name : ''),
            $reference instanceof SaleReturnLine => 'Credit Note '.($reference->salesReturn?->credit_note_number ?? '#'.$reference->sales_return_id),
            $reference instanceof PurchaseReturnLine => 'Debit Note '.($reference->purchaseReturn?->debit_note_number ?? '#'.$reference->purchase_return_id),
            $reference instanceof StockAdjustmentLine => 'Stock Adjustment #'.$reference->stock_adjustment_id,
            $reference instanceof StockTransferLine => 'Stock Transfer #'.$reference->stock_transfer_id
                .($reference->stockTransfer ? ' ('.$reference->stockTransfer->fromStore?->name.' → '.$reference->stockTransfer->toStore?->name.')' : ''),
            default => $narration ?: '-',
        };
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
