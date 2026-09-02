<?php

namespace App\Models;

use App\Enums\StockAdjustmentReason;
use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ValueError;

/**
 * A stock adjustment is purely quantity-side - it NEVER calls
 * JournalVoucher::post() or otherwise touches the ledger, matching the
 * periodic (not perpetual) inventory accounting this app follows for
 * Sales/Purchase too. Every line writes one ItemStockMovement via
 * Item::recordStockMovement(); nothing here creates a second bookkeeping
 * trail. Opening stock is just this same flow with reason_type='opening',
 * which forces direction to 'in' regardless of what was passed.
 */
#[Fillable(['date', 'store_id', 'note', 'total_value', 'status', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'created_by'])]
class StockAdjustment extends Model
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
     * @return HasMany<StockAdjustmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
     * Validates and posts every line, guarding 'out' lines against
     * overselling under a row lock on the item's own stock movements (a
     * no-op on SQLite/tests, a real lock on MySQL/prod - same pattern
     * VoucherSequence::class already uses for its own counter).
     *
     * @param  array{date: string, note?: string|null, store_id?: int|null}  $data
     * @param  array<int, array{item_id: int, direction: string, reason_type: string, quantity: float, unit_cost_rate?: float|null, remarks?: string|null}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            if (empty($lines)) {
                throw new InvalidArgumentException('At least one line is required.');
            }

            $storeId = isset($data['store_id']) ? (int) $data['store_id'] : Store::where('is_active', true)->orderBy('id')->value('id');

            if (! $storeId) {
                throw new InvalidArgumentException('No active store is configured.');
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

                $direction = $line['direction'] ?? null;

                if (! in_array($direction, ['in', 'out'], true)) {
                    throw new InvalidArgumentException('Direction must be "in" or "out".');
                }

                try {
                    $reason = StockAdjustmentReason::from($line['reason_type']);
                } catch (ValueError) {
                    throw new InvalidArgumentException("Unknown reason type [{$line['reason_type']}].");
                }

                // Opening stock is always an addition, regardless of what
                // the client sent.
                if ($reason === StockAdjustmentReason::Opening) {
                    $direction = 'in';
                }

                $item = $items[$line['item_id']];

                // A written-off item has no cost impact - forced server-side,
                // not merely at the form layer.
                if ($reason->isZeroValue()) {
                    $unitCostRate = 0.0;
                    $lineValue = 0.0;
                } else {
                    $unitCostRate = isset($line['unit_cost_rate']) ? (float) $line['unit_cost_rate'] : null;
                    $lineValue = round($quantity * ($unitCostRate ?? 0), 2);
                }

                if ($direction === 'out') {
                    $lockedMovements = ItemStockMovement::query()
                        ->where('item_id', $item->id)
                        ->where('cancelled', false)
                        ->lockForUpdate()
                        ->get();

                    $currentStock = (float) $lockedMovements->sum(
                        fn (ItemStockMovement $movement) => (float) $movement->quantity * $movement->movement_type->direction(),
                    );

                    if ($currentStock < $quantity) {
                        throw new InvalidArgumentException(
                            "Adjustment would take \"{$item->name}\" below zero stock (currently {$currentStock}).",
                        );
                    }
                }

                $preparedLines[] = [
                    'item' => $item,
                    'direction' => $direction,
                    'reason' => $reason,
                    'quantity' => $quantity,
                    'unit_cost_rate' => $unitCostRate,
                    'line_value' => $lineValue,
                    'remarks' => $line['remarks'] ?? null,
                ];

                $totalValue = round($totalValue + $lineValue, 2);
            }

            $adjustment = static::create([
                'date' => $data['date'],
                'store_id' => $storeId,
                'note' => $data['note'] ?? null,
                'total_value' => $totalValue,
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            foreach ($preparedLines as $line) {
                $adjustmentLine = $adjustment->lines()->create([
                    'item_id' => $line['item']->id,
                    'direction' => $line['direction'],
                    'reason_type' => $line['reason']->value,
                    'quantity' => $line['quantity'],
                    'unit_cost_rate' => $line['unit_cost_rate'],
                    'line_value' => $line['line_value'],
                    'remarks' => $line['remarks'],
                ]);

                $movementType = $line['direction'] === 'in'
                    ? ($line['reason'] === StockAdjustmentReason::Opening ? StockMovementType::Opening : StockMovementType::AdjustmentIn)
                    : StockMovementType::AdjustmentOut;

                $line['item']->recordStockMovement(
                    $movementType,
                    $line['quantity'],
                    $data['date'],
                    $storeId,
                    $adjustmentLine,
                    $line['unit_cost_rate'],
                );
            }

            return $adjustment;
        });
    }

    /**
     * Marks every stock movement this adjustment generated as cancelled and
     * flips the header status - no edit method exists (immutable, matching
     * every other voucher-like record in this app), which sidesteps a real
     * legacy bug class where an edit-in-place path silently left a header
     * marked cancelled while its stock movements stayed live.
     */
    public function cancel(User $actor, string $reason): void
    {
        if ($this->status === 'cancelled') {
            throw new InvalidArgumentException('This stock adjustment has already been cancelled.');
        }

        DB::transaction(function () use ($actor, $reason) {
            $lineIds = $this->lines()->pluck('id');

            ItemStockMovement::query()
                ->where('reference_type', (new StockAdjustmentLine)->getMorphClass())
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
