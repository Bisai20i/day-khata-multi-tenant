<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An alternate unit an item can be bought/sold in - e.g. an item whose base
 * `unit` is "pcs" might also be sold by the "Box" (conversion_factor 12).
 * Purely additive: an item with zero rows here behaves exactly as before
 * this model existed (see Item::currentStock(), Sale::post(), Purchase::
 * post(), which all treat a missing item_unit_id as the base unit with an
 * implicit conversion factor of 1 - no base-unit ItemUnit row is ever
 * required or created).
 *
 * purchase_rate/sale_rate/mrp are nullable overrides for this specific unit
 * - null falls back to the parent Item's own purchase_rate/sale_rate at the
 * UI layer (see Sales/Purchases Create.vue); Sale::post()/Purchase::post()
 * never read them directly, since the entered line `rate` is always what's
 * actually posted (only the stock-quantity side is unit-aware, per the
 * "money math stays in the entered unit/rate" design decision - see this
 * model's sibling docs in Sale::post()/Purchase::post()).
 *
 * `barcode` (added later, audit section 3 "Purchase") is this specific
 * unit's own scannable code - e.g. a "Box of 12" prints and scans a
 * different barcode than a single piece. Nullable and unique; scanning it
 * on a document form selects both the item and this unit.
 */
#[Fillable(['item_id', 'name', 'barcode', 'conversion_factor', 'purchase_rate', 'sale_rate', 'mrp', 'is_active'])]
class ItemUnit extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversion_factor' => Decimal::class.':4',
            'purchase_rate' => Decimal::class.':4',
            'sale_rate' => Decimal::class.':4',
            'mrp' => Decimal::class.':4',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
