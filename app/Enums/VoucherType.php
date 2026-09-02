<?php

namespace App\Enums;

/**
 * Sale/SaleAbbreviated are two independent numbering sequences (both use
 * the existing per-fiscal-year/per-type VoucherSequence mechanism) because
 * Nepali VAT law distinguishes an "abbreviated tax invoice" (small retail
 * bills) from a full tax invoice - two separate legally-numbered sequences,
 * not a cosmetic difference. See day-khata-multi-tenant mem.md's Sales
 * module section for the full research this was built from.
 */
enum VoucherType: string
{
    case OpeningBalance = 'opening_balance';
    case Journal = 'journal';
    case ClosingEntry = 'closing_entry';
    case RollForwardAdjustment = 'roll_forward_adjustment';
    case Sale = 'sale';
    case SaleAbbreviated = 'sale_abbreviated';
    case SaleReturn = 'sale_return';
    case Purchase = 'purchase';
    case PurchaseReturn = 'purchase_return';
    case FixedAssetPurchase = 'fixed_asset_purchase';
    case Depreciation = 'depreciation';
    case AssetDisposal = 'asset_disposal';
    case Receipt = 'receipt';
    case Payment = 'payment';
}
