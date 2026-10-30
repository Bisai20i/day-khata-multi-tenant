<?php

namespace App\Support\Inventory;

use App\Enums\InventoryClass;
use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * The only place perpetual (raw material / finished good) cost is computed
 * (manufacturing MC3). Trading items never come here; they stay on the
 * periodic {@see StockCosting} basis.
 *
 * ## The ledger
 *
 * Every movement of a perpetual item carries `item_stock_movements.cost_value`:
 * the positive rupee cost it added to (in-direction) or removed from
 * (out-direction) the item's value, the sign coming from
 * {@see StockMovementType::direction()}. The item's **value balance** is the
 * signed sum of those figures over non-cancelled movements, and its
 * **quantity balance** the signed sum of the same movements' quantities.
 *
 * Invariant P1: per class, the value balance summed over items equals the GL
 * balance of AS12 / AS14. It holds because every posting writes the figure
 * this class returns into the movement *and* into the voucher - nothing ever
 * recomputes a posted cost.
 *
 * ## Moving weighted average, costed as of the movement's own date
 *
 * An out-movement is costed at value balance / quantity balance as of its
 * own date. A purchase entered later but dated earlier does not re-cost the
 * issues already posted after it; it only moves the average for issues
 * posted from then on. That is deliberate: re-costing a posted issue would
 * mean rewriting its voucher, and P1 only needs the GL and the subledger to
 * agree on the posted figure, which they do.
 *
 * ## Rounding
 *
 * Same discipline as StockCosting: the average is carried at
 * {@see self::AVERAGE_COST_SCALE} decimals and the multiplication back to
 * rupees is rounded once. Issuing the whole quantity balance takes the whole
 * value balance instead of quantity x average, so an item that is fully
 * consumed always ends at exactly 0.00 - three purchases at awkward rates
 * cannot leave a stray paisa sitting in AS12 forever.
 *
 * ## Exact sums
 *
 * Balances are summed in SQL as scaled integers (value x 100, quantity x
 * 10^4), for the reason Item::currentStockByItem() documents: SQLite gives
 * a `numeric` column REAL affinity, so a plain SUM() there is a float.
 */
class PerpetualCosting
{
    /**
     * Decimals the moving average is carried at internally.
     */
    private const AVERAGE_COST_SCALE = 12;

    /**
     * Moving weighted average cost per base unit as of $asOf, at 12 decimals.
     *
     * Null when there is nothing to average: the quantity balance is zero or
     * negative, no priced movement exists, or the value balance is negative
     * (which only corrupt data can produce, and which must never turn into a
     * negative issue cost). Callers wanting a cost anyway use issueCost(),
     * which applies the fallback chain.
     */
    public static function movingAverage(Item $item, string $asOf): ?BigDecimal
    {
        ['value' => $value, 'quantity' => $quantity] = static::balance($item, $asOf);

        return static::averageOf($value, $quantity);
    }

    /**
     * The cost of taking $quantity base units of $item out of stock on $date.
     *
     * Call it **after** the item row is locked (Item::lockForStockOut()), so
     * the balance it reads cannot change before the movement it prices is
     * written. On SQLite the lock is a no-op; this is verified by review.
     *
     * 1. $quantity equal to the whole quantity balance as of $date takes the
     *    whole value balance, so nothing is left behind.
     * 2. Otherwise `Money::round(quantity x movingAverage)`.
     * 3. With no moving average: the last priced in-movement's own rate
     *    (cost_value / quantity), then `items.purchase_rate` when it is
     *    positive, else an InvalidArgumentException. A zero purchase rate is
     *    not treated as a cost basis: costing a real issue at nothing would
     *    silently understate WIP and COGS.
     *
     * @throws InvalidArgumentException when $quantity is not positive or no cost basis exists
     */
    public static function issueCost(Item $item, Quantity $quantity, string $date): Money
    {
        if (! $quantity->isPositive()) {
            throw new InvalidArgumentException("The quantity issued of \"{$item->name}\" must be greater than zero.");
        }

        ['value' => $value, 'quantity' => $balanceQuantity] = static::balance($item, $date);

        if ($quantity->isEqualTo($balanceQuantity) && ! $value->isNegative()) {
            return $value;
        }

        $rate = static::averageOf($value, $balanceQuantity)
            ?? static::lastInRate($item, $date)
            ?? static::purchaseRate($item);

        if ($rate === null) {
            throw new InvalidArgumentException("There is no cost basis for \"{$item->name}\": it has no priced stock and no purchase rate. Enter a purchase rate on the item first.");
        }

        return Money::round($quantity->toBigDecimal()->multipliedBy($rate));
    }

