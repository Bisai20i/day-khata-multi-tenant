<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\InventoryClass;
use App\Enums\StockMovementType;
use App\Support\Inventory\PerpetualCosting;
use App\Support\Manufacturing\ManufacturingAccounts;
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
use InvalidArgumentException;

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
    'mrp',
    'image_path',
    'is_vatable',
    'is_stockable',
    'is_active',
    'inventory_class',
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
            'expiry_date' => 'date:Y-m-d',
            'purchase_rate' => Decimal::class.':4',
            'sale_rate' => Decimal::class.':4',
            // Base-unit MRP, alongside the per-alternate-unit item_units.mrp
            // that already existed.
            'mrp' => Decimal::class.':4',
            'is_vatable' => 'boolean',
            'is_stockable' => 'boolean',
            'is_active' => 'boolean',
            'inventory_class' => InventoryClass::class,
        ];
    }

    /**
     * Whether this item is valued and posted to the GL in real time (raw
     * material or finished good) rather than handled by the periodic
     * year-end trading entries. A row loaded before the column existed
     * (null) is a trading item.
     */
    public function isPerpetual(): bool
    {
        return $this->inventory_class?->isPerpetual() ?? false;
    }

    /**
     * Id of the balance-sheet account holding this item's perpetual value
     * (AS12 raw material / AS14 finished goods), null for a trading item.
     *
     * @throws \RuntimeException when the account is missing (tenant migrations not run)
     */
    public function inventoryAccountId(): ?int
    {
        $code = $this->inventory_class?->inventoryAccountCode();

        return $code === null ? null : ManufacturingAccounts::id($code);
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
     *
     * `$costValue` (manufacturing MC3) only matters for a perpetual item (raw
     * material / finished good), whose every movement must carry a
     * `cost_value`; it is always left null for a trading item:
     *
     * - in-direction: `$costValue`, else `$value` (a purchase's net value is
     *   its cost). Neither given throws, because an unpriced in-movement
     *   would add quantity without value and break the GL/subledger match.
     * - out-direction: `$costValue`, else PerpetualCosting::issueCost() as of
     *   `$date`. Lock the item first (Item::lockForStockOut()), so the
     *   balance being costed cannot change underneath.
     *
     * The created movement comes back with `cost_value` set (a 2-decimal
     * string), and the caller posts exactly that figure to the GL.
     *
     * @throws InvalidArgumentException for an unpriced perpetual in-movement, a negative cost, or no cost basis
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
        Money|string|null $costValue = null,
    ): ItemStockMovement {
        $quantity = Quantity::of($quantity);
        $value = $value === null ? null : Money::of($value);

        if ($unitCostRate === null && $value !== null && ! $quantity->isZero()) {
            $unitCostRate = Quantity::of(
                $value->toBigDecimal()->dividedBy($quantity->toBigDecimal(), 4, RoundingMode::HalfUp)
            );
        }

        $movement = $this->stockMovements()->make([
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

        // forceFill rather than the fillable list: it is the one column only
        // this method may set, and this method alone decides its value.
        $movement->forceFill([
            'cost_value' => $this->resolvedInventoryClass()->isPerpetual()
                ? $this->perpetualCostValue($type, $quantity, $date, $value, $costValue)->toString()
                : null,
        ]);

        $movement->save();

        return $movement;
    }

    /**
     * The cost_value of one perpetual movement, per recordStockMovement()'s
     * docblock.
     */
    private function perpetualCostValue(
        StockMovementType $type,
        Quantity $quantity,
        string $date,
        ?Money $value,
        Money|string|null $costValue,
    ): Money {
        if ($costValue !== null) {
            $cost = Money::of($costValue);
        } elseif ($type->direction() === 1) {
            $cost = $value ?? throw new InvalidArgumentException(
                "\"{$this->name}\" is a {$this->resolvedInventoryClass()->label()} item, so every stock-in must carry its cost. No cost was given for this {$type->value} movement."
            );
        } else {
            $cost = PerpetualCosting::issueCost($this, $quantity, $date);
        }

        if ($cost->isNegative()) {
            throw new InvalidArgumentException("The cost of a stock movement of \"{$this->name}\" cannot be negative.");
        }

        return $cost;
    }

    /**
     * This item's class, read from the database when the loaded row does not
     * carry the column (an Item::create() that left it to the column default,
     * or a query that selected a subset of columns). Treating a missing
     * attribute as Trading would write an unpriced movement for a perpetual
     * item and silently break invariant P1.
     */
    private function resolvedInventoryClass(): InventoryClass
    {
        if (! array_key_exists('inventory_class', $this->getAttributes()) && $this->exists) {
            // Raw attribute plus synced original, so the lookup never shows
            // up as a pending change on a later save().
            $this->attributes['inventory_class'] = static::query()->whereKey($this->getKey())->toBase()->value('inventory_class')
                ?? InventoryClass::Trading->value;
            $this->syncOriginalAttribute('inventory_class');
        }

        return $this->inventory_class ?? InventoryClass::Trading;
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
     * The least stock this item will have on any day from $from onwards: its
     * balance at the end of $from, then walked forward through every later
     * movement day by day (flags G-05). A back-dated sale can pass a check
     * against the stock on its own date and still drive a later day
     * negative, for example selling on the 1st goods that only arrived on
     * the 5th, when the 3rd already sold them. Checking this minimum is what
     * stops it.
     */
    public function lowestStockFrom(string $from, ?int $storeId = null): Quantity
    {
        $lowest = $running = $this->currentStock($storeId, $from);

        $inTypes = array_map(
            fn (StockMovementType $type) => $type->value,
            array_values(array_filter(StockMovementType::cases(), fn (StockMovementType $type) => $type->direction() === 1)),
        );
        $scaled = static::scaledQuantityExpression();
        $placeholders = implode(', ', array_fill(0, count($inTypes), '?'));

        $laterDays = ItemStockMovement::query()
            ->selectRaw(
                "DATE(date) as movement_day, SUM(CASE WHEN movement_type IN ({$placeholders}) THEN {$scaled} ELSE -{$scaled} END) as net_scaled",
                $inTypes,
            )
            ->where('item_id', $this->getKey())
            ->where('cancelled', false)
            ->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId))
            ->whereDate('date', '>', $from)
            ->groupByRaw('DATE(date)')
            ->orderByRaw('DATE(date)')
            ->pluck('net_scaled');

        foreach ($laterDays as $netScaled) {
            $running = $running->plus(static::quantityFromScaled($netScaled));

            if ($running->isLessThan($lowest)) {
                $lowest = $running;
            }
        }

        return $lowest;
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
     * Posting lock order (flags PUR-20, PUR-21), one order for every writer:
     *
     * 1. the supplier row (Purchase, Payment, unlinked PurchaseReturn,
     *    CapitalPurchase and its settlements);
     * 2. the document rows being settled, returned or cancelled, ascending id;
     * 3. every stockable item the document writes a line or stock movement
     *    for - stock in as well as stock out, and whether or not negative
     *    stock is allowed - through this method, ascending id;
     * 4. the fiscal year (JournalVoucher::post()/reverse()).
     *
     * A path skips the steps it has no rows for, never reorders them. Rows
     * reached only after the fiscal year (foreign-key shared locks on a
     * customer or a non-stockable item) are ones no posting locks before it.
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
