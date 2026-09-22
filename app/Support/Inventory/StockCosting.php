<?php

namespace App\Support\Inventory;

use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one place stock is valued (CONTRACTS C10, audit P0-17).
 *
 * ## The policy, chosen by the user on 2026-09-11
 *
 * **Weighted average cost, on a fixed basis.** An item's cost per base unit
 * is the total net value of everything bought into stock divided by the
 * total base quantity bought in, up to the valuation date. Closing stock is
 * that cost multiplied by the quantity actually on hand. There is no FIFO
 * layering and no per-store cost: a business running one item across three
 * stores values it identically in all three, which is what the legacy system
 * and the tenants' accountants already expect.
 *
 * ## What is in the basis, and why
 *
 * - **In-movements with a recorded `value`.** Purchases (net of line and
 *   header discount, VAT excluded), opening stock, priced adjustments-in and
 *   priced conversion outputs. A movement with a null `value` carries no
 *   cost information and contributes neither value nor quantity, which is
 *   different from contributing a zero (a zero would drag the average down
 *   and value real stock at nothing).
 * - **Purchase returns subtract**, value and quantity both. Goods sent back
 *   to the supplier were never part of the cost of what is still on the
 *   shelf.
 * - **Transfers are excluded entirely.** Audit P0-17 found the all-stores
 *   valuation double counting transfer-in rows: moving 10 units from the
 *   main store to the branch created a TransferIn that looked like another
 *   10 units of purchased stock. Relocating your own goods does not change
 *   what they cost.
 * - **Sales and sale returns are not in the basis.** They are valued at
 *   selling price, not cost, so including them would value stock at margin.
 * - **Cancelled movements are excluded**, the same as everywhere else.
 *
 * ## Rounding
 *
 * The audit's worked example: 100,000 units at Rs 1 plus 200,000 at Rs 2 is
 * Rs 500,000 of stock, but rounding the average (1.666666...) to 4 decimals
 * first and multiplying afterwards reported Rs 500,010.00. So the average is
 * carried at {@see self::AVERAGE_COST_SCALE} decimals and the multiplication
 * back to rupees is rounded exactly once.
 *
 * ## No basis at all
 *
 * `averageCost()` returns null when nothing priced has ever come in - a
 * brand new item, or one whose only stock came from an unpriced adjustment.
 * Callers fall back to `items.purchase_rate`, which is a per-base-unit rate,
 * and this class's own `closingValue()`/`valuationRows()` do exactly that.
 */
class StockCosting
{
    /**
     * Decimals the weighted average is carried at internally. Well beyond
     * the 4 the UI shows, so that one rounding at the end lands on the exact
     * rupee value rather than on a value pre-distorted by a rounded rate.
     */
    private const AVERAGE_COST_SCALE = 12;

    /**
     * Weighted average cost per base unit as of $asOf, or null when this item
     * has no priced stock-in history to average.
     */
    public static function averageCost(Item $item, string $asOf): ?BigDecimal
    {
        ['value' => $value, 'quantity' => $quantity] = static::basis($item->getKey(), $asOf);

        if ($quantity->isZero()) {
            return null;
        }

        return $value->toBigDecimal()->dividedBy(
            $quantity->toBigDecimal(),
            self::AVERAGE_COST_SCALE,
            RoundingMode::HalfUp,
        );
    }

    /**
     * Value of the stock on hand as of $asOf, optionally for one store only.
     *
     * The quantity is store-scoped but the cost never is (see the class
     * docblock), so the per-store values of one item always add up to its
     * all-stores value. A negative on-hand quantity gives a negative value
     * rather than being clamped to zero: negative stock is a data problem,
     * and hiding it in the valuation is how it stays hidden.
     */
    public static function closingValue(Item $item, string $asOf, ?int $storeId = null): Money
    {
        $quantity = $item->currentStock($storeId, $asOf);
        $cost = static::costPerBaseUnit($item, $asOf);

        if ($quantity->isZero() || $cost->isZero()) {
            return Money::zero();
        }

        return Money::round($quantity->toBigDecimal()->multipliedBy($cost));
    }

