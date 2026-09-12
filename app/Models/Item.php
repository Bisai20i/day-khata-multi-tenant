<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\StockMovementType;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'item_category_id',
    'item_subcategory_id',
    'brand_id',
    'account_id',
    'name',
    'description',
    'unit',
    'hs_code',
    'barcode',
    'min_stock',
    'expiry_date',
    'purchase_rate',
    'sale_rate',
    'image_path',
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
            'min_stock' => Decimal::class.':2',
            'expiry_date' => 'date',
            'purchase_rate' => Decimal::class.':4',
            'sale_rate' => Decimal::class.':4',
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
     * Optional brand/manufacturer tag - see Brand's docblock. Unlike
     * category(), this may be null; an item with no brand_id is unaffected
     * by anything reading this relation.
     *
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
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
     * This item's alternate units of sale/purchase (e.g. "Box" = 12 of the
     * base `unit`) - see ItemUnit's docblock. An item with none is exactly
     * as before this relation existed; nothing here implies a base-unit row
     * must exist.
     *
     * @return HasMany<ItemUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(ItemUnit::class);
    }

    /**
     * Writes one quantity movement for this item (CONTRACTS C10). Purely a
     * stock-quantity tracker - never posts a JournalVoucher (see
     * StockMovementType's docblock: legacy day_khata runs periodic, not
     * perpetual, inventory accounting, and this rewrite preserves that).
     *
     * Two rules this signature enforces, both from audit P0-17:
     *
     * - `$quantity` is always in the item's **base** unit. A line entered in
     *   an alternate unit multiplies by its conversion factor before getting
     *   here.
     * - `$unitCostRate` is the net cost per **base** unit, i.e.
     *   `$value / $quantity` rounded once to 4 decimals. It is never the
     *   entered-unit rate: buying 2 Box of 12 at Rs 1,200 per Box is 24
     *   pieces at Rs 100 each, not 24 pieces at Rs 1,200 each. Callers that
     *   pass `$value` and leave `$unitCostRate` null get that division done
     *   for them, which is the safest way to call this.
     * - `$value` is the exact, positive rupee value of a priced movement
     *   (a purchase line's net value after line and header discount, VAT
     *   excluded; opening stock and priced adjustments-in at qty x rate;
     *   a purchase return's credited net value). Unpriced movements - sales,
     *   transfers, write-offs - pass null, which keeps them out of the cost
     *   basis entirely instead of pricing them at zero.
     *
     * `$reference` is nullable purely so a bare quantity movement can be
     * seeded (report tests and fixtures do this); every real posting passes
     * the document line it came from.
     */
    public function recordStockMovement(
        StockMovementType $type,
        Quantity|BigDecimal|string|int|float $quantity,
        string $date,
        int $storeId,
        ?Model $reference = null,
        Quantity|BigDecimal|string|int|float|null $unitCostRate = null,
        Money|BigDecimal|string|int|null $value = null,
        ?string $narration = null,
    ): ItemStockMovement {
        $quantity = Quantity::of($quantity);
        $value = $value === null ? null : Money::of($value);

        if ($unitCostRate === null && $value !== null && ! $quantity->isZero()) {
            $unitCostRate = Quantity::of(
                $value->toBigDecimal()->dividedBy($quantity->toBigDecimal(), 4, RoundingMode::HalfUp)
            );
        }

        return $this->stockMovements()->create([
            'store_id' => $storeId,
            'movement_type' => $type,
            'quantity' => $quantity,
            'unit_cost_rate' => $unitCostRate === null ? null : Quantity::of($unitCostRate),
            'value' => $value,
            'date' => $date,
            'narration' => $narration,
            'reference_type' => $reference ? $reference->getMorphClass() : null,
            'reference_id' => $reference?->getKey(),
        ]);
    }

    /**
     * Net on-hand quantity: signed sum of every non-cancelled movement, per
     * StockMovementType::direction(). Filtered to one store when $storeId is
     * given, a cross-store total when omitted; bounded to movements dated on
     * or before $asOf when given, which is what every "as of" report needs.
     */
    public function currentStock(?int $storeId = null, ?string $asOf = null): Quantity
    {
        return static::currentStockByItem([$this->getKey()], $storeId, $asOf)[$this->getKey()] ?? Quantity::zero();
    }

    /**
     * On-hand quantity for many items in one query, keyed by item id - the
     * batch form every list screen needs so it does not run one query per
     * row. Items with no movements at all come back as an explicit zero, so
     * callers never have to null-check.
     *
     * The sum runs in SQL rather than in PHP, which is what audit P1 asked
     * for ("Item::currentStock() sums every movement in PHP floats", so
     * returning the last 0.2 of a 0.3 line failed with a message reading
     * "0.19999999999999998"). It is summed as a **scaled integer** rather
     * than as the raw DECIMAL because SQLite - which the test suite runs on -
     * gives a `numeric` column REAL affinity, so a plain SUM() there comes
     * back as a float and reintroduces exactly the error being removed.
     * Multiplying by 10^4 and casting to an integer inside SQL makes the sum
     * exact on SQLite and on MySQL alike; dividing back by 10^4 is lossless
     * at the 4 decimals a Quantity holds.
     *
     * @param  array<int, int|string>  $itemIds
     * @return array<int, Quantity>
     */
    public static function currentStockByItem(array $itemIds, ?int $storeId = null, ?string $asOf = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $itemIds)));

        if ($ids === []) {
            return [];
        }

        $stock = array_fill_keys($ids, Quantity::zero());

        $inTypes = array_map(
            fn (StockMovementType $type) => $type->value,
            array_values(array_filter(StockMovementType::cases(), fn (StockMovementType $type) => $type->direction() === 1)),
        );

        $scaled = static::scaledQuantityExpression();
        $placeholders = implode(', ', array_fill(0, count($inTypes), '?'));

        $rows = ItemStockMovement::query()
            ->selectRaw(
                "item_id, SUM(CASE WHEN movement_type IN ({$placeholders}) THEN {$scaled} ELSE -{$scaled} END) as net_scaled",
                $inTypes,
            )
            ->whereIn('item_id', $ids)
            ->where('cancelled', false)
            ->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId))
            ->when($asOf !== null, fn (Builder $query) => $query->whereDate('date', '<=', $asOf))
            ->groupBy('item_id')
            ->pluck('net_scaled', 'item_id');

        foreach ($rows as $itemId => $netScaled) {
            $stock[(int) $itemId] = static::quantityFromScaled($netScaled);
        }

        return $stock;
    }

    /**
     * Locks the given items' own rows, ascending by id, for the rest of the
     * current transaction (CONTRACTS C10).
     *
     * Every posting that reduces stock calls this before it reads stock, so
     * two terminals cannot both pass the negative-stock check on the last
     * unit and then both sell it (audit P1: "Negative-stock check reads
     * without a lock"). The `items` row is the lock target rather than the
     * movement rows because a concurrent posting inserts *new* movements,
     * which a range lock on movements would not reliably block; ascending id
     * order is what keeps two multi-item postings from deadlocking against
     * each other. `lockForUpdate()` is a no-op on SQLite, so this is
     * verified by review, not by a test.
     *
     * @param  array<int, int|string>  $itemIds
     * @return Collection<int, static>
     */
    public static function lockForStockOut(array $itemIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $itemIds)));

        if ($ids === []) {
            return new Collection;
        }

        return static::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * `quantity` as an exact integer scaled by 10^4, spelled for the driver
     * in use. MySQL has no `CAST(... AS INTEGER)` (it wants `SIGNED`) and
     * SQLite has no `SIGNED`, so the cast keyword is the one thing that has
     * to differ. ROUND() is what absorbs SQLite's REAL representation of the
     * stored decimal (0.1 arrives as 0.1000000000000000055); on MySQL the
     * column is a true DECIMAL and ROUND() changes nothing.
     */
    private static function scaledQuantityExpression(): string
    {
        $cast = (new ItemStockMovement)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        return "CAST(ROUND(quantity * 10000) AS {$cast})";
    }

    /**
     * Turns the scaled-integer SUM back into a Quantity. The value is a sum
     * of integers, so the int cast is exact (a tenant would need 9.2 x 10^14
     * units of one item to reach the 64-bit limit) and the division by 10^4
     * is lossless at 4 decimals - there is no rounding step here to get
     * wrong.
     */
    private static function quantityFromScaled(mixed $netScaled): Quantity
    {
        return Quantity::of(
            BigDecimal::of((int) $netScaled)->dividedBy(10000, 4, RoundingMode::Unnecessary)
        );
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
