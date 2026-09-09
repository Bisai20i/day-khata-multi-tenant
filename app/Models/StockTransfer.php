<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
            'total_value' => 'decimal:2',
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
     * than the source store actually holds under a row lock on the item's
     * own stock movements at that store (a no-op on SQLite/tests, a real
     * lock on MySQL/prod - same pattern StockAdjustment::post() already
     * uses for its own 'out' lines). Unlike Sale::post(), this check is
     * never gated by CompanySetting::allow_negative_stock - that setting is
     * a sales/overselling policy, and there's no equivalent business reason
     * to let a transfer push a store's own stock negative.
     *
     * @param  array{date: string, from_store_id: int, to_store_id: int, note?: string|null}  $data
     * @param  array<int, array{item_id: int, quantity: float, unit_cost_rate?: float|null, remarks?: string|null}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            if (empty($lines)) {
                throw new InvalidArgumentException('At least one line is required.');
            }

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
            $totalValue = 0.0;

            foreach ($lines as $line) {
                if (! $items->has($line['item_id'])) {
                    throw new InvalidArgumentException("Unknown item [{$line['item_id']}].");
                }

                $quantity = (float) $line['quantity'];

                if ($quantity <= 0) {
                    throw new InvalidArgumentException('Quantity must be greater than zero.');
                }

                $item = $items[$line['item_id']];
                $unitCostRate = isset($line['unit_cost_rate']) ? (float) $line['unit_cost_rate'] : null;
                $lineValue = round($quantity * ($unitCostRate ?? 0), 2);

                $lockedMovements = ItemStockMovement::query()
                    ->where('item_id', $item->id)
                    ->where('store_id', $fromStoreId)
                    ->where('cancelled', false)
                    ->lockForUpdate()
                    ->get();

                $currentStock = (float) $lockedMovements->sum(
                    fn (ItemStockMovement $movement) => (float) $movement->quantity * $movement->movement_type->direction(),
                );

                if ($currentStock < $quantity) {
                    throw new InvalidArgumentException(
                        "Transfer would take \"{$item->name}\" below zero stock at the source store (currently {$currentStock}).",
                    );
                }

                $preparedLines[] = [
                    'item' => $item,
                    'quantity' => $quantity,
                    'unit_cost_rate' => $unitCostRate,
                    'line_value' => $lineValue,
                    'remarks' => $line['remarks'] ?? null,
                ];

                $totalValue = round($totalValue + $lineValue, 2);
            }

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

                $line['item']->recordStockMovement(
                    StockMovementType::TransferOut,
                    $line['quantity'],
                    $data['date'],
                    $fromStoreId,
                    $transferLine,
                    $line['unit_cost_rate'],
                );

                $line['item']->recordStockMovement(
                    StockMovementType::TransferIn,
                    $line['quantity'],
                    $data['date'],
                    $toStoreId,
                    $transferLine,
                    $line['unit_cost_rate'],
                );
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
     */
    public function cancel(User $actor, string $reason): void
    {
        if ($this->status === 'cancelled') {
            throw new InvalidArgumentException('This stock transfer has already been cancelled.');
        }

        DB::transaction(function () use ($actor, $reason) {
            $lineIds = $this->lines()->pluck('id');

            ItemStockMovement::query()
                ->where('reference_type', (new StockTransferLine)->getMorphClass())
                ->whereIn('reference_id', $lineIds)
                ->update(['cancelled' => true]);

            $this->update([
                'status' => 'cancelled',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);
        });
    }
}
