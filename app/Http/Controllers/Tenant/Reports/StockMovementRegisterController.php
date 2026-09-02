<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturnLine;
use App\Models\SaleLine;
use App\Models\SaleReturnLine;
use App\Models\StockAdjustmentLine;
use App\Models\Store;
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
 */
class StockMovementRegisterController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $itemId = $request->integer('item_id') ?: null;
        $storeId = $request->integer('store_id') ?: null;

        $movements = ItemStockMovement::query()
            ->where('cancelled', false)
            ->whereBetween('date', [$from, $to])
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->with(['item:id,name,unit', 'store:id,name'])
            ->with(['reference' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    SaleLine::class => ['sale.customer'],
                    PurchaseLine::class => ['purchase.supplier'],
                    SaleReturnLine::class => ['salesReturn'],
                    PurchaseReturnLine::class => ['purchaseReturn'],
                    StockAdjustmentLine::class => ['stockAdjustment'],
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
                'quantity' => round((float) $movement->quantity * $movement->movement_type->direction(), 4),
                'unitCostRate' => $movement->unit_cost_rate !== null ? round((float) $movement->unit_cost_rate, 4) : null,
                'reference' => $this->referenceDescription($movement->reference, $movement->narration),
            ])->values(),
            'items' => Item::query()->orderBy('name')->get(['id', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'itemId' => $itemId,
            'storeId' => $storeId,
        ]);
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
            $reference instanceof SaleLine => 'Sale #'.$reference->sale_id
                .($reference->sale?->customer?->name ? ' · '.$reference->sale->customer->name : ''),
            $reference instanceof PurchaseLine => 'Purchase #'.$reference->purchase_id
                .($reference->purchase?->supplier?->name ? ' · '.$reference->purchase->supplier->name : ''),
            $reference instanceof SaleReturnLine => 'Sale Return #'.$reference->sales_return_id
                .($reference->salesReturn?->sale_id ? ' (Sale #'.$reference->salesReturn->sale_id.')' : ''),
            $reference instanceof PurchaseReturnLine => 'Purchase Return #'.$reference->purchase_return_id
                .($reference->purchaseReturn?->purchase_id ? ' (Purchase #'.$reference->purchaseReturn->purchase_id.')' : ''),
            $reference instanceof StockAdjustmentLine => 'Stock Adjustment #'.$reference->stock_adjustment_id,
            default => $narration ?: '—',
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
