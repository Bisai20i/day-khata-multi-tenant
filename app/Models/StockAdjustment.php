<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\FiscalYearStatus;
use App\Enums\StockAdjustmentReason;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Inventory\PerpetualCosting;
use App\Support\Inventory\PerpetualStockGuard;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
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
 * trail. Closing stock reaches the books once a year, at year-end, out of
 * App\Support\Inventory\StockCosting - not one journal line per movement.
 *
 * The single exception is the opening-stock import (see
 * postOpeningImport()), which does post one journal voucher: opening stock
 * is a balance-sheet fact a tenant migrating mid-year has to state, not a
 * movement of goods during the year.
 *
 * Because nothing here goes through JournalVoucher::post(), this class calls
 * ClosedFiscalYearGuard::assertDateInOpenYear() itself (CONTRACTS C4). Audit
 * P0-11 found stock documents bypassing the fiscal-year guard entirely, so a
 * stock adjustment could be dated into a closed, already-filed year.
 *
 * Perpetual items (raw materials and finished goods, manufacturing contract
 * MC8) are the second exception: their value lives in a GL account (AS12 /
 * AS14) that must always equal their subledger (invariant P1), so an
 * adjustment of one is always valued and always posted. In at the entered
 * rate (required, greater than zero), out at PerpetualCosting::issueCost(),
 * damage and loss included - a write-off still removes real cost. They post
 * one voucher of type InventoryCost per adjustment: Dr/Cr the class account
 * against EXE23 Inventory Adjustment, or against the "Profit & Loss" equity
 * account for an opening line, the same counter-account the opening-stock
 * import uses. Trading lines on the same document stay exactly as above.
 */
