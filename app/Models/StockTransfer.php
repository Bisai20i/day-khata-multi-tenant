<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\FiscalYearStatus;
use App\Enums\InventoryClass;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Models\Scopes\BranchAccessScope;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchContext;
use App\Support\Branches\DocumentBranch;
use App\Support\Branches\TransferLedgerLines;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Inventory\PerpetualCosting;
use App\Support\Inventory\PerpetualStockGuard;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Moves quantity of one or more items from one store to another. Every line
 * writes TWO ItemStockMovement rows - a StockMovementType::TransferOut at
 * the source store, a StockMovementType::TransferIn at the destination
 * store - both pointing at the same StockTransferLine via the polymorphic
 * `reference`, so cancelling the transfer (see cancel()) flips both with
 * one query. Each line also stores its item's inventory_class (BR25).
 *
 * Within one branch it is purely quantity-side, same as StockAdjustment's
 * trading lines - it NEVER calls JournalVoucher::post(): relocating stock
 * between a business's own stores in one branch has no accounting impact.
 * For trading items both movements are excluded from the periodic
 * weighted-average cost basis by movement type (App\Support\Inventory\
 * StockCosting; audit P0-17 found transfer-in rows double counted), and the
 * `value` written on them is the document's stated worth, kept for the
 * paperwork. For raw material and finished goods (perpetual classes) both
 * legs carry the same issue cost as cost_value, so the item's value and its
 * AS12 / AS14 balance are unchanged (MC8, I8) while each store's value
 * follows its goods.
 *
 * Because a transfer inside one branch never goes through
 * JournalVoucher::post(), this class calls
 * ClosedFiscalYearGuard::assertDateInOpenYear() itself (CONTRACTS C4, audit
 * P0-11: stock documents bypassed the fiscal-year guard entirely).
 *
 * Branches (plan 12, sections 5.4 and 5.5): a transfer records the branch
 * of each end in from_branch_id / to_branch_id. Equal means a single-step
 * move inside one branch (BR08), exactly as described above: status
 * `posted`, both legs at once, same date, no voucher. A restricted user sees
 * a transfer when either end is one of their branches.
 *
 * Different branches (BR18, perpetual classes since BR25) is a two-step
 * transfer, and the one place a transfer touches the ledger, because each
 * branch's books must show the stock it sent or took in:
 *
 * - Dispatch (post()): status `in_transit`, only the TransferOut leg at the
 *   source store, and a BranchStockTransfer voucher in the sender's branch.
 *   Trading lines are valued at the company average cost on the transfer
 *   date (StockCosting, falling back to the item's purchase rate); raw
 *   material and finished goods lines at their perpetual issue cost
 *   (PerpetualCosting::issueCost(), costed line by line as the TransferOut
 *   legs are written). The rate and value are stored on the lines, replacing
 *   whatever rate the form sent, total_value is their sum and
 *   perpetual_value the perpetual part of it, so paper, movements and
 *   voucher always agree.
 * - Receive (receive()): status `received`, the TransferIn leg at the
 *   destination store dated the receipt date and carrying the stored line
 *   value (as cost_value for a perpetual line: nothing is re-costed), and a
 *   second voucher in the receiver's branch for the SAME stored amounts.
 * - Cancel while in transit: the dispatch voucher is reversed and the
 *   TransferOut leg flagged cancelled, which puts both the quantity and, for
 *   perpetual items, the value and the AS12 / AS14 balance back in the
 *   sender. Refused once received: the receiver records a stock adjustment
 *   for a shortage, or transfers the goods back.
 *
 * The voucher lines (App\Support\Branches\TransferLedgerLines): dispatch Dr
 * Inter-Branch Clearing (counterparty: receiver) for the total, Cr Stock
 * Transferred to Branches for the trading lines, Cr Raw Material / Finished
 * Goods Inventory (AS12 / AS14) for the perpetual ones; receipt the mirror,
 * Dr Stock Received from Branches / AS12 / AS14, Cr Inter-Branch Clearing
 * (counterparty: sender). A transfer of trading items only posts exactly the
 * two-line vouchers of BR18. Each voucher balances inside its own branch, so
 * no clearing lines are auto-added, I1 holds trivially, and each actor posts
 * only in a branch they may use (I6). Company-wide, both clearing lines and
 * both trading lines cancel once the transfer is received (I3), and AS12 /
 * AS14 per branch keep matching the stock ledger of that branch's stores
 * (P1). While a transfer is in transit they do not cancel, which is why the
 * year cannot close until it is received or cancelled
 * (FiscalYear::assertBranchBooksCanClose()).
 */
#[Fillable(['date', 'from_store_id', 'to_store_id', 'from_branch_id', 'to_branch_id', 'note', 'total_value', 'perpetual_value', 'status', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'created_by', 'dispatched_at', 'received_at', 'received_on', 'received_by', 'journal_voucher_id', 'receipt_journal_voucher_id', 'reversal_journal_voucher_id'])]
class StockTransfer extends Model
{
    /** A transfer inside one branch: both legs posted at once. */
    public const STATUS_POSTED = 'posted';

    /** A transfer between branches that left the sender and is not yet received. */
    public const STATUS_IN_TRANSIT = 'in_transit';

    /** A transfer between branches that the receiving branch took in. */
    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'total_value' => Decimal::class.':2',
            'perpetual_value' => Decimal::class.':2',
            'cancelled_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'received_on' => 'date:Y-m-d',
            'from_branch_id' => 'integer',
            'to_branch_id' => 'integer',
        ];
    }

    /**
     * Whether the two ends are in different branches (the two-step flow).
     */
    public function isCrossBranch(): bool
    {
        return $this->from_branch_id !== $this->to_branch_id;
    }

    /**
     * Not BelongsToBranch: a transfer has two branch columns and no
     * branch_id, so only the access scope is registered, widened below.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchAccessScope);
    }

    /**
     * Called by BranchAccessScope for a restricted user only: a transfer is
     * visible when either end is in one of their branches (plan 12, 5.7).
     *
     * @param  Builder<static>  $query
     * @param  list<int>  $branchIds
     */
    public function applyBranchAccessConstraint(Builder $query, array $branchIds): void
    {
        $query->where(fn (Builder $visible) => $visible
            ->whereIn($this->qualifyColumn('from_branch_id'), $branchIds)
            ->orWhereIn($this->qualifyColumn('to_branch_id'), $branchIds));
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
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    /**
     * The dispatch voucher of a transfer between branches (sender's branch).
     *
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }

    /**
     * The receipt voucher of a received transfer (receiver's branch).
     *
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function receiptJournalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class, 'receipt_journal_voucher_id');
    }

    /**
     * The reversal of the dispatch voucher, for a transfer cancelled in transit.
     *
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function reversalJournalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class, 'reversal_journal_voucher_id');
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
     * The transfer is filed under the source store's branch: the actor must
     * be allowed to use it, and a `branch_id`, when given, must be that
     * branch. A destination in the same branch posts exactly as before
     * (status `posted`, both legs, no voucher). A destination in another
     * branch dispatches (see the class docblock and
     * assertCanDispatchBetweenBranches()); the actor needs no access to the
     * receiving branch for that, only to receive. All of it is checked
     * before anything is written, and everything, voucher included, happens
     * in this one transaction.
     *
     * @param  array{date: string, from_store_id: int, to_store_id: int, branch_id?: int|null, note?: string|null}  $data
     * @param  array<int, array{item_id: int, quantity: mixed, unit_cost_rate?: mixed, remarks?: string|null}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            if (empty($lines)) {
                throw new InvalidArgumentException('At least one line is required.');
            }

            $fiscalYear = ClosedFiscalYearGuard::assertDateInOpenYear($data['date'], $actor);

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

            $branchId = DocumentBranch::forStoreDocument($actor, $data['branch_id'] ?? null, $fromStoreId)->branchId;

            // Shared lock, like DocumentBranch's read of the source store: a
            // store move in flight cannot leave this transfer filed under the
            // branch the destination store just left (I5).
            $toBranchId = (int) Store::query()->whereKey($toStoreId)->sharedLock()->value('branch_id');
            $crossBranch = $toBranchId !== $branchId;

            if ($crossBranch) {
                static::assertCanDispatchBetweenBranches($fiscalYear, (string) $data['date'], $toStoreId, $toBranchId);
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

            static::assertStockAvailableAtSource($requestedOut, $items, $fromStoreId, $data['date']);

            if ($crossBranch) {
                // After the items are locked, so the cost is read with
                // nothing else taking the same stock out underneath it.
                // Perpetual lines come back at zero, priced in the loop below.
                $preparedLines = static::valuedAtAverageCost($preparedLines, $data['date']);
            }

            // Between branches this is the trading lines only until the
            // perpetual lines are costed below; a trading-only transfer is
            // created with its final total, exactly as before BR25.
            $totalValue = Money::sum(array_map(fn (array $line) => $line['line_value'], $preparedLines));

            $transfer = static::create([
                'date' => $data['date'],
                'from_store_id' => $fromStoreId,
                'to_store_id' => $toStoreId,
                'from_branch_id' => $branchId,
                'to_branch_id' => $toBranchId,
                'note' => $data['note'] ?? null,
                'total_value' => $totalValue,
                'status' => $crossBranch ? self::STATUS_IN_TRANSIT : self::STATUS_POSTED,
                'dispatched_at' => $crossBranch ? now() : null,
                'created_by' => $actor->id,
            ]);

            if ($crossBranch) {
                $perpetualValue = Money::zero();

                foreach ($preparedLines as $line) {
                    $inventoryClass = $line['item']->inventory_class ?? InventoryClass::Trading;
                    $costValue = null;

                    if ($inventoryClass->isPerpetual()) {
                        // Costed here, interleaved with the movement writes
                        // and after assertStockAvailableAtSource() locked the
                        // items, exactly like the same-branch path below: a
                        // second line of the same item is costed against a
                        // balance that already has the first line's
                        // TransferOut taken off.
                        $line = [...$line, ...static::perpetualLineCost($line['item'], $line['quantity'], $data['date'])];
                        $costValue = $line['line_value'];
                        $perpetualValue = $perpetualValue->plus($costValue);
                    }

                    $transferLine = $transfer->lines()->create([
                        'item_id' => $line['item']->id,
                        'quantity' => $line['quantity'],
                        'unit_cost_rate' => $line['unit_cost_rate'],
                        'line_value' => $line['line_value'],
                        'inventory_class' => $inventoryClass,
                        'remarks' => $line['remarks'],
                    ]);

                    // Only the leg leaving the sender; the destination's leg is
                    // written by receive(), dated the day the goods arrive.
                    $line['item']->recordStockMovement(
                        StockMovementType::TransferOut,
                        $line['quantity'],
                        $data['date'],
                        $fromStoreId,
                        $transferLine,
                        $line['unit_cost_rate'],
                        $line['line_value'],
                        costValue: $costValue,
                    );
                }

                if ($perpetualValue->isPositive()) {
                    $transfer->update([
                        'total_value' => $totalValue->plus($perpetualValue),
                        'perpetual_value' => $perpetualValue,
                    ]);
                }

                $transfer->postDispatchVoucher($actor);

                return $transfer;
            }

            foreach ($preparedLines as $line) {
                $transferLine = $transfer->lines()->create([
                    'item_id' => $line['item']->id,
                    'quantity' => $line['quantity'],
                    'unit_cost_rate' => $line['unit_cost_rate'],
                    'line_value' => $line['line_value'],
                    'inventory_class' => $line['item']->inventory_class ?? InventoryClass::Trading,
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

                if ($line['item']->isPerpetual()) {
                    // Both legs carry the same cost_value, so the item's
                    // value balance and its class account (AS12 / AS14) are
                    // unchanged and no voucher is needed (MC8). Costed after
                    // assertStockAvailableAtSource() locked the item, and
                    // line by line so a second line of the same item sees the
                    // first; the moving average is item-wide, not per store.
                    $cost = PerpetualCosting::issueCost($line['item'], $line['quantity'], $data['date']);

                    foreach ($movements as [$movementType, $storeId]) {
                        $line['item']->recordStockMovement(
                            $movementType,
                            $line['quantity'],
                            $data['date'],
                            $storeId,
                            $transferLine,
                            $line['unit_cost_rate'],
                            $value,
                            costValue: $cost,
                        );
                    }

                    continue;
                }

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
     *
     * A transfer between branches (BR18) may only be cancelled while in
     * transit, by an actor who may use the sending branch: its TransferOut
     * leg (the only one written) is flagged cancelled, the same convention
     * as above, and its dispatch voucher is reversed. Once received it is
     * refused: the goods are on the receiver's books, so a shortage is a
     * stock adjustment there and a return is a transfer back. Because the
     * receive() and this method lock the same row first, a cancel and a
     * receive of the same transfer can never both succeed.
     *
     * No assertPerpetualStockRemovable() on that path: an in-transit
     * transfer has only TransferOut legs, and cancelling an out-movement puts
     * stock back, which can never take anything below zero
     * (PerpetualStockGuard::assertCanRemove() ignores out-movements anyway).
     * The reversal of the dispatch voucher puts the AS12 / AS14 value back in
     * the sender for exactly the cost_value the flagged legs add back (P1).
     */
    public function cancel(User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to cancel a stock transfer.');
        }

        DB::transaction(function () use ($actor, $reason) {
            /** @var self $fresh */
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status === self::STATUS_CANCELLED) {
                throw new InvalidArgumentException('This stock transfer has already been cancelled.');
            }

            if ($fresh->status === self::STATUS_RECEIVED) {
                throw new InvalidArgumentException(
                    'This transfer has already been received by the other branch, so it can no longer be cancelled. '
                    .'Record a stock adjustment at the receiving store for any shortage or damage, or transfer the goods back.'
                );
            }

            ClosedFiscalYearGuard::assertDateInOpenYear($fresh->date->toDateString(), $actor, $reason);

            if ($fresh->status === self::STATUS_IN_TRANSIT) {
                $fresh->cancelInTransit($actor, $reason);
                $this->forceFill($fresh->getAttributes())->syncOriginal();

                return;
            }

            $lineIds = $fresh->lines()->pluck('id');

            static::assertPerpetualStockRemovable($lineIds->all());

            ItemStockMovement::query()
                ->where('reference_type', (new StockTransferLine)->getMorphClass())
                ->whereIn('reference_id', $lineIds)
                ->update(['cancelled' => true]);

            $fresh->update([
                'status' => self::STATUS_CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            $this->forceFill($fresh->getAttributes())->syncOriginal();
        });
    }

    /**
     * Receives a transfer between branches (BR18) into its destination store
     * on $date: writes the TransferIn legs dated $date and posts the
     * receiving branch's voucher for the amount stored at dispatch (never
     * re-valued: the two vouchers must carry the same figure for the
     * clearing account and the two trading accounts to cancel, I3).
     *
     * Double receipt is prevented by the row lock: the transfer is re-read
     * under lockForUpdate() inside the transaction and its status re-checked
     * there, so of two concurrent receives (or a receive racing a cancel) the
     * second waits and then finds the transfer no longer in transit.
     * lockForUpdate() is a no-op on SQLite, so that half is verified by review.
     *
     * The actor must be allowed to use the receiving branch (I6), which must
     * be active (JournalVoucher::post() checks it again), as must the
     * destination store. $date may not be before the dispatch date and must
     * be in the open fiscal year. The voucher lines are built from the stored
     * lines first (TransferLedgerLines), so a transfer whose lines do not add
     * up to its header is refused before anything is written.
     *
     * @throws InvalidArgumentException
     * @throws AuthorizationException When a reopened year is aimed at without the right to post there.
     */
    public function receive(User $actor, string $date): void
    {
        DB::transaction(function () use ($actor, $date) {
            /** @var self $fresh */
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== self::STATUS_IN_TRANSIT) {
                throw new InvalidArgumentException(match ($fresh->status) {
                    self::STATUS_RECEIVED => 'This stock transfer has already been received.',
                    self::STATUS_CANCELLED => 'This stock transfer has been cancelled and can no longer be received.',
                    default => 'Only a transfer between branches that is in transit can be received.',
                });
            }

            if (! BranchContext::canAccessBranch((int) $fresh->to_branch_id, $actor)) {
                throw new InvalidArgumentException('Only a user of the receiving branch can receive this transfer, and the branch must be active.');
            }

            $receivedOn = CarbonImmutable::parse($date)->toDateString();
            $dispatchedOn = $fresh->date->toDateString();

            if ($receivedOn < $dispatchedOn) {
                throw new InvalidArgumentException("The receipt date cannot be before the transfer date ({$dispatchedOn}).");
            }

            // Like the dispatch: a future-dated receipt would leave the
            // receiving store and the company's perpetual stock short today
            // while the transfer already shows as received.
            $today = now()->setTimezone('Asia/Kathmandu')->toDateString();

            if ($receivedOn > $today) {
                throw new InvalidArgumentException("The receipt date cannot be after today ({$today}).");
            }

            $fiscalYear = ClosedFiscalYearGuard::assertDateInOpenYear($receivedOn, $actor);
            static::assertInOpenYear($fiscalYear, 'received');

            static::assertDestinationStillUsable((int) $fresh->to_store_id, (int) $fresh->to_branch_id);

            // Amounts first: a transfer whose stored lines disagree with its
            // header is refused before any movement is written.
            [$clearingId, , $receivedId] = static::branchAccountIds();
            $voucherLines = TransferLedgerLines::receipt($fresh, $clearingId, $receivedId);

            $lines = $fresh->lines()->with('item')->orderBy('id')->get();

            foreach ($lines as $line) {
                $lineClass = $line->inventory_class ?? InventoryClass::Trading;

                // Defence only: an item's class cannot change once it has a
                // stock movement, and this line's TransferOut is one.
                if (($line->item->inventory_class ?? InventoryClass::Trading) !== $lineClass) {
                    throw new InvalidArgumentException(
                        "\"{$line->item->name}\" is no longer the same kind of item it was when this transfer was dispatched, so it cannot be received. Contact support."
                    );
                }

                // The stored line value, never re-costed: for raw material and
                // finished goods it is the cost_value the TransferOut took off,
                // so the item total is unchanged and the receiver's AS12 /
                // AS14 debit matches its store's stock ledger (P1).
                $line->item->recordStockMovement(
                    StockMovementType::TransferIn,
                    $line->quantity,
                    $receivedOn,
                    (int) $fresh->to_store_id,
                    $line,
                    $line->unit_cost_rate,
                    $line->line_value,
                    costValue: $lineClass->isPerpetual() ? $line->line_value : null,
                );
            }

            $fromBranchName = Branch::query()->whereKey($fresh->from_branch_id)->value('name') ?? "#{$fresh->from_branch_id}";

            $voucher = JournalVoucher::post([
                'voucher_type' => VoucherType::BranchStockTransfer->value,
                'date' => $receivedOn,
                'narration' => "Stock transfer #{$fresh->id} received from {$fromBranchName}",
                'branch_id' => (int) $fresh->to_branch_id,
            ], $voucherLines, $actor);

            $fresh->update([
                'status' => self::STATUS_RECEIVED,
                'received_at' => now(),
                'received_on' => $receivedOn,
                'received_by' => $actor->id,
                'receipt_journal_voucher_id' => $voucher->id,
            ]);

            static::logActivity($fresh, $actor, 'received', "Stock transfer #{$fresh->id} received on {$receivedOn} (voucher #{$voucher->seriesNumber()})", [
                'received_on' => $receivedOn,
                'receipt_journal_voucher_id' => $voucher->id,
            ]);

            $this->forceFill($fresh->getAttributes())->syncOriginal();
        });
    }

    /**
     * The in-transit half of cancel(): runs inside its transaction, on the
     * locked row, after the status and fiscal-year checks. The actor must be
     * allowed to use the sending branch: the stock goes back on its shelf
     * and the reversal posts in its books.
     */
    private function cancelInTransit(User $actor, string $reason): void
    {
        if (! BranchContext::canAccessBranch((int) $this->from_branch_id, $actor)) {
            throw new InvalidArgumentException('Only a user of the sending branch can cancel this transfer, and the branch must be active.');
        }

        ItemStockMovement::query()
            ->where('reference_type', (new StockTransferLine)->getMorphClass())
            ->whereIn('reference_id', $this->lines()->pluck('id'))
            ->update(['cancelled' => true]);

        $reversal = JournalVoucher::reverse(
            BranchContext::unrestricted(fn () => $this->journalVoucher()->firstOrFail()),
            $actor,
            Str::limit("Cancellation of stock transfer #{$this->id}: {$reason}", 250, ''),
        );

        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_by' => $actor->id,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
            'reversal_journal_voucher_id' => $reversal->id,
        ]);

        static::logActivity($this, $actor, 'cancelled', "Stock transfer #{$this->id} cancelled in transit: {$reason}", [
            'reason' => $reason,
            'reversal_journal_voucher_id' => $reversal->id,
        ]);
    }

    /**
     * Posts the sending branch's voucher for a just-created transfer between
     * branches and links it. Called by post() inside its transaction.
     */
    private function postDispatchVoucher(User $actor): void
    {
        [$clearingId, $transferredId] = static::branchAccountIds();
        $toBranchName = Branch::query()->whereKey($this->to_branch_id)->value('name') ?? "#{$this->to_branch_id}";

        $voucher = JournalVoucher::post([
            'voucher_type' => VoucherType::BranchStockTransfer->value,
            'date' => $this->date->toDateString(),
            'narration' => "Stock transfer #{$this->id} dispatched to {$toBranchName}",
            'branch_id' => (int) $this->from_branch_id,
        ], TransferLedgerLines::dispatch($this, $clearingId, $transferredId), $actor);

        $this->update(['journal_voucher_id' => $voucher->id]);

        static::logActivity($this, $actor, 'dispatched', "Stock transfer #{$this->id} dispatched to {$toBranchName} (voucher #{$voucher->seriesNumber()})", [
            'from_branch_id' => $this->from_branch_id,
            'to_branch_id' => $this->to_branch_id,
            'total_value' => Money::of($this->total_value)->toString(),
            'journal_voucher_id' => $voucher->id,
        ]);
    }

    /**
     * What a transfer to another branch needs before anything is written:
     * the branches module (with it off, the refusal is word for word the one
     * before BR18), a date in the open fiscal year (an in-transit transfer
     * must be settled before its year closes, so a reopened year is no
     * place for one), a dispatch date no later than today, and an active
     * destination store and branch.
     *
     * Not future-dated (BR22): the dispatch posts the sender's voucher and
     * puts the goods in transit now (dispatched_at), and receive() only
     * refuses a receipt dated before the dispatch date, so a future date
     * would let the books show goods leaving on a day that has not come and
     * force the receiver to date its receipt after it. Today is the
     * Kathmandu calendar day, as JournalVoucher reads it. Transfers within
     * one branch never reach this check and are unchanged.
     *
     * @throws InvalidArgumentException
     */
    private static function assertCanDispatchBetweenBranches(FiscalYear $fiscalYear, string $date, int $toStoreId, int $toBranchId): void
    {
        if (! ActiveBranchSelection::moduleEnabled()) {
            throw new InvalidArgumentException(
                'The source and destination stores are in different branches. Transfers between branches are not available yet; choose two stores of the same branch.'
            );
        }

        $dispatchedOn = CarbonImmutable::parse($date)->toDateString();
        $today = now()->setTimezone('Asia/Kathmandu')->toDateString();

        if ($dispatchedOn > $today) {
            throw new InvalidArgumentException(
                "A transfer between branches cannot be dated after today ({$today}): it is dispatched when it is saved."
            );
        }

        static::assertInOpenYear($fiscalYear, 'dispatched');

        if (! Store::query()->whereKey($toStoreId)->where('is_active', true)->exists()) {
            throw new InvalidArgumentException('The destination store is inactive. Choose an active store.');
        }

        if (! Branch::query()->whereKey($toBranchId)->where('is_active', true)->exists()) {
            throw new InvalidArgumentException('The destination store belongs to an inactive branch. Choose a store of an active branch.');
        }
    }

    /**
     * receive()'s re-check of what assertCanDispatchBetweenBranches()
     * checked at dispatch (deferred from BR22): the destination store, or its
     * branch, may have been deactivated while the goods were in transit, and
     * stock must not be taken into a store nobody can select any more. The
     * store cannot have changed branch: a store a transfer names is "in use"
     * (Store::hasMovementsOrDocuments()) and may not move. The sender can
     * still cancel the transfer, or the store be reactivated.
     *
     * @throws InvalidArgumentException
     */
    private static function assertDestinationStillUsable(int $toStoreId, int $toBranchId): void
    {
        if (! Store::query()->whereKey($toStoreId)->where('is_active', true)->exists()) {
            throw new InvalidArgumentException('The destination store is inactive. Reactivate it before receiving this transfer.');
        }

        if (! Branch::query()->whereKey($toBranchId)->where('is_active', true)->exists()) {
            throw new InvalidArgumentException('The destination store belongs to an inactive branch. Reactivate the branch before receiving this transfer.');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertInOpenYear(FiscalYear $fiscalYear, string $action): void
    {
        if ($fiscalYear->status !== FiscalYearStatus::Open) {
            throw new InvalidArgumentException(
                "A transfer between branches can only be {$action} with a date in the open fiscal year, not in {$fiscalYear->name}."
            );
        }
    }

    /**
     * Values each prepared line of a transfer between branches at the
     * company's weighted average cost on the transfer date (StockCosting),
     * or the item's purchase rate when it has no priced history - the same
     * fallback StockCosting's own valuation uses. The rate is rounded to 4
     * decimals once and the value is rate x quantity rounded once, so the
     * printed rate, quantity and value always reconcile. A line that comes to
     * zero is refused: the receiving branch would take the goods in at
     * nothing.
     *
     * The average is a company-wide fact, read outside the branch access
     * scope so a restricted sender values the goods exactly as anyone else.
     *
     * Raw material and finished goods lines are not valued here: they come
     * back with no rate and a zero value, and post() prices each one with
     * perpetualLineCost() as its TransferOut leg is written, because their
     * cost depends on the lines of the same item written before them.
     *
     * @param  list<array{item: Item, quantity: Quantity, unit_cost_rate: Quantity|null, line_value: Money, remarks: string|null}>  $preparedLines
     * @return list<array{item: Item, quantity: Quantity, unit_cost_rate: Quantity|null, line_value: Money, remarks: string|null}>
     *
     * @throws InvalidArgumentException
     */
    private static function valuedAtAverageCost(array $preparedLines, string $date): array
    {
        return array_map(function (array $line) use ($date): array {
            $item = $line['item'];

            if ($item->isPerpetual()) {
                return [...$line, 'unit_cost_rate' => null, 'line_value' => Money::zero()];
            }

            $cost = BranchContext::unrestricted(fn () => StockCosting::averageCost($item, $date))
                ?? ($item->purchase_rate === null ? null : Quantity::of($item->purchase_rate)->toBigDecimal());

            $rate = $cost === null ? Quantity::zero() : Quantity::round($cost);
            $value = Money::round($line['quantity']->toBigDecimal()->multipliedBy($rate->toBigDecimal()));

            if (! $value->isPositive()) {
                throw new InvalidArgumentException(
                    "\"{$item->name}\" has no cost to transfer it at (no priced purchase or opening stock, and no purchase rate). "
                    .'Set the item\'s purchase rate before transferring it to another branch.'
                );
            }

            return [...$line, 'unit_cost_rate' => $rate, 'line_value' => $value];
        }, $preparedLines);
    }

    /**
     * The value of one raw material or finished goods line of a transfer
     * between branches: its perpetual issue cost on the transfer date
     * (PerpetualCosting::issueCost(), moving average with the whole-balance
     * and fallback rules), the same figure its TransferOut leg takes off as
     * cost_value and the dispatch voucher credits to AS12 / AS14 (P1). Call
     * it after the items are locked and after the TransferOut legs of the
     * earlier lines are written.
     *
     * The value is the issue cost itself, never rate x quantity: the rate
     * (cost / quantity at 4 decimals) is stored for the paper only, so it may
     * not reconcile to the paisa with the value. A line that comes to zero is
     * refused, like a trading line: the receiving branch would take the goods
     * in at nothing.
     *
     * @return array{unit_cost_rate: Quantity, line_value: Money}
     *
     * @throws InvalidArgumentException
     */
    private static function perpetualLineCost(Item $item, Quantity $quantity, string $date): array
    {
        $cost = PerpetualCosting::issueCost($item, $quantity, $date);

        if (! $cost->isPositive()) {
            throw new InvalidArgumentException(
                "\"{$item->name}\" has no cost to transfer it at (its stock is valued at nothing). "
                .'Correct its stock value before transferring it to another branch.'
            );
        }

        return [
            'unit_cost_rate' => Quantity::of($cost->toBigDecimal()->dividedBy($quantity->toBigDecimal(), 4, RoundingMode::HalfUp)),
            'line_value' => $cost,
        ];
    }

    /**
     * Ids of Inter-Branch Clearing, Stock Transferred to Branches and Stock
     * Received from Branches, in that order, by their protected codes.
     *
     * @return array{0: int, 1: int, 2: int}
     *
     * @throws InvalidArgumentException When one is missing (tenant migrations not run), or its code belongs to a tenant's own account (BranchAccounts::codeConflictMessage()).
     */
    private static function branchAccountIds(): array
    {
        $codes = [
            BranchAccounts::CLEARING_ACCOUNT_CODE,
            BranchAccounts::STOCK_TRANSFERRED_CODE,
            BranchAccounts::STOCK_RECEIVED_CODE,
        ];

        $accounts = Account::query()->whereIn('code', $codes)->get(['id', 'code', 'name'])->keyBy('code');

        foreach ($codes as $code) {
            $account = $accounts->get($code);

            if ($account === null) {
                throw new InvalidArgumentException(
                    "The inter-branch account {$code} is missing from the chart of accounts, so transfers between branches cannot be posted. Contact support."
                );
            }

            $conflict = BranchAccounts::codeConflictMessage($code, (string) $account->name);

            if ($conflict !== null) {
                throw new InvalidArgumentException($conflict);
            }
        }

        return [(int) $accounts[$codes[0]]->id, (int) $accounts[$codes[1]]->id, (int) $accounts[$codes[2]]->id];
    }

    /**
     * One audit-trail row for a step of a transfer between branches. Stock
     * transfers are not on ActivityLogObserver's list, so these steps are
     * written here, in the same shape as the observer's rows.
     *
     * @param  array<string, mixed>  $changes
     */
    private static function logActivity(self $transfer, User $actor, string $action, string $description, array $changes): void
    {
        ActivityLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $transfer->getMorphClass(),
            'subject_id' => $transfer->getKey(),
            'description' => Str::limit($description, 250),
            'changes' => $changes,
        ]);
    }

    /**
     * Refuses to cancel a transfer when raw material or finished goods it
     * delivered have already gone out of the destination store: cancelling
     * removes the receiving leg from the transfer date on, and a perpetual
     * item can never go below zero (M08b). Only the TransferIn legs matter
     * (cancelling the TransferOut leg puts stock back at the source).
     * Trading items are not checked, the same as before, and only the
     * perpetual items are locked (ascending id, after the transfer row), so a
     * trading-only transfer cancels exactly as it always has.
     *
     * @param  array<int, int>  $lineIds
     */
    private static function assertPerpetualStockRemovable(array $lineIds): void
    {
        $movements = ItemStockMovement::query()
            ->where('reference_type', (new StockTransferLine)->getMorphClass())
            ->whereIn('reference_id', $lineIds)
            ->where('cancelled', false)
            ->get();

        $perpetualIds = PerpetualStockGuard::perpetualItemIds($movements->pluck('item_id')->all());

        if ($perpetualIds === []) {
            return;
        }

        $items = Item::lockForStockOut($perpetualIds)->keyBy('id');

        PerpetualStockGuard::assertCanRemove($movements, $items, 'Cannot cancel this stock transfer');
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
    private static function assertStockAvailableAtSource(array $requestedOut, $items, int $fromStoreId, string $date): void
    {
        if ($requestedOut === []) {
            return;
        }

        $locked = Item::lockForStockOut(array_keys($requestedOut))->keyBy('id');

        // Raw material and finished goods are checked first, from the
        // transfer date on, so a backdated transfer cannot move stock the
        // source store did not yet hold (M08b). Trading items are skipped by
        // the guard and keep the check below unchanged.
        PerpetualStockGuard::assertCanTakeOut($locked, $requestedOut, $fromStoreId, $date, 'Cannot transfer this stock');

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
