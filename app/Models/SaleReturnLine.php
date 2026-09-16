<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One returned line of a credit note.
 *
 * `net_amount`, `vat_amount` and `tds_amount` are the three components this
 * line actually credited (CONTRACTS C6): the line's value after the line AND
 * header discount, its share of the invoice's VAT, and its share of the
 * invoice's TDS. They are stored rather than recomputed because the return
 * that consumes a sale line's last remaining quantity credits "the component
 * minus everything already credited for that line" - which is only exact if
 * what earlier returns credited is a fact on the row, not a re-derivation.
 * `line_total` keeps its original meaning (what the customer is credited for
 * the goods themselves) and always equals `net_amount`.
 *
 * `bonus_quantity` (audit section 3 "Sales"): bonus/free units returned
 * alongside `quantity` - always credits zero rupees, only restocks.
 *
 * `sale_line_id` is null, and `item_id`/`item_unit_id`/
 * `unit_conversion_factor`/`vatable` are set instead, only for an "unlinked"
 * return line (SalesReturn::postUnlinked(), C7 "returns without a bill") -
 * one with no original SaleLine to point at.
 */
#[Fillable([
    'sales_return_id', 'sale_line_id', 'item_id', 'item_unit_id', 'unit_conversion_factor', 'vatable',
    'quantity', 'bonus_quantity', 'rate', 'line_total', 'net_amount', 'vat_amount', 'tds_amount',
])]
class SaleReturnLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => Decimal::class.':4',
            'bonus_quantity' => Decimal::class.':4',
            'unit_conversion_factor' => Decimal::class.':4',
            'vatable' => 'boolean',
            'rate' => Decimal::class.':4',
            'line_total' => Decimal::class.':2',
            'net_amount' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'tds_amount' => Decimal::class.':2',
        ];
    }

    /**
     * @return BelongsTo<SalesReturn, $this>
     */
    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    /**
     * Null for an unlinked line - see the class docblock.
     *
     * @return BelongsTo<SaleLine, $this>
     */
    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
    }

    /**
     * Set only on an unlinked line, which names its item directly instead of
     * inheriting one from a SaleLine.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<ItemUnit, $this>
     */
    public function itemUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class);
    }
}