#[Fillable([
    'date',
    'store_id',
    'note',
    'total_value',
    'status',
    'is_opening_import',
    'journal_voucher_id',
    'cancelled_by',
    'cancelled_at',
    'cancel_reason',
    'created_by',
])]
class StockAdjustment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'total_value' => Decimal::class.':2',
            'is_opening_import' => 'boolean',
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
     * The one journal voucher an opening-stock import posts (Dr AS11 Opening
     * Stock / Cr Profit & Loss). Null on every other adjustment.
     *
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
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
     * Validates and posts every line inside one transaction.
     *
     * Three guards, all of them fixes the 2026-09-11 audit asked for:
     *
     * 1. The date must lie inside the open fiscal year (or a year reopened
     *    for correction, with fiscal_year.edit and a reason) - P0-11.
     * 2. 'out' lines are checked **per store** and **per item after
     *    aggregating every line for that item**, so two lines of 3 against 5
     *    on hand can no longer both pass, and stock held at another store
     *    can no longer be spent here - P1.
     * 3. The check runs after Item::lockForStockOut(), and compares exact
     *    Quantity values. The old float comparison rejected returning the
     *    last 0.2 of a 0.3 line and told the user "0.19999999999999998".
     *
     * $data['fiscal_year_id'] is accepted only as a cross-check against the
     * year the date actually falls in: the date decides the fiscal year, the
     * picker never overrides it.
     *
     * @param  array{date: string, note?: string|null, store_id?: int|null, fiscal_year_id?: int, reason?: string|null, is_opening_import?: bool}  $data
     * @param  array<int, array{item_id: int, item_unit_id?: int|null, direction: string, reason_type: string, quantity: mixed, unit_cost_rate?: mixed, remarks?: string|null}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            if (empty($lines)) {
                throw new InvalidArgumentException('At least one line is required.');
            }

            // Named distinctly from the per-line $reason below (a
            // StockAdjustmentReason enum) to avoid shadowing it.
            $correctionReason = $data['reason'] ?? null;

            $fiscalYear = ClosedFiscalYearGuard::assertDateInOpenYear($data['date'], $actor, $correctionReason);
            $isCorrection = $fiscalYear->status !== FiscalYearStatus::Open;

            if (isset($data['fiscal_year_id']) && (int) $data['fiscal_year_id'] !== $fiscalYear->id) {
                throw new InvalidArgumentException(
                    "The date {$data['date']} does not fall inside the fiscal year you selected."
                );
            }

            // fiscal_year.edit, not the owner-only close_archive key: posting
            // a correction is day-to-day bookkeeping a trusted accountant
            // role may be granted, reopening the year is not.
            if ($isCorrection && ! $actor->can('fiscal_year.edit')) {
                throw new AuthorizationException('You do not have permission to post into a reopened fiscal year.');
            }

            $storeId = isset($data['store_id']) ? (int) $data['store_id'] : Store::where('is_active', true)->orderBy('id')->value('id');

            if (! $storeId) {
                throw new InvalidArgumentException('No active store is configured.');
            }

            $items = Item::with('units')->whereIn('id', collect($lines)->pluck('item_id'))->get()->keyBy('id');

            $preparedLines = [];
            $requestedOut = [];

            foreach ($lines as $line) {
                if (! $items->has($line['item_id'])) {
                    throw new InvalidArgumentException("Unknown item [{$line['item_id']}].");
                }

                $item = $items[$line['item_id']];
                // Alternate-unit entry: a clerk counts "2 Box" instead of
                // converting to 24 pieces by hand. `quantity` stays "as
                // entered" (mirrors purchase_lines.quantity); everything that
                // touches stock uses $baseQuantity instead (CONTRACTS C10).
                [$itemUnitId, $conversionFactor] = static::resolveItemUnit($item, $line['item_unit_id'] ?? null);

                $quantity = Quantity::of($line['quantity']);

                if (! $quantity->isPositive()) {
                    throw new InvalidArgumentException('Quantity must be greater than zero.');
                }

                $baseQuantity = $quantity->multipliedBy($conversionFactor);

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

                // A written-off item has no cost impact - forced server-side,
                // not merely at the form layer.
                if ($reason->isZeroValue()) {
                    $unitCostRate = Quantity::zero();
                    $lineValue = Money::zero();
                } else {
                    $unitCostRate = Quantity::ofNullable($line['unit_cost_rate'] ?? null);
                    $lineValue = $unitCostRate === null
                        ? Money::zero()
                        : Money::round($baseQuantity->toBigDecimal()->multipliedBy($unitCostRate->toBigDecimal()));
                }

                // A perpetual item cannot come in at no cost: its moving
                // average would fall toward a price nobody paid, and the GL
                // would never see the value. Its out lines are costed below,
                // at posting time, whatever rate the client sent.
                if ($item->isPerpetual() && $direction === 'in'
                    && ($reason->isZeroValue() || $unitCostRate === null || ! $unitCostRate->isPositive())) {
                    throw new InvalidArgumentException(
                        "\"{$item->name}\" is a {$item->inventory_class->label()} item: bringing it in needs a unit cost rate greater than zero."
                    );
                }

                if ($direction === 'out') {
                    $requestedOut[$item->id] = ($requestedOut[$item->id] ?? Quantity::zero())->plus($baseQuantity);
                }

                $preparedLines[] = [
                    'item' => $item,
                    'item_unit_id' => $itemUnitId,
                    'unit_conversion_factor' => $conversionFactor,
                    'direction' => $direction,
                    'reason' => $reason,
                    'quantity' => $quantity,
                    'base_quantity' => $baseQuantity,
                    'unit_cost_rate' => $unitCostRate,
                    'line_value' => $lineValue,
                    'remarks' => $line['remarks'] ?? null,
                ];
            }

            static::assertStockAvailable($requestedOut, $items, $storeId, $data['date']);

            $totalValue = Money::sum(array_map(fn (array $line) => $line['line_value'], $preparedLines));

            $postedLineValues = [];

            $adjustment = static::create([
                'date' => $data['date'],
                'store_id' => $storeId,
                'note' => $data['note'] ?? null,
                'total_value' => $totalValue,
                'status' => 'posted',
                'is_opening_import' => (bool) ($data['is_opening_import'] ?? false),
                'created_by' => $actor->id,
            ]);

            /** @var list<array{item: Item, direction: string, reason: StockAdjustmentReason, value: Money}> $perpetualPostings */
            $perpetualPostings = [];

            foreach ($preparedLines as $line) {
                $isPerpetual = $line['item']->isPerpetual();

                if ($isPerpetual && $line['direction'] === 'out') {
                    // Costed here, after assertStockAvailable() locked the
                    // item and line by line, so a second out line of the same
                    // item sees the first one's movement (MC3).
                    $line['line_value'] = PerpetualCosting::issueCost($line['item'], $line['base_quantity'], $data['date']);
                    $line['unit_cost_rate'] = Quantity::of($line['line_value']->toBigDecimal()->dividedBy(
                        $line['base_quantity']->toBigDecimal(), 4, RoundingMode::HalfUp
                    ));
                }

                $adjustmentLine = $adjustment->lines()->create([
                    'item_id' => $line['item']->id,
                    'item_unit_id' => $line['item_unit_id'],
                    'unit_conversion_factor' => $line['unit_conversion_factor'],
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

                // Only a priced stock-IN carries a cost basis. An 'out' line
                // removes stock that was already valued when it came in, and
                // a damage/lost write-off is explicitly zero-cost: pricing
                // either of them here would pull the weighted average toward
                // a number nobody ever paid. See StockCosting's docblock.
                // A zero rate is "no cost given", not "these goods were
                // free": the create form sends 0 for an untouched rate
                // field, and a genuine zero in the basis would value real
                // stock at nothing.
                $value = $line['direction'] === 'in'
                    && ! $line['reason']->isZeroValue()
                    && $line['unit_cost_rate'] !== null
                    && $line['unit_cost_rate']->isPositive()
                        ? $line['line_value']
                        : null;

                if ($isPerpetual) {
                    // cost_value is the exact figure the InventoryCost voucher
                    // below posts for this line, in and out alike.
                    $line['item']->recordStockMovement(
                        $movementType,
                        $line['base_quantity'],
                        $data['date'],
                        $storeId,
                        $adjustmentLine,
                        $value === null ? null : $line['unit_cost_rate'],
                        $value,
                        costValue: $line['line_value'],
                    );

                    $perpetualPostings[] = [
                        'item' => $line['item'],
                        'direction' => $line['direction'],
                        'reason' => $line['reason'],
                        'value' => $line['line_value'],
                    ];
                } else {
                    $line['item']->recordStockMovement(
                        $movementType,
                        $line['base_quantity'],
                        $data['date'],
                        $storeId,
                        $adjustmentLine,
                        $value === null ? null : $line['unit_cost_rate'],
                        $value,
                    );
                }

                $postedLineValues[] = $line['line_value'];
            }

            if ($perpetualPostings !== []) {
                // Perpetual out lines were costed only inside the loop.
                $adjustment->update(['total_value' => Money::sum($postedLineValues)]);
            }

            // The opening-stock import posts its own single voucher that
            // already carries the perpetual lines (postOpeningImport()).
            if ($perpetualPostings !== [] && ! $adjustment->is_opening_import) {
                $voucher = static::postPerpetualVoucher($adjustment, $perpetualPostings, $fiscalYear, $correctionReason, $actor);

                if ($voucher !== null) {
                    $adjustment->update(['journal_voucher_id' => $voucher->id]);
                }
            }

            if ($isCorrection) {
                ClosedFiscalYearGuard::logCorrection($fiscalYear, (string) $correctionReason, "Stock adjustment #{$adjustment->id}");
            }

            return $adjustment;
        });
    }

    /**
     * Posts an opening-stock batch, replacing whatever the last one was.
     *
     * Re-importing an opening-stock CSV used to stack a second full set of
     * opening quantities on top of the first (audit P1), so a tenant who
     * corrected one row in their spreadsheet and re-uploaded ended up with
     * double the stock. Here the previous batch is cancelled first, inside
     * the same transaction, so a re-import is a replacement.
     *
     * Unlike every other stock document this one also posts a ledger entry:
     * Dr AS11 "Opening Stock" / Cr CA2 "Profit & Loss". AS11 is the account
     * the chart-of-accounts seeder files under Current Assets > Stock and
     * which nothing has ever posted to (audit P0-17); "Profit & Loss" under
     * Capital Account > Reserve Surplus is the same equity account
     * FiscalYear::close() sweeps retained earnings into, and is what the
     * opening-balance import expects a migrating tenant's counter-entries to
     * land in. VoucherType::Journal rather than OpeningBalance on purpose:
     * the Opening Balance voucher type has a specific meaning to the Cash
     * Book and year-end roll-forward (one per year, written by
     * FiscalYear::close()), and a second one would be read as a restatement
     * of every balance.
     *
     * @param  array{date: string, note?: string|null, store_id?: int|null, fiscal_year_id?: int, reason?: string|null}  $data
     * @param  array<int, array{item_id: int, direction: string, reason_type: string, quantity: mixed, unit_cost_rate?: mixed, remarks?: string|null}>  $lines
     * @return array{adjustment: self, replaced: ?self}
     */
    public static function postOpeningImport(array $data, array $lines, User $actor): array
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            $previous = static::query()
                ->where('is_opening_import', true)
                ->where('status', 'posted')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($previous as $batch) {
                $batch->cancel($actor, 'Replaced by a newer opening stock import.');
            }

            $adjustment = static::post($data + ['is_opening_import' => true], $lines, $actor);

            $totalValue = Money::of($adjustment->total_value);

            if ($totalValue->isPositive()) {
                $openingStock = Account::where('code', 'AS11')->firstOrFail();
                $equity = Account::where('name', 'Profit & Loss')->firstOrFail();

                // Perpetual lines are debited to their class account (AS12 /
                // AS14) rather than AS11 (MC8); the trading remainder stays
                // on AS11 exactly as before. A trading-only import therefore
                // still posts the one Dr AS11 / Cr Profit & Loss pair.
                $perpetualByAccount = static::perpetualOpeningValues($adjustment);
                $tradingValue = $totalValue->minus(Money::sum(array_values($perpetualByAccount)));
                $debitLines = [];

                if ($tradingValue->isPositive()) {
                    $debitLines[] = ['account_id' => $openingStock->id, 'debit' => $tradingValue->toString(), 'credit' => 0, 'narration' => 'Opening stock'];
                }

                foreach ($perpetualByAccount as $accountId => $amount) {
                    $debitLines[] = ['account_id' => $accountId, 'debit' => $amount->toString(), 'credit' => 0, 'narration' => 'Opening stock'];
                }

                // The fiscal year is named explicitly rather than left to
                // JournalVoucher::post()'s FiscalYear::current(): post()
                // above has already resolved the year the date actually
                // falls in, and on a correction posting into a reopened
                // closed year that is deliberately not the current one.
                $fiscalYear = ClosedFiscalYearGuard::assertDateInOpenYear(
                    $data['date'],
                    $actor,
                    $data['reason'] ?? null,
                );

                $voucher = JournalVoucher::post(
                    [
                        'voucher_type' => VoucherType::Journal->value,
                        'fiscal_year_id' => $fiscalYear->id,
                        'date' => $data['date'],
                        'narration' => 'Opening stock',
                        'reason' => $data['reason'] ?? null,
                    ],
                    [
                        ...$debitLines,
                        ['account_id' => $equity->id, 'debit' => 0, 'credit' => $totalValue->toString(), 'narration' => 'Opening stock'],
                    ],
                    $actor,
                );

                $adjustment->update(['journal_voucher_id' => $voucher->id]);
            }

            return ['adjustment' => $adjustment, 'replaced' => $previous->first()];
        });
    }

    /**
     * Marks every stock movement this adjustment generated as cancelled and
     * flips the header status - no edit method exists (immutable, matching
     * every other voucher-like record in this app), which sidesteps a real
     * legacy bug class where an edit-in-place path silently left a header
     * marked cancelled while its stock movements stayed live.
     *
     * The row is re-read under lockForUpdate() inside the transaction and
     * the status re-checked there (audit P0-16: two clicks used to cancel
     * twice), and the document's own date must still fall inside the open
     * fiscal year - a filed year's quantities do not get to change.
     */
    public function cancel(User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to cancel a stock adjustment.');
        }

        DB::transaction(function () use ($actor, $reason) {
            /** @var self $fresh */
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status === 'cancelled') {
                throw new InvalidArgumentException('This stock adjustment has already been cancelled.');
            }

            ClosedFiscalYearGuard::assertDateInOpenYear($fresh->date->toDateString(), $actor, $reason);

            $lineIds = $fresh->lines()->pluck('id');

            static::assertPerpetualStockRemovable($lineIds->all());

            ItemStockMovement::query()
                ->where('reference_type', (new StockAdjustmentLine)->getMorphClass())
                ->whereIn('reference_id', $lineIds)
                ->update(['cancelled' => true]);

            if ($fresh->journal_voucher_id !== null) {
                // An opening import's voucher, or the InventoryCost voucher
                // of an adjustment with perpetual lines: reversing it puts
                // the class accounts back in step with the movements just
                // cancelled (P1).
                JournalVoucher::reverse(
                    $fresh->journalVoucher()->firstOrFail(),
                    $actor,
                    $fresh->is_opening_import
                        ? "Reversal of opening stock entry for stock adjustment #{$fresh->id}"
                        : "Cancellation of stock adjustment #{$fresh->id}: {$reason}",
                );
            }

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
     * Refuses to cancel an adjustment in (or an opening entry) of raw material
     * or finished goods whose stock has already gone out again: cancelling
     * removes it from its date on, and a perpetual item can never go below
     * zero (M08b). Trading lines are not checked, the same as before, and
     * only the perpetual items are locked (ascending id, after the adjustment
     * row and before the reversal takes the fiscal year), so a trading-only
     * adjustment cancels exactly as it always has.
     *
     * @param  array<int, int>  $lineIds
     */
    private static function assertPerpetualStockRemovable(array $lineIds): void
    {
        $movements = ItemStockMovement::query()
            ->where('reference_type', (new StockAdjustmentLine)->getMorphClass())
            ->whereIn('reference_id', $lineIds)
            ->where('cancelled', false)
            ->get();

        $perpetualIds = PerpetualStockGuard::perpetualItemIds($movements->pluck('item_id')->all());

        if ($perpetualIds === []) {
            return;
        }

        $items = Item::lockForStockOut($perpetualIds)->keyBy('id');

        PerpetualStockGuard::assertCanRemove($movements, $items, 'Cannot cancel this stock adjustment');
    }

    /**
     * Locks every item on the adjustment and refuses the whole document if
     * any item being taken out would go below zero **at this store**.
     *
     * The lock is taken on the `items` rows in ascending id order before any
     * stock is read, which is what stops two terminals from both passing the
     * check on the last unit (CONTRACTS C10); `lockForUpdate()` is a no-op on
     * SQLite, so this is verified by review rather than by a test. The 'in'
     * lines' items are locked too: left to the line and stock-movement
     * inserts, InnoDB's foreign-key checks would take them in line order
     * while this adjustment already holds its 'out' items, against a bill
     * that holds them in id order (flags PUR-21).
     *
     * @param  array<int, Quantity>  $requestedOut  required quantity per item, already aggregated
     * @param  Collection<int, Item>  $items  every item on the adjustment, keyed by id
     */
    private static function assertStockAvailable(array $requestedOut, Collection $items, int $storeId, string $date): void
    {
        $locked = Item::lockForStockOut($items->keys()->all())->keyBy('id');

        if ($requestedOut === []) {
            return;
        }

        // Raw material and finished goods are checked first, from the
        // adjustment date on, so a backdated write-off cannot reach before
        // the stock arrived either and the message names the shortfall
        // (M08b). Trading items are skipped by the guard and keep the check
        // below unchanged.
        PerpetualStockGuard::assertCanTakeOut($locked, $requestedOut, $storeId, $date, 'Cannot adjust this stock out');

        $available = Item::currentStockByItem(array_keys($requestedOut), $storeId);

        foreach ($requestedOut as $itemId => $required) {
            $onHand = $available[$itemId] ?? Quantity::zero();

            if ($onHand->isLessThan($required)) {
                $name = $items[$itemId]->name;

                throw new InvalidArgumentException(
                    "Adjustment would take \"{$name}\" below zero stock at this store "
                    ."(available {$onHand->formatQuantity()}, required {$required->formatQuantity()})."
                );
            }
        }
    }

    /**
     * Posts the one InventoryCost voucher for an adjustment's perpetual lines.
     *
     * Gross, not netted: each class account gets one debit (value in) and one
     * credit (value out) and EXE23 the opposite, so the voucher reads like the
     * document. Opening lines go against the "Profit & Loss" equity account
     * instead of EXE23, the same counter-entry postOpeningImport() makes,
     * because opening stock is a balance-sheet fact, not an expense. Zero
     * amounts (an item whose remaining value is nil) are dropped; when nothing
     * is left no voucher is posted. The voucher is dated and filed in the
     * same year post() already resolved, with the correction reason, and the
     * correction is logged once by post() itself.
     *
     * @param  list<array{item: Item, direction: string, reason: StockAdjustmentReason, value: Money}>  $postings
     */
    private static function postPerpetualVoucher(self $adjustment, array $postings, FiscalYear $fiscalYear, ?string $correctionReason, User $actor): ?JournalVoucher
    {
        $adjustmentAccountId = ManufacturingAccounts::id(ManufacturingAccounts::ADJUSTMENT);
        $equityAccountId = null;

        /** @var array<int, Money> $debits */
        $debits = [];
        /** @var array<int, Money> $credits */
        $credits = [];

        foreach ($postings as $posting) {
            $classAccountId = $posting['item']->inventoryAccountId();

            if ($posting['direction'] === 'in') {
                $counterAccountId = $posting['reason'] === StockAdjustmentReason::Opening
                    ? ($equityAccountId ??= Account::where('name', 'Profit & Loss')->firstOrFail()->id)
                    : $adjustmentAccountId;

                $debits[$classAccountId] = ($debits[$classAccountId] ?? Money::zero())->plus($posting['value']);
                $credits[$counterAccountId] = ($credits[$counterAccountId] ?? Money::zero())->plus($posting['value']);
            } else {
                $debits[$adjustmentAccountId] = ($debits[$adjustmentAccountId] ?? Money::zero())->plus($posting['value']);
                $credits[$classAccountId] = ($credits[$classAccountId] ?? Money::zero())->plus($posting['value']);
            }
        }

        $voucherLines = [];

        foreach ($debits as $accountId => $amount) {
            if ($amount->isPositive()) {
                $voucherLines[] = ['account_id' => $accountId, 'debit' => $amount->toString(), 'credit' => '0', 'narration' => 'Stock adjustment'];
            }
        }

        foreach ($credits as $accountId => $amount) {
            if ($amount->isPositive()) {
                $voucherLines[] = ['account_id' => $accountId, 'debit' => '0', 'credit' => $amount->toString(), 'narration' => 'Stock adjustment'];
            }
        }

        if ($voucherLines === []) {
            return null;
        }

        return JournalVoucher::post(
            [
                'voucher_type' => VoucherType::InventoryCost->value,
                'fiscal_year_id' => $fiscalYear->id,
                'date' => $adjustment->date->toDateString(),
                'narration' => "Stock adjustment #{$adjustment->id}",
                'reason' => $correctionReason,
            ],
            $voucherLines,
            $actor,
            logCorrection: false,
        );
    }

    /**
     * Value of an opening import's perpetual lines per class account, for
     * postOpeningImport()'s voucher. Read back from the posted lines, so it is
     * exactly what their movements carry as cost_value.
     *
     * @return array<int, Money> keyed by account id, ascending
     */
    private static function perpetualOpeningValues(self $adjustment): array
    {
        /** @var array<int, Money> $values */
        $values = [];

        foreach ($adjustment->lines()->with('item')->orderBy('id')->get() as $line) {
            if (! $line->item->isPerpetual()) {
                continue;
            }

            $accountId = $line->item->inventoryAccountId();
            $values[$accountId] = ($values[$accountId] ?? Money::zero())->plus(Money::of($line->line_value));
        }

        ksort($values);

        return array_filter($values, fn (Money $amount): bool => $amount->isPositive());
    }

    /**
     * Resolves a line's optional item_unit_id against the item's already-
     * loaded `units` relation - mirrors Purchase::resolveItemUnit() exactly
     * (see that method's docblock for the full rationale, including why a
     * null/missing item_unit_id is a guaranteed behavior-preserving no-op).
     *
     * @return array{0: int|null, 1: string}
     */
    private static function resolveItemUnit(Item $item, mixed $itemUnitId): array
    {
        if ($itemUnitId === null || $itemUnitId === '') {
            return [null, '1'];
        }

        $itemUnit = $item->units->firstWhere('id', (int) $itemUnitId);

        if (! $itemUnit) {
            throw new InvalidArgumentException("Unit [{$itemUnitId}] does not belong to item [{$item->id}].");
        }

        return [$itemUnit->id, (string) $itemUnit->conversion_factor];
    }
}