    /**
     * The item's perpetual value as of $asOf (all dates when null), for one
     * store when $storeId is given. Store values of one item add up to its
     * all-stores value: a transfer moves the same cost_value out of one
     * store and into the other.
     */
    public static function valueBalance(Item $item, ?string $asOf = null, ?int $storeId = null): Money
    {
        return static::valueBalanceByItem([$item->getKey()], $asOf, $storeId)[$item->getKey()] ?? Money::zero();
    }

    /**
     * Value balance for many items in one query, keyed by item id. Every id
     * asked for comes back, those with no priced movement as an explicit
     * zero. $storeId is an optional extension of the MC3 signature.
     *
     * @param  array<int, int|string>  $itemIds
     * @return array<int, Money>
     */
    public static function valueBalanceByItem(array $itemIds, ?string $asOf = null, ?int $storeId = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $itemIds)));

        if ($ids === []) {
            return [];
        }

        $balances = array_fill_keys($ids, Money::zero());

        foreach (static::balanceRows(fn (Builder $query) => $query->whereIn('item_id', $ids), $asOf, $storeId) as $row) {
            $balances[(int) $row->item_id] = static::moneyFromScaled($row->value_scaled);
        }

        return $balances;
    }

    /**
     * Subledger total of one class as of $asOf: the figure the year-end
     * reconciliation compares with the AS12 / AS14 GL balance. Trading items
     * never carry cost_value, so the Trading class is always zero here; its
     * stock is valued by StockCosting.
     */
    public static function classValue(InventoryClass $class, string $asOf): Money
    {
        $rows = static::balanceRows(
            fn (Builder $query) => $query->whereIn(
                'item_id',
                Item::query()->select('id')->where('inventory_class', $class->value),
            ),
            $asOf,
            null,
        );

        return Money::sum($rows->map(fn (ItemStockMovement $row) => static::moneyFromScaled($row->value_scaled)));
    }

    /**
     * Value and quantity balance of one item, over its priced movements only.
     *
     * Only movements with a cost_value are counted on either side. For a
     * perpetual item that is every movement (Item::recordStockMovement()
     * refuses to write one without it, and the class cannot change once a
     * movement exists), so this is the item's real quantity; restricting it
     * anyway keeps a stray unpriced row from distorting the average.
     *
     * @return array{value: Money, quantity: Quantity}
     */
    private static function balance(Item $item, string $asOf): array
    {
        $row = static::balanceRows(fn (Builder $query) => $query->where('item_id', $item->getKey()), $asOf, null)->first();

        if ($row === null) {
            return ['value' => Money::zero(), 'quantity' => Quantity::zero()];
        }

        return [
            'value' => static::moneyFromScaled($row->value_scaled),
            'quantity' => Quantity::of(BigDecimal::of((int) $row->quantity_scaled)->dividedBy(10000, 4, RoundingMode::Unnecessary)),
        ];
    }

    /**
     * Signed scaled-integer sums of cost_value and quantity per item.
     *
     * @param  callable(Builder<ItemStockMovement>): mixed  $scope
     * @return Collection<int, ItemStockMovement>
     */
    private static function balanceRows(callable $scope, ?string $asOf, ?int $storeId): Collection
    {
        $cast = (new ItemStockMovement)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';
        $scaledValue = "CAST(ROUND(cost_value * 100) AS {$cast})";
        $scaledQuantity = "CAST(ROUND(quantity * 10000) AS {$cast})";

        $inTypes = static::inTypes();
        $placeholders = implode(', ', array_fill(0, count($inTypes), '?'));

        $query = ItemStockMovement::query()
            ->selectRaw(
                "item_id,
                 SUM(CASE WHEN movement_type IN ({$placeholders}) THEN {$scaledValue} ELSE -{$scaledValue} END) as value_scaled,
                 SUM(CASE WHEN movement_type IN ({$placeholders}) THEN {$scaledQuantity} ELSE -{$scaledQuantity} END) as quantity_scaled",
                [...$inTypes, ...$inTypes],
            )
            ->where('cancelled', false)
            ->whereNotNull('cost_value')
            ->when($asOf !== null, fn (Builder $q) => $q->whereDate('date', '<=', $asOf))
            ->when($storeId !== null, fn (Builder $q) => $q->where('store_id', $storeId))
            ->groupBy('item_id');

        $scope($query);

        return $query->get();
    }

    /**
     * value / quantity at 12 decimals, or null when there is no usable
     * average (see movingAverage()).
     */
    private static function averageOf(Money $value, Quantity $quantity): ?BigDecimal
    {
        if (! $quantity->isPositive() || $value->isNegative()) {
            return null;
        }

        return $value->toBigDecimal()->dividedBy($quantity->toBigDecimal(), self::AVERAGE_COST_SCALE, RoundingMode::HalfUp);
    }

    /**
     * Rate of the most recent priced in-movement (cost_value / quantity), the
     * first fallback. One dated on or before $date is preferred; failing
     * that, any priced in-movement at all, because a real cost from the
     * item's own history is a better basis than the catalog rate.
     */
    private static function lastInRate(Item $item, string $date): ?BigDecimal
    {
        $cast = (new ItemStockMovement)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        // Read back as scaled integers, not the raw columns: on SQLite a
        // decimal column comes back as a REAL.
        $latest = fn (bool $onOrBefore) => ItemStockMovement::query()
            ->selectRaw("id, CAST(ROUND(cost_value * 100) AS {$cast}) as value_scaled, CAST(ROUND(quantity * 10000) AS {$cast}) as quantity_scaled")
            ->where('item_id', $item->getKey())
            ->where('cancelled', false)
            ->whereNotNull('cost_value')
            ->whereIn('movement_type', static::inTypes())
            ->where('quantity', '>', 0)
            ->when($onOrBefore, fn (Builder $q) => $q->whereDate('date', '<=', $date))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        $movement = $latest(true) ?? $latest(false);

        if ($movement === null || (int) $movement->quantity_scaled <= 0) {
            return null;
        }

        return BigDecimal::of((int) $movement->value_scaled)
            ->multipliedBy(100)
            ->dividedBy((int) $movement->quantity_scaled, self::AVERAGE_COST_SCALE, RoundingMode::HalfUp);
    }

    /**
     * `items.purchase_rate` (a per-base-unit rate) when it is positive, the
     * last fallback.
     */
    private static function purchaseRate(Item $item): ?BigDecimal
    {
        if ($item->purchase_rate === null) {
            return null;
        }

        $rate = Quantity::of($item->purchase_rate);

        return $rate->isPositive() ? $rate->toBigDecimal() : null;
    }

    private static function moneyFromScaled(mixed $scaled): Money
    {
        return Money::of(BigDecimal::of((int) $scaled)->dividedBy(100, 2, RoundingMode::Unnecessary));
    }

    /**
     * Every stock-increasing movement type. Unlike StockCosting's periodic
     * basis, transfers are included: a TransferIn adds back exactly the
     * cost_value its TransferOut removed, so the item total nets to zero
     * while each store's value follows its goods.
     *
     * @return list<string>
     */
    private static function inTypes(): array
    {
        return array_values(array_map(
            fn (StockMovementType $type) => $type->value,
            array_filter(StockMovementType::cases(), fn (StockMovementType $type) => $type->direction() === 1),
        ));
    }
}
