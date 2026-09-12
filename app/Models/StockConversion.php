<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\StockConversionType;
use App\Enums\StockMovementType;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ValueError;

/**
 * Production ("assemble N raw materials into a finished good"), Refining
 * ("convert a raw material into a different, usually smaller-quantity,
 * refined output"), and Repackaging (an arbitrary N-items-in -> M-items-out
 * conversion with no other business meaning attached - legacy day_khata's
 * `/transfer` capability, see StockConversionType's own docblock for why it
 * isn't called "transfer" here) are mechanically identical stock movements -
 * each just consumes one or more input items at given quantities and
 * produces one or more output items at given quantities, store-scoped, with
 * no ledger impact at all (same periodic, not perpetual, inventory
 * accounting every other quantity-only document in this app follows - see
 * StockAdjustment/StockTransfer's own docblocks). `type` is purely a
 * narration/reporting label distinguishing the three - exactly like
 * CapitalPurchase's own `type in:capital,service` - not a mechanical
 * difference, so all three share this one model/table/controller rather
 * than three near-duplicate ones.
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
 * second bookkeeping trail. Costing stays deliberately manual: an output
 * item's cost is NOT auto-derived from the sum of its consumed inputs (how
 * to split one input cost across several outputs is a real allocation
 * policy question, left as a follow-up rather than guessed at here). What
 * did change with CONTRACTS C10 is that a priced line now records its
 * `value` as well as its rate, so the weighted average an output feeds into
 * is the value that was actually stated; a line with no rate records no
 * value and stays out of the cost basis entirely, rather than being valued
 * at zero.
 *
 * Because nothing here goes through JournalVoucher::post(), this class calls
 * ClosedFiscalYearGuard::assertDateInOpenYear() itself (CONTRACTS C4, audit
 * P0-11: stock documents bypassed the fiscal-year guard entirely).
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
            'total_value' => Decimal::class.':2',
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
     *
     * Input items are guarded against being consumed beyond what the
     * conversion's store actually holds: requested quantity is aggregated
     * per item first (an item can legitimately appear on more than one input
     * line), the items are locked with Item::lockForStockOut(), and the
     * comparison is exact Quantity arithmetic against a store-scoped SQL
     * sum. The old version rounded a float difference to 4 decimals, which
     * is the comparison audit P1 flagged.
     *
     * @param  array{type: string, date: string, note?: string|null, store_id?: int|null}  $data
     * @param  array<int, array{item_id: int, quantity: mixed, unit_cost_rate?: mixed, remarks?: string|null}>  $inputLines
     * @param  array<int, array{item_id: int, quantity: mixed, unit_cost_rate?: mixed, remarks?: string|null}>  $outputLines
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
                throw new InvalidArgumentException('Type must be "production", "refining", or "repackaging".');
            }

            ClosedFiscalYearGuard::assertDateInOpenYear($data['date'], $actor);

            $storeId = isset($data['store_id']) ? (int) $data['store_id'] : Store::where('is_active', true)->orderBy('id')->value('id');

            if (! $storeId) {
                throw new InvalidArgumentException('No active store is configured.');
            }

            $items = Item::whereIn('id', collect([...$inputLines, ...$outputLines])->pluck('item_id'))->get()->keyBy('id');

            $prepareLine = function (array $line, string $direction) use ($items): array {
                if (! $items->has($line['item_id'])) {
                    throw new InvalidArgumentException("Unknown item [{$line['item_id']}].");
                }

                $quantity = Quantity::of($line['quantity']);

                if (! $quantity->isPositive()) {
                    throw new InvalidArgumentException('Quantity must be greater than zero.');
                }

                $unitCostRate = Quantity::ofNullable($line['unit_cost_rate'] ?? null);
                $lineValue = $unitCostRate === null
                    ? Money::zero()
                    : Money::round($quantity->toBigDecimal()->multipliedBy($unitCostRate->toBigDecimal()));

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

            $requestedOut = [];

            foreach ($preparedInputs as $line) {
                $itemId = $line['item']->id;
                $requestedOut[$itemId] = ($requestedOut[$itemId] ?? Quantity::zero())->plus($line['quantity']);
            }

            Item::lockForStockOut(array_keys($requestedOut));

            $available = Item::currentStockByItem(array_keys($requestedOut), $storeId);

            foreach ($requestedOut as $itemId => $required) {
                $onHand = $available[$itemId] ?? Quantity::zero();

                if ($onHand->isLessThan($required)) {
                    $name = $items[$itemId]->name;

                    throw new InvalidArgumentException(
                        "Insufficient stock for \"{$name}\" at this store "
                        ."(available {$onHand->formatQuantity()}, required {$required->formatQuantity()})."
                    );
                }
            }

            $allPreparedLines = [...$preparedInputs, ...$preparedOutputs];
            $totalValue = Money::sum(array_map(fn (array $line) => $line['line_value'], $allPreparedLines));

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
                    $type === StockConversionType::Refining && $line['direction'] === 'out' => StockMovementType::RefiningOut,
                    $type === StockConversionType::Repackaging && $line['direction'] === 'in' => StockMovementType::RepackagingIn,
                    default => StockMovementType::RepackagingOut,
                };

                // A zero or missing rate means "no cost stated", not "this
                // output was free": the create form sends 0 for an untouched
                // rate field, and a genuine zero in the basis would value
                // real stock at nothing.
                $value = $line['unit_cost_rate'] !== null && $line['unit_cost_rate']->isPositive()
                    ? $line['line_value']
                    : null;

                $line['item']->recordStockMovement(
                    $movementType,
                    $line['quantity'],
                    $data['date'],
                    $storeId,
                    $conversionLine,
                    $line['unit_cost_rate'],
                    $value,
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
     *
     * The row is re-read under lockForUpdate() inside the transaction and
     * its status re-checked there (audit P0-16), and the document's own date
     * must still fall inside the open fiscal year.
     */
    public function cancel(User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to cancel a stock conversion.');
        }

        DB::transaction(function () use ($actor, $reason) {
            /** @var self $fresh */
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status === 'cancelled') {
                throw new InvalidArgumentException('This stock conversion has already been cancelled.');
            }

            ClosedFiscalYearGuard::assertDateInOpenYear($fresh->date->toDateString(), $actor, $reason);

            $lineIds = $fresh->lines()->pluck('id');

            ItemStockMovement::query()
                ->where('reference_type', (new StockConversionLine)->getMorphClass())
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
}
