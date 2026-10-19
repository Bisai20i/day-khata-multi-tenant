<?php

namespace App\Support\Inventory;

use App\Models\ActivityLog;
use App\Models\Item;
use App\Models\ItemUnit;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Support\Money\InvalidAmount;
use App\Support\Money\Quantity;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Applies the "Yes, update saved price" answers a clerk gave on the purchase
 * form (see resources/js/composables/usePurchasePriceConfirm.js) to the item
 * master, once the bill itself has posted.
 *
 * The saved purchase price lives in two places: `items.purchase_rate` for the
 * base unit (conversion factor 1) and `item_units.purchase_rate` for each
 * alternate unit. A Yes on any unit sets that unit to the rate typed on the
 * bill and rescales every other active unit by its conversion factor, rounded
 * once HalfUp to 2 decimals, so the base rate StockCosting falls back on and
 * the per-unit prefills stay consistent with each other. A chosen unit with
 * no usable conversion factor (null or <= 0) updates only itself.
 *
 * Rescaling is all or nothing per item: when any rescaled unit would round
 * to zero or overflow the column, none of them is written and only the
 * chosen unit takes the typed rate, so the units are never left rescaled
 * from two different prices. The activity log row records that
 * (`rescale_skipped`), and the browser's preview
 * (resources/js/lib/purchasePriceUpdate.js) shows the same outcome before
 * the clerk answers.
 *
 * Must be called inside the same DB transaction as Purchase::post() (see
 * PurchaseController::store()), so a bill that is rejected, fails or rolls
 * back never changes a price. Only the master columns are written:
 * purchase_lines and item_stock_movements keep the rates they were posted at.
 *
 * Lock order: Purchase::post() locks the supplier, then the bill's
 * stockable items (ascending id), then the fiscal year and the voucher
 * sequence - the posting lock order documented on Item::lockForStockOut().
 * The answered items are locked again here in one ascending-id query through
 * Item::lockForStockOut() - a harmless re-lock of rows Purchase::post()
 * already holds, kept so this class stays safe to call on its own - and each
 * item's unit rows only after that. A non-stockable item is one no posting
 * locks before the fiscal year, so taking it here, after the fiscal year,
 * matches every other path.
 *
 * Tenant isolation: tenancy here is one database per tenant
 * (DatabaseTenancyBootstrapper), and every read and write below goes through
 * the tenant connection Eloquent is bound to for this request. An answer is
 * also only honoured when this bill, just saved in that same database, has a
 * line of that item, in that unit, at that rate - so a crafted request can
 * neither reach another tenant's rows nor rewrite an arbitrary item's price.
 */
final class ItemPurchasePriceUpdater
{
    /** The largest value items.purchase_rate / item_units.purchase_rate (DECIMAL(15,4)) hold. */
    public const MAX_RATE = '99999999999.9999';

    /**
     * @param  mixed  $answers  the request's `price_updates`: list of {item_id, item_unit_id, rate}
     * @return int number of saved prices (unit rows) actually changed
     */
    public function apply(Purchase $purchase, mixed $answers): int
    {
        if (! is_array($answers) || $answers === []) {
            return 0;
        }

        $purchase->loadMissing('lines');

        // Every answer is fully validated first and only then deduplicated, so
        // a later malformed or off-bill answer for an item can never cancel an
        // earlier valid one. One change per item: every unit is rescaled from
        // the chosen one, so a later valid Yes on any unit replaces the earlier.
        // The shape and on-bill checks cost no query; the "still active" check
        // does, so it runs once per item and unit however often that pair is
        // repeated (up to 500 answers are accepted, all while the fiscal year
        // is locked).
        /** @var array<int, array{item_id: int, item_unit_id: int|null, rate: Quantity}> $byItem */
        $byItem = [];
        /** @var array<string, bool> $active */
        $active = [];

        foreach ($answers as $answer) {
            $valid = $this->validatedAnswer($purchase, $answer);

            if ($valid === null) {
                continue;
            }

            $key = $valid['item_id'].'|'.($valid['item_unit_id'] ?? '');
            $active[$key] ??= $this->isActive($valid['item_id'], $valid['item_unit_id']);

            if ($active[$key]) {
                $byItem[$valid['item_id']] = $valid;
            }
        }

        if ($byItem === []) {
            return 0;
        }

        // Ascending item id whatever order the answers came in (see the class
        // docblock): every item row in one locking query, then each item's
        // units in that same order.
        ksort($byItem);
        $items = Item::lockForStockOut(array_keys($byItem))->keyBy('id');

        $changed = 0;

        foreach ($byItem as $itemId => $answer) {
            $item = $items->get($itemId);

            if ($item !== null) {
                $changed += $this->applyToItem($purchase, $item, $answer['item_unit_id'], $answer['rate']);
            }
        }

        return $changed;
    }

