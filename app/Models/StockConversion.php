<?php

namespace App\Models;

use App\Enums\StockConversionType;
use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ValueError;

/**
 * Production ("assemble N raw materials into a finished good") and Refining
 * ("convert a raw material into a different, usually smaller-quantity,
 * refined output") are mechanically identical stock movements - each just
 * consumes one or more input items at given quantities and produces one or
 * more output items at given quantities, store-scoped, with no ledger
 * impact at all (same periodic, not perpetual, inventory accounting every
 * other quantity-only document in this app follows - see
 * StockAdjustment/StockTransfer's own docblocks). `type` is purely a
 * narration/reporting label distinguishing the two - exactly like
 * CapitalPurchase's own `type in:capital,service` - not a mechanical
 * difference, so both share this one model/table/controller rather than two
 * near-duplicate ones.
 *
 * Legacy day_khata modelled a multi-stage refining chain (raw material ->
 * intermediate -> ground/finished) as several single-input/single-output
 * conversions stitched together in one form submission. That's not a
 * materially different mechanism from this N-inputs -> M-outputs shape - a
 * business that needs a second stage just posts a second StockConversion
 * (of type=refining) using the first one's output as its input, the same
 * way legacy's own form chained two conversions internally. No multi-stage
 * "recipe" or automatic selling-price/margin calculation is built here
 * (legacy's own "actualRate" margin math is a pricing decision, not a stock
 * movement, and is thin/ad hoc in legacy) - deliberately deferred, not
 * forgotten.
 *
 * Every line writes one ItemStockMovement via Item::recordStockMovement()
 * (input lines decrease, output lines increase) - nothing here creates a
 * second bookkeeping trail. unit_cost_rate is optional and recorded as-is
 * per line (same shape as StockAdjustmentLine/StockTransferLine) - this
 * deliberately does NOT auto-derive an output item's cost from the sum of
 * its consumed inputs (that's a real weighted-average-costing/allocation
 * policy question - e.g. how to split cost across multiple outputs - left
 * as a follow-up rather than guessed at here). StockValuationReportController
 * still picks up whatever unit_cost_rate an output line is given, same as
 * any other stock-increasing movement.
 */
