<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'item_category_id',
    'item_subcategory_id',
    'account_id',
    'name',
    'description',
    'unit',
    'hs_code',
    'min_stock',
    'expiry_date',
    'is_vatable',
    'is_stockable',
    'is_active',
])]
class Item extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_stock' => 'decimal:2',
            'expiry_date' => 'date',
            'is_vatable' => 'boolean',
            'is_stockable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ItemCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    /**
     * @return BelongsTo<ItemSubcategory, $this>
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ItemSubcategory::class, 'item_subcategory_id');
    }

    /**
     * The inventory/COGS ledger account this item posts against, if set.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<ItemStockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(ItemStockMovement::class);
    }

    /**
     * Writes one quantity movement for this item. Purely a stock-quantity
     * tracker - never posts a JournalVoucher (see StockMovementType's
     * docblock: legacy day_khata runs periodic, not perpetual, inventory
     * accounting, and this rewrite preserves that).
     */
    public function recordStockMovement(
        StockMovementType $type,
        float $quantity,
        string $date,
        ?Model $reference = null,
        ?float $unitCostRate = null,
        ?string $narration = null,
    ): ItemStockMovement {
        return $this->stockMovements()->create([
            'movement_type' => $type,
            'quantity' => $quantity,
            'unit_cost_rate' => $unitCostRate,
            'date' => $date,
            'narration' => $narration,
            'reference_type' => $reference ? $reference->getMorphClass() : null,
            'reference_id' => $reference?->getKey(),
        ]);
    }

    /**
     * Net on-hand quantity: signed sum of every non-cancelled movement,
     * per StockMovementType::direction(). Computed at query time rather
     * than cached, matching the legacy app's periodic-accounting model.
     */
    public function currentStock(): float
    {
        return (float) $this->stockMovements()
            ->where('cancelled', false)
            ->get()
            ->sum(fn (ItemStockMovement $movement) => (float) $movement->quantity * $movement->movement_type->direction());
    }
}