    /**
     * The answer's shape and its match against the bill's lines; no query.
     *
     * @return array{item_id: int, item_unit_id: int|null, rate: Quantity}|null
     */
    private function validatedAnswer(Purchase $purchase, mixed $answer): ?array
    {
        if (! is_array($answer)) {
            return null;
        }

        $itemId = $this->positiveId($answer['item_id'] ?? null);
        $rawUnitId = $answer['item_unit_id'] ?? null;
        $unitId = $rawUnitId === null || $rawUnitId === '' ? null : $this->positiveId($rawUnitId);
        $rate = $this->validRate($answer['rate'] ?? null);

        if ($itemId === null || ($rawUnitId !== null && $rawUnitId !== '' && $unitId === null) || $rate === null) {
            return null;
        }

        if (! $this->isOnBill($purchase, $itemId, $unitId, $rate)) {
            return null;
        }

        return ['item_id' => $itemId, 'item_unit_id' => $unitId, 'rate' => $rate];
    }

    /** The item is active and, when given, the unit is an active unit of that item. */
    private function isActive(int $itemId, ?int $unitId): bool
    {
        return Item::query()->whereKey($itemId)->where('is_active', true)->exists()
            && ($unitId === null || ItemUnit::query()
                ->whereKey($unitId)
                ->where('item_id', $itemId)
                ->where('is_active', true)
                ->exists());
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * A rate the master columns can hold: a plain decimal of at most 4
     * places, above zero and no larger than DECIMAL(15,4) allows.
     */
    private function validRate(mixed $value): ?Quantity
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        try {
            $rate = Quantity::of(is_string($value) ? trim($value) : $value);
        } catch (InvalidAmount) {
            return null;
        }

        if (! $rate->isPositive() || $rate->isGreaterThan(self::MAX_RATE)) {
            return null;
        }

        return $rate;
    }

    /**
     * Exact 4dp comparison: the browser sends the line's own rate in its
     * canonical 4dp form ("10" as "10.0000"), and purchase_lines.rate stores
     * that same value, so the two are equal without any rounding on either side.
     */
    private function isOnBill(Purchase $purchase, int $itemId, ?int $unitId, Quantity $rate): bool
    {
        return $purchase->lines->contains(fn (PurchaseLine $line): bool => (int) $line->item_id === $itemId
            && ($line->item_unit_id === null ? null : (int) $line->item_unit_id) === $unitId
            && Quantity::of($line->rate)->isEqualTo($rate));
    }

