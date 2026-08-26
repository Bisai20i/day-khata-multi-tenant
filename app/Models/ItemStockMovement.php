<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One quantity movement against an item - purely a stock-quantity tracker,
 * deliberately decoupled from the ledger (see StockMovementType's docblock).
 * Sales/Purchase/Stock-Adjustment write these directly; nothing here posts
 * a JournalVoucher.
 */
#[Fillable(['item_id', 'movement_type', 'quantity', 'unit_cost_rate', 'reference_type', 'reference_id', 'date', 'cancelled', 'narration'])]
class ItemStockMovement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => StockMovementType::class,
            'quantity' => 'decimal:4',
            'unit_cost_rate' => 'decimal:4',
            'date' => 'date',
            'cancelled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The Sale/SaleLine/Purchase/PurchaseLine (or similar) row this
     * movement was generated from, if any.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
