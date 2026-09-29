<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\StockMovementType;
use App\Models\ItemStockMovement;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturnLine;
use App\Models\SaleLine;
use App\Models\SaleReturnLine;
use App\Models\StockAdjustmentLine;
use App\Models\StockTransferLine;
use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Row-level presentation of an ItemStockMovement, shared by the Stock
 * Movement Register (many items) and the Item Ledger (one item). Both
 * screens must label a movement and name its source document identically,
 * otherwise a user cross-checking one against the other sees two different
 * descriptions of the same stock event.
 */
trait DescribesStockMovements
{
    /**
     * Eager-load map for the polymorphic `reference`, so describing a page
     * of movements costs a fixed number of queries rather than one per row.
     *
     * @return array<string, \Closure(MorphTo): void>
     */
    protected function stockMovementReferenceEagerLoad(): array
    {
        return ['reference' => function (MorphTo $morphTo) {
            $morphTo->morphWith([
                SaleLine::class => ['sale.customer'],
                PurchaseLine::class => ['purchase.supplier'],
                SaleReturnLine::class => ['salesReturn'],
                PurchaseReturnLine::class => ['purchaseReturn'],
                StockAdjustmentLine::class => ['stockAdjustment'],
                StockTransferLine::class => ['stockTransfer.fromStore', 'stockTransfer.toStore'],
            ]);
        }];
    }

    /**
     * The movement's quantity with the direction folded in. Never a float:
     * a register or ledger is the audit trail stock disputes get settled
     * from, and 0.1 + 0.2 printing as 0.30000000000000004 is exactly the
     * class of bug this rewrite removed.
     */
    protected function signedStockQuantity(ItemStockMovement $movement): Quantity
    {
        $quantity = Quantity::of($movement->quantity);

        return $movement->movement_type->direction() === -1 ? $quantity->negated() : $quantity;
    }

    protected function movementTypeLabel(StockMovementType $type): string
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
    protected function referenceDescription(?Model $reference, ?string $narration): string
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
}
