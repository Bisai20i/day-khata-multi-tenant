<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
     * @return HasMany<ItemVariety, $this>
     */
    public function varieties(): HasMany
    {
        return $this->hasMany(ItemVariety::class);
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
        int $storeId,
        ?Model $reference = null,
        ?float $unitCostRate = null,
        ?string $narration = null,
    ): ItemStockMovement {
        return $this->stockMovements()->create([
            'store_id' => $storeId,
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
     * Net on-hand quantity: signed sum of every non-cancelled movement, per
     * StockMovementType::direction(). Filtered to one store when $storeId is
     * given, a cross-store total when omitted (existing callers that don't
     * care about store scoping keep working).
     */
    public function currentStock(?int $storeId = null): float
    {
        return (float) $this->stockMovements()
            ->where('cancelled', false)
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->get()
            ->sum(fn (ItemStockMovement $movement) => (float) $movement->quantity * $movement->movement_type->direction());
    }

    /**
     * Items whose expiry_date has passed, i.e. is today or earlier. Uses
     * whereDate() rather than a raw string comparison - this app's own
     * documented SQLite gotcha (mem.md) is that a `date`-cast column stores
     * a full "Y-m-d H:i:s" datetime string, which sorts lexicographically
     * wrong against a bare "Y-m-d" boundary string; whereDate() sidesteps
     * that portably. An item expiring exactly today is treated as expired
     * (not "expiring soon"), matching the natural "don't sell it today"
     * business reading.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', now()->toDateString());
    }

    /**
     * Items whose expiry_date falls strictly after today, within the next
     * $withinDays days (inclusive of the far boundary). See scopeExpired()
     * for why whereDate() is used, and why "today" itself is excluded here
     * (it belongs to scopeExpired() instead, not both).
     */
    public function scopeExpiringSoon(Builder $query, int $withinDays = 30): Builder
    {
        $today = now()->toDateString();
        $until = now()->addDays($withinDays)->toDateString();

        return $query->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>', $today)
            ->whereDate('expiry_date', '<=', $until);
    }
}
