<?php

namespace App\Enums;

/**
 * Legacy day_khata runs periodic (not perpetual) inventory accounting: a
 * sale/purchase never posts an inventory-asset or COGS ledger line, only a
 * quantity movement here. Confirmed via an explicit docblock in the legacy
 * StockAdjustmentController - see day-khata-multi-tenant mem.md's Sales/
 * Purchase module section. direction() encodes which side of the ledger
 * (in/out of stock) each type represents, so callers never hardcode signs.
 */
enum StockMovementType: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case PurchaseReturn = 'purchase_return';
    case SaleReturn = 'sale_return';
    case Opening = 'opening';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';

    public function direction(): int
    {
        return match ($this) {
            self::Purchase, self::SaleReturn, self::Opening, self::AdjustmentIn => 1,
            self::Sale, self::PurchaseReturn, self::AdjustmentOut => -1,
        };
    }
}
