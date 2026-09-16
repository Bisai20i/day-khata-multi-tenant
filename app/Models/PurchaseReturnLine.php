<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One returned line of a debit note.
 *
 * `net_value` is the load-bearing amount: the rupees this line credited back
 * after the original bill's line AND header discounts, excluding VAT. It is
 * what CONTRACTS C6 cuts every further return's share from, so a second and
 * third partial return of the same purchase line can never credit a paisa more
 * or less than the line was worth. `line_total` holds the same figure for
 * display; rows posted before that rule existed keep their historical value in
 * `line_total` and carry the recovered one in `net_value`.
 *
 * `bonus_quantity` (item 3) is the portion of THIS line's own `quantity` that
 * PurchaseReturn::prepareLine() decided came out of the purchase line's bonus
 * allotment rather than its paid one - credited at zero, persisted so a later
 * return against the same purchase line replays the paid/bonus split
 * correctly instead of re-deriving it.
 *
 * `item_id`/`item_unit_id`/`unit_conversion_factor` are populated only on an
 * UNLINKED return (item 4, `purchase_line_id` null) - the item and unit are
 * named directly instead of inherited from a purchase line.
 */
#[Fillable([
    'purchase_return_id', 'purchase_line_id', 'item_id', 'item_unit_id', 'unit_conversion_factor',
    'quantity', 'bonus_quantity', 'rate', 'line_total', 'net_value', 'vat_amount', 'tds_amount',
])]
class PurchaseReturnLine extends Model
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
            'rate' => Decimal::class.':4',
            'line_total' => Decimal::class.':2',
            'net_value' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'tds_amount' => Decimal::class.':2',
        ];
    }

    /**
     * @return BelongsTo<PurchaseReturn, $this>
     */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    /**
     * @return BelongsTo<PurchaseLine, $this>
     */
    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class);
    }

    /**
     * The item named directly on an UNLINKED line only - a linked line reads
     * its item off `purchaseLine->item` instead.
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

    /**
     * The item to show on screen/print, whichever kind of line this is.
     */
    public function documentItem(): ?Item
    {
        return $this->purchaseLine?->item ?? $this->item;
    }

    /**
     * The unit name to show on screen/print, whichever kind of line this is.
     */
    public function documentUnitName(): ?string
    {
        if ($this->purchaseLine) {
            return $this->purchaseLine->unitName();
        }

        return $this->itemUnit?->name ?? $this->item?->unit;
    }
}
