<?php

namespace App\Enums;

/**
 * Production, Refining, and Repackaging are mechanically identical - all
 * three consume one or more input items at given quantities and produce one
 * or more output items at given quantities, as a single store-scoped,
 * ledger-free stock movement (see StockConversion's own docblock). `type` is
 * purely a narration/reporting label distinguishing them, exactly like
 * CapitalPurchase's own `type in:capital,service` - not a mechanical
 * difference.
 *
 * Repackaging is the arbitrary N-items-in -> M-items-out conversion legacy
 * day_khata exposed at its `/transfer` route (InventoryStockController::
 * storeStockTransfer) - a generic stock-to-stock conversion with no
 * business meaning attached to "raw material" or "refined output" and, per
 * the legacy view/controller read for this build, no store dimension of its
 * own either. It is NOT the same feature as this app's own StockTransfer
 * model (a genuine store-to-store relocation of the SAME item) - deliberately
 * named to avoid that collision, since legacy's own UI label ("Stock
 * Transfer") is already taken by that unrelated, genuinely new capability.
 */
enum StockConversionType: string
{
    case Production = 'production';
    case Refining = 'refining';
    case Repackaging = 'repackaging';
}
