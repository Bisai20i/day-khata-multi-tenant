<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\StockAdjustmentReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_adjustment_id', 'item_id', 'direction', 'reason_type', 'quantity', 'unit_cost_rate', 'line_value', 'remarks'])]
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
}
