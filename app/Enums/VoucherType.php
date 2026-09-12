<?php

namespace App\Enums;

/**
 * Every case here is its own gapless per-fiscal-year numbering sequence (see
 * App\Models\VoucherSequence): the voucher number a case hands out is the
 * number printed on the document it backs, so two documents that must be
 * numbered independently by law - or that must never steal a number from one
 * another - need two cases, not one.
 *
 * Sale/SaleAbbreviated/SalePan are three independent sequences because Nepali
 * VAT law distinguishes an "abbreviated tax invoice" (small retail bills) from
 * a full tax invoice - two separate legally-numbered sequences, not a cosmetic
 * difference - and because a PAN bill carries no VAT at all and prints its own
 * prefix. Sharing one sequence between them printed a different prefix on the
 * same underlying number, so each printed series showed gaps (audit P0-15).
 * See day-khata-multi-tenant mem.md's Sales module section for the full
 * research this was built from.
 *
 * Reversal is the single series every cancellation reversal posts into, for
 * the same reason: a cancelled bill used to mirror itself as a SaleReturn (and
 * a cancelled return as a Sale), which burned a real credit-note or invoice
 * number on a document that never existed. A reversal now consumes only a
 * Reversal number, so no customer-facing series ever gains a hole.
 */
enum VoucherType: string
{
    case OpeningBalance = 'opening_balance';
    case Journal = 'journal';
    case ClosingEntry = 'closing_entry';
    case RollForwardAdjustment = 'roll_forward_adjustment';
    case Reversal = 'reversal';
    case Sale = 'sale';
    case SaleAbbreviated = 'sale_abbreviated';
    case SalePan = 'sale_pan';
    case SaleReturn = 'sale_return';
    case Purchase = 'purchase';
    case PurchaseReturn = 'purchase_return';
    case CapitalPurchase = 'capital_purchase';
    case CapitalSale = 'capital_sale';
    case FixedAssetPurchase = 'fixed_asset_purchase';
    case Depreciation = 'depreciation';
    case AssetDisposal = 'asset_disposal';
    case Receipt = 'receipt';
    case Payment = 'payment';
}
