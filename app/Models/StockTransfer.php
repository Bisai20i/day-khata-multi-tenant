<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\StockMovementType;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves quantity of one or more items from one store to another. Purely
 * quantity-side, same as StockAdjustment - it NEVER calls
 * JournalVoucher::post() or otherwise touches the ledger, matching the
 * periodic (not perpetual) inventory accounting this app follows for
 * Sales/Purchase/Stock Adjustment too (relocating stock between a
 * business's own stores has no accounting/financial impact). Every line
 * writes TWO ItemStockMovement rows - a StockMovementType::TransferOut at
 * the source store, a StockMovementType::TransferIn at the destination
 * store - both dated identically and both pointing at the same
 * StockTransferLine via the polymorphic `reference`, so cancelling the
 * transfer (see cancel()) flips both with one query.
 *
 * Relocating your own goods does not change what they cost, so both
 * movements are excluded from the weighted-average cost basis by movement
 * type - see App\Support\Inventory\StockCosting, which audit P0-17 found
 * double counting transfer-in rows in the all-stores valuation. The `value`
 * still written on each movement is the document's own stated worth, kept
 * for the paperwork; no costing code reads it.
 *
 * Because nothing here goes through JournalVoucher::post(), this class calls
 * ClosedFiscalYearGuard::assertDateInOpenYear() itself (CONTRACTS C4, audit
 * P0-11: stock documents bypassed the fiscal-year guard entirely).
 */
#[Fillable(['date', 'from_store_id', 'to_store_id', 'note', 'total_value', 'status', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'created_by'])]
class StockTransfer extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_value' => Decimal::class.':2',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function toStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Validates and posts every line, guarding against transferring more
     * than the source store actually holds.
     *
     * The guard aggregates every line for the same item first (an item may
     * legitimately appear on two lines), takes a row lock on the items via
     * Item::lockForStockOut() before reading any stock, and compares exact
     * Quantity values - the three fixes audit P1 asked for, in place of a
     * per-line float comparison that let two lines of 3 against 5 on hand
     * both pass and reported a shortfall as "0.19999999999999998".
     *
     * Unlike Sale::post(), this check is never gated by CompanySetting::
     * allow_negative_stock - that setting is a sales/overselling policy, and
     * there is no equivalent business reason to let a transfer push a
     * store's own stock negative.
     *
     * @param  array{date: string, from_store_id: int, to_store_id: int, note?: string|null}  $data
     * @param  array<int, array{item_id: int, quantity: mixed, unit_cost_rate?: mixed, remarks?: string|null}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            if (empty($lines)) {
                throw new InvalidArgumentException('At least one line is required.');
            }

            ClosedFiscalYearGuard::assertDateInOpenYear($data['date'], $actor);

            $fromStoreId = (int) $data['from_store_id'];
            $toStoreId = (int) $data['to_store_id'];

            if (! Store::whereKey($fromStoreId)->exists()) {
                throw new InvalidArgumentException('Unknown source store.');
            }

            if (! Store::whereKey($toStoreId)->exists()) {
                throw new InvalidArgumentException('Unknown destination store.');
            }

            if ($fromStoreId === $toStoreId) {
                throw new InvalidArgumentException('The source and destination store must be different.');
            }

            $items = Item::whereIn('id', collect($lines)->pluck('item_id'))->get()->keyBy('id');

            $preparedLines = [];
            $requestedOut = [];

            foreach ($lines as $line) {
                if (! $items->has($line['item_id'])) {
                    throw new InvalidArgumentException("Unknown item [{$line['item_id']}].");
                }

                $quantity = Quantity::of($line['quantity']);

                if (! $quantity->isPositive()) {
                    throw new InvalidArgumentException('Quantity must be greater than zero.');
                }

                $item = $items[$line['item_id']];
                $unitCostRate = Quantity::ofNullable($line['unit_cost_rate'] ?? null);
                $lineValue = $unitCostRate === null
                    ? Money::zero()
                    : Money::round($quantity->toBigDecimal()->multipliedBy($unitCostRate->toBigDecimal()));

                $requestedOut[$item->id] = ($requestedOut[$item->id] ?? Quantity::zero())->plus($quantity);

                $preparedLines[] = [
                    'item' => $item,
                    'quantity' => $quantity,
                    'unit_cost_rate' => $unitCostRate,
                    'line_value' => $lineValue,
                    'remarks' => $line['remarks'] ?? null,
                ];
            }

            static::assertStockAvailableAtSource($requestedOut, $items, $fromStoreId);

            $totalValue = Money::sum(array_map(fn (array $line) => $line['line_value'], $preparedLines));

            $transfer = static::create([
                'date' => $data['date'],
                'from_store_id' => $fromStoreId,
                'to_store_id' => $toStoreId,
                'note' => $data['note'] ?? null,
                'total_value' => $totalValue,
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            foreach ($preparedLines as $line) {
                $transferLine = $transfer->lines()->create([
                    'item_id' => $line['item']->id,
                    'quantity' => $line['quantity'],
                    'unit_cost_rate' => $line['unit_cost_rate'],
                    'line_value' => $line['line_value'],
                    'remarks' => $line['remarks'],
                ]);

                // A zero or missing rate means "no value stated", not "these
                // goods are worth nothing": the create form sends 0 for an
                // untouched rate field.
                $value = $line['unit_cost_rate'] !== null && $line['unit_cost_rate']->isPositive()
                    ? $line['line_value']
                    : null;

                $movements = [
                    [StockMovementType::TransferOut, $fromStoreId],
                    [StockMovementType::TransferIn, $toStoreId],
                ];

                foreach ($movements as [$movementType, $storeId]) {
                    $line['item']->recordStockMovement(
                        $movementType,
                        $line['quantity'],
                        $data['date'],
                        $storeId,
                        $transferLine,
                        $line['unit_cost_rate'],
                        $value,
                    );
                }
            }

            return $transfer;
        });
    }

    /**
     * Marks every stock movement this transfer generated (both the
     * TransferOut at the source store and the TransferIn at the
     * destination) as cancelled and flips the header status - no edit
     * method exists (immutable, matching every other voucher-like record in
     * this app, including StockAdjustment).
     *
     * The row is re-read under lockForUpdate() inside the transaction and
     * its status re-checked there (audit P0-16: the status used to be
     * checked before the transaction, so two clicks both passed), and the
     * document's own date must still fall inside the open fiscal year.
     */
    public function cancel(User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to cancel a stock transfer.');
        }

        DB::transaction(function () use ($actor, $reason) {
            /** @var self $fresh */
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status === 'cancelled') {
                throw new InvalidArgumentException('This stock transfer has already been cancelled.');
            }

            ClosedFiscalYearGuard::assertDateInOpenYear($fresh->date->toDateString(), $actor, $reason);

            $lineIds = $fresh->lines()->pluck('id');

            ItemStockMovement::query()
                ->where('reference_type', (new StockTransferLine)->getMorphClass())
                ->whereIn('reference_id', $lineIds)
                ->update(['cancelled' => true]);

            $fresh->update([
                'status' => 'cancelled',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            $this->forceFill($fresh->getAttributes())->syncOriginal();
        });
    }

    /**
     * Locks the items being moved and refuses the whole transfer if any of
     * them would go below zero at the source store. `lockForUpdate()` is a
     * no-op on SQLite, so the concurrency half of this is verified by review
     * rather than by a test.
     *
     * @param  array<int, Quantity>  $requestedOut  required quantity per item, already aggregated
     * @param  Collection<int, Item>  $items
     */
    private static function assertStockAvailableAtSource(array $requestedOut, $items, int $fromStoreId): void
    {
        if ($requestedOut === []) {
            return;
        }

        Item::lockForStockOut(array_keys($requestedOut));

        $available = Item::currentStockByItem(array_keys($requestedOut), $fromStoreId);

        foreach ($requestedOut as $itemId => $required) {
            $onHand = $available[$itemId] ?? Quantity::zero();

            if ($onHand->isLessThan($required)) {
                $name = $items[$itemId]->name;

                throw new InvalidArgumentException(
                    "Transfer would take \"{$name}\" below zero stock at the source store "
                    ."(available {$onHand->formatQuantity()}, required {$required->formatQuantity()})."
                );
            }
        }
    }
}
