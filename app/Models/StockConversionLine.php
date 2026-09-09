<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stock_conversion_id', 'item_id', 'direction', 'quantity', 'unit_cost_rate', 'line_value', 'remarks'])]
class StockConversionLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost_rate' => 'decimal:4',
            'line_value' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<StockConversion, $this>
     */
    public function stockConversion(): BelongsTo
    {
        return $this->belongsTo(StockConversion::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