    /**
     * @param  Item  $item  locked by apply() for the rest of the transaction, so
     *                      two bills saved at the same moment apply their answers one after the other
     */
    private function applyToItem(Purchase $purchase, Item $item, ?int $chosenUnitId, Quantity $rate): int
    {
        $units = ItemUnit::query()
            ->where('item_id', $item->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $chosenUnit = $chosenUnitId === null ? null : $units->firstWhere('id', $chosenUnitId);

        // The chosen unit was deactivated after apply() checked it.
        if ($chosenUnitId !== null && $chosenUnit === null) {
            return 0;
        }

        $chosenFactor = $chosenUnit === null ? Quantity::of(1) : Quantity::ofNullable($chosenUnit->conversion_factor);

        $rows = [['model' => $item, 'unit_id' => null, 'name' => (string) $item->unit, 'factor' => Quantity::of(1)]];

        foreach ($units as $unit) {
            $rows[] = ['model' => $unit, 'unit_id' => $unit->id, 'name' => (string) $unit->name, 'factor' => Quantity::ofNullable($unit->conversion_factor)];
        }

        [$newRates, $rescaleSkipped] = $this->plannedRates($rows, $chosenUnitId, $chosenFactor, $rate);

        /** @var list<array{unit: string, item_unit_id: int|null, old: string|null, new: string}> $changes */
        $changes = [];

        foreach ($newRates as $index => $newRate) {
            $row = $rows[$index];
            $oldRate = Quantity::ofNullable($row['model']->purchase_rate);

            if ($oldRate !== null && $oldRate->isEqualTo($newRate)) {
                continue;
            }

            $row['model']->purchase_rate = $newRate->toString();
            $row['model']->save();

            $changes[] = [
                'unit' => $row['name'],
                'item_unit_id' => $row['unit_id'],
                'old' => $oldRate?->toString(),
                'new' => $newRate->toString(),
            ];
        }

        if ($changes !== []) {
            $this->log($purchase, $item, $changes, $rescaleSkipped);
        }

        return count($changes);
    }

    /**
     * The new rate of every row that is to change, keyed by its index in
     * $rows: the chosen unit always, plus every other unit with a usable
     * factor rescaled from it - unless any one of those rescaled values is
     * not above zero or does not fit the column, in which case no unit is
     * rescaled at all (see the class docblock). A chosen factor that is null
     * or not above zero rescales nothing, exactly as the browser's
     * rescalePlan() previews it.
     *
     * @param  list<array{model: Item|ItemUnit, unit_id: int|null, name: string, factor: Quantity|null}>  $rows
     * @return array{0: array<int, Quantity>, 1: bool} the rates, and whether the rescale was skipped
     */
    private function plannedRates(array $rows, ?int $chosenUnitId, ?Quantity $chosenFactor, Quantity $rate): array
    {
        $chosen = [];
        $rescaled = [];
        $rescaleSkipped = false;

        foreach ($rows as $index => $row) {
            if ($row['unit_id'] === $chosenUnitId) {
                $chosen[$index] = $rate;

                continue;
            }

            if ($chosenFactor === null || ! $chosenFactor->isPositive() || $row['factor'] === null || ! $row['factor']->isPositive()) {
                continue;
            }

            $newRate = Quantity::of(
                $rate->toBigDecimal()
                    ->multipliedBy($row['factor']->toBigDecimal())
                    ->dividedBy($chosenFactor->toBigDecimal(), 2, RoundingMode::HalfUp)
            );

            if (! $newRate->isPositive() || $newRate->isGreaterThan(self::MAX_RATE)) {
                $rescaleSkipped = true;
            }

            $rescaled[$index] = $newRate;
        }

        $planned = $rescaleSkipped ? $chosen : $chosen + $rescaled;
        // Back in unit order (base unit first) for the activity log.
        ksort($planned);

        return [$planned, $rescaleSkipped];
    }

    /**
     * One audit row per item, in the tenant's existing activity log - the
     * same table and conventions ActivityLogObserver and
     * ClosedFiscalYearGuard::logCorrection() write to.
     *
     * @param  list<array{unit: string, item_unit_id: int|null, old: string|null, new: string}>  $changes
     * @param  bool  $rescaleSkipped  the other units were left alone because a rescaled price was out of range
     */
    private function log(Purchase $purchase, Item $item, array $changes, bool $rescaleSkipped): void
    {
        $summary = collect($changes)
            ->map(fn (array $change): string => sprintf(
                '%s %s -> %s',
                $change['unit'],
                $change['old'] === null ? 'not set' : Quantity::of($change['old'])->formatRate(),
                Quantity::of($change['new'])->formatRate(),
            ))
            ->implode(', ')
            .($rescaleSkipped ? ' (other units unchanged: their rescaled price was out of range)' : '');

        ActivityLog::create([
            // Explicit 'web' guard, matching ActivityLogObserver::write().
            'user_id' => Auth::guard('web')->id(),
            'action' => 'price_updated',
            'subject_type' => $item->getMorphClass(),
            'subject_id' => $item->getKey(),
            'description' => Str::limit("Saved purchase price of \"{$item->name}\" updated by purchase {$purchase->purchase_number}: {$summary}", 250),
            'changes' => [
                'purchase_id' => $purchase->id,
                'purchase_number' => $purchase->purchase_number,
                'units' => $changes,
                'rescale_skipped' => $rescaleSkipped,
            ],
        ]);
    }
}
