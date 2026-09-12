<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sale_id', 'item_id', 'item_unit_id', 'quantity', 'unit_conversion_factor', 'rate', 'discount', 'discount_type', 'discount_amount', 'vatable', 'line_total'])]
class SaleLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => Decimal::class.':4',
            'unit_conversion_factor' => Decimal::class.':4',
            'rate' => Decimal::class.':4',
            'discount' => Decimal::class.':2',
            'discount_amount' => Decimal::class.':2',
            'vatable' => 'boolean',
            'line_total' => Decimal::class.':2',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The alternate unit this line was entered in, if any - null means the
     * item's own base unit (see ItemUnit's docblock). `quantity`/`rate`
     * above are always in terms of THIS unit, not the base unit;
     * unit_conversion_factor is what was multiplied into the base-unit
     * stock movement this line generated (see Sale::post()).
     *
     * @return BelongsTo<ItemUnit, $this>
     */
    public function itemUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class);
    }
}
