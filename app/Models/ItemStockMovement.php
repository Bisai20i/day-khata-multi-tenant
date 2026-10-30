<?php

namespace App\Models;

use App\Casts\Decimal;
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
 *
 * `quantity` is always in the item's base unit, `unit_cost_rate` is always
 * the net cost per base unit, and `value` is the exact rupee value of a
 * priced movement (null when the movement has no cost basis at all - a sale,
 * a store-to-store transfer, a damage write-off). `unit_cost_rate` is a
 * derived convenience (`value / quantity`, rounded once); `value` is the
 * authoritative figure App\Support\Inventory\StockCosting reads. See
 * CONTRACTS C10 and Item::recordStockMovement().
 *
 * `cost_value` is the perpetual cost of the movement for Raw Material and
 * Finished Good items (todo/manufacturing CONTRACTS MC3), always positive with
 * the sign taken from the movement type, and always null for trading items.
 * Unlike `value` it is never a selling price, so its signed sum per class
 * equals the class's inventory account balance.
 */
#[Fillable(['item_id', 'store_id', 'movement_type', 'quantity', 'unit_cost_rate', 'value', 'cost_value', 'reference_type', 'reference_id', 'date', 'cancelled', 'narration'])]
class ItemStockMovement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => StockMovementType::class,
            'quantity' => Decimal::class.':4',
            'unit_cost_rate' => Decimal::class.':4',
            'value' => Decimal::class.':2',
            'cost_value' => Decimal::class.':2',
            'date' => 'date:Y-m-d',
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
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
