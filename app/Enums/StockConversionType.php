<?php

namespace App\Enums;

/**
 * Production and Refining are mechanically identical - both consume one or
 * more input items at given quantities and produce one or more output items
 * at given quantities, as a single store-scoped, ledger-free stock movement
 * (see StockConversion's own docblock). `type` is purely a narration/
 * reporting label distinguishing the two, exactly like CapitalPurchase's own
 * `type in:capital,service` - not a mechanical difference.
 */
enum StockConversionType: string
{
    case Production = 'production';
    case Refining = 'refining';
}