    /**
     * Total closing stock value across every stockable item - the single
     * figure the Balance Sheet's "Stock in Hand" and the year-end closing
     * entry need.
     */
    public static function totalClosingValue(string $asOf, ?int $storeId = null): Money
    {
        return Money::sum(
            static::valuationRows($asOf, $storeId)->map(fn (array $row) => $row['value'])
        );
    }

    /**
     * One row per stockable item: `item_id`, `quantity` (Quantity),
     * `average_cost` (a 4-decimal display string) and `value` (Money).
     *
     * Items that have never moved and hold nothing are skipped, so a catalog
     * of 5,000 items does not produce 5,000 zero rows. Supported $filters:
     * `item_category_id`, `item_subcategory_id`, `brand_id`, `search` (item
     * name), `include_empty` (true to keep the zero rows), and `stock_status`
     * (`'positive'`, `'negative'` or `'all'`, default `'all'`).
     *
     * `stock_status` mirrors legacy's `stockValuationReport()` filter, which
     * real users relied on to hunt down negative-stock data-entry errors -
     * the sign of the on-hand quantity, checked with `Quantity::isPositive()`
     * / `isNegative()` rather than a numeric comparison, since a `Quantity`
     * is never cast to float for comparison anywhere in this app. `'all'` is
     * the default and applies no sign filter at all, so callers that never
     * pass `stock_status` (the category/brand/inventory reports) see exactly
     * the rows they always have - only `StockValuationReportController`
     * threads the request param through.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{item_id: int, name: string, unit: string, hs_code: ?string, quantity: Quantity, average_cost: string, value: Money}>
     */
    public static function valuationRows(string $asOf, ?int $storeId = null, array $filters = []): Collection
    {
        $items = Item::query()
            ->where('is_stockable', true)
            ->when(($filters['item_category_id'] ?? null) !== null, fn (Builder $q) => $q->where('item_category_id', $filters['item_category_id']))
            ->when(($filters['item_subcategory_id'] ?? null) !== null, fn (Builder $q) => $q->where('item_subcategory_id', $filters['item_subcategory_id']))
            ->when(($filters['brand_id'] ?? null) !== null, fn (Builder $q) => $q->where('brand_id', $filters['brand_id']))
            ->when(($filters['search'] ?? null) !== null, fn (Builder $q) => $q->where('name', 'like', '%'.$filters['search'].'%'))
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'purchase_rate', 'hs_code']);

        if ($items->isEmpty()) {
            return new Collection;
        }

        $includeEmpty = (bool) ($filters['include_empty'] ?? false);
        $stockStatus = $filters['stock_status'] ?? 'all';
        $quantities = Item::currentStockByItem($items->modelKeys(), $storeId, $asOf);
        $bases = static::basisByItem($items->modelKeys(), $asOf);

        return $items->map(function (Item $item) use ($quantities, $bases): array {
            $quantity = $quantities[$item->getKey()] ?? Quantity::zero();
            $basis = $bases[$item->getKey()] ?? null;

            $cost = $basis !== null && ! $basis['quantity']->isZero()
                ? $basis['value']->toBigDecimal()->dividedBy($basis['quantity']->toBigDecimal(), self::AVERAGE_COST_SCALE, RoundingMode::HalfUp)
                : static::fallbackCost($item);

            $value = $quantity->isZero() || $cost->isZero()
                ? Money::zero()
                : Money::round($quantity->toBigDecimal()->multipliedBy($cost));

            return [
                'item_id' => $item->getKey(),
                'name' => $item->name,
                'unit' => $item->unit,
                'hs_code' => $item->hs_code,
                'quantity' => $quantity,
                // 4 decimals is what the rate columns show everywhere else;
                // the full-precision figure only ever exists inside $value.
                'average_cost' => Quantity::round($cost)->toString(),
                'value' => $value,
            ];
        })
            ->filter(fn (array $row) => $includeEmpty || ! $row['quantity']->isZero() || ! $row['value']->isZero())
            ->when($stockStatus === 'positive', fn (Collection $rows) => $rows->filter(fn (array $row) => $row['quantity']->isPositive()))
            ->when($stockStatus === 'negative', fn (Collection $rows) => $rows->filter(fn (array $row) => $row['quantity']->isNegative()))
            ->values();
    }

    /**
     * The cost this item is valued at: its weighted average, or - when it has
     * no priced history - the catalog purchase rate, which is also a
     * per-base-unit figure. Zero when it has neither.
     */
    private static function costPerBaseUnit(Item $item, string $asOf): BigDecimal
    {
        return static::averageCost($item, $asOf) ?? static::fallbackCost($item);
    }

    private static function fallbackCost(Item $item): BigDecimal
    {
        return $item->purchase_rate === null
            ? BigDecimal::zero()
            : Quantity::of($item->purchase_rate)->toBigDecimal();
    }

    /**
     * Cost basis for one item.
     *
     * @return array{value: Money, quantity: Quantity}
     */
    private static function basis(int $itemId, string $asOf): array
    {
        return static::basisByItem([$itemId], $asOf)[$itemId] ?? [
            'value' => Money::zero(),
            'quantity' => Quantity::zero(),
        ];
    }

    /**
     * Cost basis for many items in one query, keyed by item id.
     *
     * Value and quantity are summed as scaled integers for the same reason
     * Item::currentStockByItem() does it (SQLite gives a `numeric` column
     * REAL affinity, so a plain SUM() there is a float); on MySQL the columns
     * are true DECIMALs and the cast changes nothing. Only movements with a
     * non-null `value` are in scope, so an unpriced movement contributes
     * neither side of the average.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, array{value: Money, quantity: Quantity}>
     */
    private static function basisByItem(array $itemIds, string $asOf): array
    {
        if ($itemIds === []) {
            return [];
        }

        $cast = (new ItemStockMovement)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';
        $scaledValue = "CAST(ROUND(value * 100) AS {$cast})";
        $scaledQuantity = "CAST(ROUND(quantity * 10000) AS {$cast})";

        $inTypes = static::basisInTypes();
        $outTypes = static::basisOutTypes();
        $inPlaceholders = implode(', ', array_fill(0, count($inTypes), '?'));

        $rows = ItemStockMovement::query()
            ->selectRaw(
                "item_id,
                 SUM(CASE WHEN movement_type IN ({$inPlaceholders}) THEN {$scaledValue} ELSE -{$scaledValue} END) as basis_value,
                 SUM(CASE WHEN movement_type IN ({$inPlaceholders}) THEN {$scaledQuantity} ELSE -{$scaledQuantity} END) as basis_quantity",
                [...$inTypes, ...$inTypes],
            )
            ->whereIn('item_id', $itemIds)
            ->where('cancelled', false)
            ->whereNotNull('value')
            ->whereIn('movement_type', [...$inTypes, ...$outTypes])
            ->whereDate('date', '<=', $asOf)
            ->groupBy('item_id')
            ->get();

        $bases = [];

        foreach ($rows as $row) {
            $bases[(int) $row->item_id] = [
                'value' => Money::of(BigDecimal::of((int) $row->basis_value)->dividedBy(100, 2, RoundingMode::Unnecessary)),
                'quantity' => Quantity::of(BigDecimal::of((int) $row->basis_quantity)->dividedBy(10000, 4, RoundingMode::Unnecessary)),
            ];
        }

        return $bases;
    }

    /**
     * Stock-increasing movement types that count toward the cost basis:
     * every in-direction type except TransferIn (see the class docblock).
     * SaleReturn is in the list because a return of goods to stock can
     * legitimately be priced at cost by the returning module; when it is
     * not, its null `value` keeps it out anyway.
     *
     * @return list<string>
     */
    private static function basisInTypes(): array
    {
        return array_values(array_map(
            fn (StockMovementType $type) => $type->value,
            array_filter(
                StockMovementType::cases(),
                fn (StockMovementType $type) => $type->direction() === 1 && $type !== StockMovementType::TransferIn,
            ),
        ));
    }

    /**
     * Stock-decreasing movement types that subtract from the cost basis.
     * Only purchase returns: a sale removes stock at selling price and a
     * transfer-out is the other half of a relocation, neither of which
     * changes what the remaining stock cost.
     *
     * @return list<string>
     */
    private static function basisOutTypes(): array
    {
        return [StockMovementType::PurchaseReturn->value];
    }
}
