<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\StockAdjustmentReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_adjustment_id', 'item_id', 'item_unit_id', 'unit_conversion_factor',
    'direction', 'reason_type', 'quantity', 'unit_cost_rate', 'line_value', 'remarks',
])]
class StockAdjustmentLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason_type' => StockAdjustmentReason::class,
            'quantity' => Decimal::class.':4',
            // The alternate unit's factor (item 7) - '1' when the line was
            // entered in the item's own base unit, same convention as
            // purchase_lines.unit_conversion_factor.
            'unit_conversion_factor' => Decimal::class.':4',
            'unit_cost_rate' => Decimal::class.':4',
            'line_value' => Decimal::class.':2',
        ];
    }

    /**
     * @return BelongsTo<StockAdjustment, $this>
     */
    public function stockAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The alternate unit this line was entered in, if any (item 7).
     *
     * @return BelongsTo<ItemUnit, $this>
     */
    public function itemUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class);
    }

    /**
     * The unit the line was entered in: the alternate unit when one was
     * chosen, otherwise the item's own base unit.
     */
    public function unitName(): ?string
    {
        return $this->itemUnit?->name ?? $this->item?->unit;
    }
}
