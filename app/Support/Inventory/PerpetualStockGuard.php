<?php

namespace App\Support\Inventory;

use App\Enums\InventoryClass;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Support\Money\Quantity;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The strict stock rule for perpetual (raw material and finished good)
 * items, shared by every stock-out path outside manufacturing.
 *
 * A perpetual item's moving average is value / quantity on hand, which is
 * meaningless at zero or below, so these items **always** enforce stock and
 * ignore `company_settings.allow_negative_stock` - exactly the rule
 * MaterialIssueNote::post() applies. Trading items keep honouring the
 * setting; every method here skips them, so a caller can hand over all of a
 * document's items and the trading behaviour stays untouched.
 *
 * The check is store-scoped, aggregated per item, and uses
 * Item::lowestStockFrom(): the least balance on any day from the document
 * date onwards, so a backdated stock-out cannot reach before the first
 * receipt or drive a later day negative either.
 *
 * Callers lock the items first (Item::lockForStockOut(), ascending id, the
 * app-wide posting order) and pass the locked rows in; nothing here takes a
 * lock, so it can never break that order by locking a subset out of turn.
 */
final class PerpetualStockGuard
{
    /**
     * The perpetual items among $itemIds, ascending. An unlocked read, used
     * only to decide which rows to lock when the tenant allows negative stock
     * (a trading-only document then takes no new lock); the locked rows are
     * re-checked with Item::isPerpetual() before anything is enforced.
     *
     * @param  array<int, int|string>  $itemIds
     * @return list<int>
     */
    public static function perpetualItemIds(array $itemIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $itemIds)));

        if ($ids === []) {
            return [];
        }

        return Item::query()
            ->whereIn('id', $ids)
            ->whereIn('inventory_class', [InventoryClass::RawMaterial->value, InventoryClass::FinishedGood->value])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Refuses a stock-out of a perpetual item beyond what the store holds
     * from $date onwards. Trading items in $required are ignored.
     *
     * @param  Collection<int, Item>|array<int, Item>  $items  the locked items, keyed by id
     * @param  array<int, Quantity>  $required  base quantity leaving the store, already aggregated per item
     */
    public static function assertCanTakeOut(Collection|array $items, array $required, int $storeId, string $date, string $context = ''): void
    {
        foreach ($required as $itemId => $quantity) {
            $item = $items[$itemId] ?? null;

            if ($item === null || ! $item->isPerpetual()) {
                continue;
            }

            self::assertQuantityAvailable($item, $quantity, $storeId, $date, $context);
        }
    }

    /**
     * Refuses to cancel in-direction movements (a purchase, a sales return,
     * an adjustment in, a transfer's receiving leg) of a perpetual item when
     * the stock they brought in has already gone out again. Cancelling one
     * removes its quantity from its date onwards, so the store must still
     * hold at least that much on every day from then. Grouped per item and
     * store; out-direction and trading movements are ignored.
     *
     * @param  Collection<int, ItemStockMovement>  $movements  the movements about to be cancelled
     * @param  Collection<int, Item>|array<int, Item>  $items  the locked items, keyed by id
     */
    public static function assertCanRemove(Collection $movements, Collection|array $items, string $context = ''): void
    {
        $incoming = $movements->filter(function (ItemStockMovement $movement) use ($items): bool {
            $item = $items[$movement->item_id] ?? null;

            return $item !== null && $item->isPerpetual() && $movement->movement_type->direction() === 1;
        });

        foreach ($incoming->groupBy(fn (ItemStockMovement $movement): string => $movement->item_id.'|'.$movement->store_id) as $group) {
            /** @var Collection<int, ItemStockMovement> $group */
            $first = $group->first();
            $removing = Quantity::sum($group->map(
                fn (ItemStockMovement $movement): Quantity => Quantity::of($movement->quantity)
            ));
            $from = $group->map(fn (ItemStockMovement $movement): string => $movement->date->toDateString())->min();

            self::assertQuantityAvailable($items[$first->item_id], $removing, (int) $first->store_id, $from, $context);
        }
    }

    /**
     * The single place the shortfall message is worded, so every document
     * names the item, the store-level balance and the exact shortfall.
     */
    private static function assertQuantityAvailable(Item $item, Quantity $required, int $storeId, string $date, string $context): void
    {
        $have = $item->lowestStockFrom($date, $storeId);

        if (! $have->isLessThan($required)) {
            return;
        }

        $shortfall = $required->minus($have);
        $prefix = $context === '' ? '' : rtrim($context, ' :').': ';

        throw new InvalidArgumentException(
            "{$prefix}Insufficient stock for \"{$item->name}\" at this store "
            ."(available {$have->formatQuantity()}, required {$required->formatQuantity()}, short by {$shortfall->formatQuantity()}). "
            .'Raw material and finished goods can never go below zero stock.'
        );
    }
}