#[Fillable(['type', 'date', 'store_id', 'note', 'total_value', 'status', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'created_by'])]
class StockConversion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StockConversionType::class,
            'date' => 'date',
            'total_value' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<StockConversionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockConversionLine::class);
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
     * Validates and posts every input/output line inside one transaction.
     * Input items are guarded against being consumed beyond what the
     * conversion's store actually holds - requested quantity is aggregated
     * per item first (an item can legitimately appear on more than one
     * input line), then checked once per item under a row lock scoped to
     * that store, combining Sale::post()'s per-item aggregation with
     * StockTransfer::post()'s store-scoped row lock (StockAdjustment::
     * post()'s own equivalent check is NOT store-scoped - this is the
     * stricter, more correct pattern, not a mirror of that one).
     *
     * @param  array{type: string, date: string, note?: string|null, store_id?: int|null}  $data
     * @param  array<int, array{item_id: int, quantity: float, unit_cost_rate?: float|null, remarks?: string|null}>  $inputLines
     * @param  array<int, array{item_id: int, quantity: float, unit_cost_rate?: float|null, remarks?: string|null}>  $outputLines
     */
    public static function post(array $data, array $inputLines, array $outputLines, User $actor): self
    {
        return DB::transaction(function () use ($data, $inputLines, $outputLines, $actor) {
            if (empty($inputLines)) {
                throw new InvalidArgumentException('At least one input item is required.');
            }

            if (empty($outputLines)) {
                throw new InvalidArgumentException('At least one output item is required.');
            }

            try {
                $type = StockConversionType::from($data['type']);
            } catch (ValueError) {
                throw new InvalidArgumentException('Type must be either "production" or "refining".');
            }

            $storeId = isset($data['store_id']) ? (int) $data['store_id'] : Store::where('is_active', true)->orderBy('id')->value('id');

            if (! $storeId) {
                throw new InvalidArgumentException('No active store is configured.');
            }

            $items = Item::whereIn('id', collect([...$inputLines, ...$outputLines])->pluck('item_id'))->get()->keyBy('id');

            $prepareLine = function (array $line, string $direction) use ($items): array {
                if (! $items->has($line['item_id'])) {
                    throw new InvalidArgumentException("Unknown item [{$line['item_id']}].");
                }

                $quantity = (float) $line['quantity'];

                if ($quantity <= 0) {
                    throw new InvalidArgumentException('Quantity must be greater than zero.');
                }

                $unitCostRate = isset($line['unit_cost_rate']) ? (float) $line['unit_cost_rate'] : null;
                $lineValue = round($quantity * ($unitCostRate ?? 0), 2);

                return [
                    'item' => $items[$line['item_id']],
                    'direction' => $direction,
                    'quantity' => $quantity,
                    'unit_cost_rate' => $unitCostRate,
                    'line_value' => $lineValue,
                    'remarks' => $line['remarks'] ?? null,
                ];
            };

            $preparedInputs = array_map(fn (array $line) => $prepareLine($line, 'out'), $inputLines);
            $preparedOutputs = array_map(fn (array $line) => $prepareLine($line, 'in'), $outputLines);

            $requestedQtyByItem = [];
            foreach ($preparedInputs as $line) {
                $itemId = $line['item']->id;
                $requestedQtyByItem[$itemId] = ($requestedQtyByItem[$itemId] ?? 0) + $line['quantity'];
            }

            foreach ($requestedQtyByItem as $itemId => $requestedQty) {
                $item = $items[$itemId];

                $lockedMovements = ItemStockMovement::query()
                    ->where('item_id', $itemId)
                    ->where('store_id', $storeId)
                    ->where('cancelled', false)
                    ->lockForUpdate()
                    ->get();

                $currentStock = (float) $lockedMovements->sum(
                    fn (ItemStockMovement $movement) => (float) $movement->quantity * $movement->movement_type->direction(),
                );

                if (round($currentStock - $requestedQty, 4) < 0) {
                    throw new InvalidArgumentException(
                        "Insufficient stock for \"{$item->name}\" at this store (available {$currentStock}, required {$requestedQty}).",
                    );
                }
            }

            $allPreparedLines = [...$preparedInputs, ...$preparedOutputs];
            $totalValue = round(collect($allPreparedLines)->sum('line_value'), 2);

            $conversion = static::create([
                'type' => $type->value,
                'date' => $data['date'],
                'store_id' => $storeId,
                'note' => $data['note'] ?? null,
                'total_value' => $totalValue,
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            foreach ($allPreparedLines as $line) {
                $conversionLine = $conversion->lines()->create([
                    'item_id' => $line['item']->id,
                    'direction' => $line['direction'],
                    'quantity' => $line['quantity'],
                    'unit_cost_rate' => $line['unit_cost_rate'],
                    'line_value' => $line['line_value'],
                    'remarks' => $line['remarks'],
                ]);

                $movementType = match (true) {
                    $type === StockConversionType::Production && $line['direction'] === 'in' => StockMovementType::ProductionIn,
                    $type === StockConversionType::Production && $line['direction'] === 'out' => StockMovementType::ProductionOut,
                    $type === StockConversionType::Refining && $line['direction'] === 'in' => StockMovementType::RefiningIn,
                    default => StockMovementType::RefiningOut,
                };

                $line['item']->recordStockMovement(
                    $movementType,
                    $line['quantity'],
                    $data['date'],
                    $storeId,
                    $conversionLine,
                    $line['unit_cost_rate'],
                );
            }

            return $conversion;
        });
    }

    /**
     * Marks every stock movement this conversion generated as cancelled and
     * flips the header status - no edit method exists (immutable, matching
     * every other voucher-like record in this app, including
     * StockAdjustment/StockTransfer).
     */
    public function cancel(User $actor, string $reason): void
    {
        if ($this->status === 'cancelled') {
            throw new InvalidArgumentException('This stock conversion has already been cancelled.');
        }

        DB::transaction(function () use ($actor, $reason) {
            $lineIds = $this->lines()->pluck('id');

            ItemStockMovement::query()
                ->where('reference_type', (new StockConversionLine)->getMorphClass())
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
