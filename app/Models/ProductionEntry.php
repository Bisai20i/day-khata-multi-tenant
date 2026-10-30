<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\DirectCostType;
use App\Enums\FiscalYearStatus;
use App\Enums\InventoryClass;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Inventory\PerpetualCosting;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Manufacturing\WipLedger;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * A production run turning issued raw material into a finished good (MC6).
 *
 * Everything an entry does to the books flows through the work-in-process
 * account AS13 and its subledger `production_wip_entries` (MC7), and the two
 * are written together in every method so invariant P2 (subledger == GL)
 * always holds:
 *
 * - material comes in through Material Issue Notes (MC5), which post their
 *   own Dr AS13 / Cr AS12 voucher; attaching a note only moves its WIP rows
 *   onto this entry, it never posts anything;
 * - direct costs post Dr AS13 / Cr the paying or payable account;
 * - material returns post Dr AS12 / Cr AS13 at this entry's own WIP rate of
 *   the item, never the store's moving average, so returning everything that
 *   was issued gives back exactly what was issued;
 * - completions post Dr AS14 / Cr AS13 and a ProductionReceipt movement, a
 *   final completion taking the whole remaining balance so no WIP is ever
 *   orphaned on a finished entry;
 * - abandoning writes the remaining balance off to Production Loss (EXE24).
 *
 * Every method runs in one transaction that re-reads and locks this entry's
 * row first, then the items it moves stock for (Item::lockForStockOut, which
 * documents the global lock order), then the fiscal year (taken inside
 * JournalVoucher::post()/reverse()), and every dated posting goes through
 * ClosedFiscalYearGuard.
 *
 * Postings into a closed year reopened for correction (create, direct cost,
 * material return, completion) take an optional correction reason, passed
 * to ClosedFiscalYearGuard::assertDateInOpenYear() and to
 * JournalVoucher::post() exactly as Purchase and StockAdjustment do: the
 * guard refuses a reopened year without a reason or without
 * fiscal_year.edit, and post() rolls the voucher forward and logs the
 * correction. Cancels go through JournalVoucher::reverse(), which only ever
 * reverses a voucher of the open year (C4/C5), so a row posted into a
 * correction year cannot be cancelled from here.
 *
 * The latest live completion or material return can be cancelled on its own
 * (cancelCompletion(), cancelMaterialReturn()). Only the latest, because each
 * one was costed from the WIP the previous ones left behind; undoing the
 * latest restores exactly the per-item quantities and per-bucket values that
 * were there before it, so invariant P2 holds and the next posting costs as
 * if the cancelled one never happened.
 */
#[Fillable([
    'fiscal_year_id',
    'number',
    'date',
    'store_id',
    'finished_item_id',
    'planned_quantity',
    'status',
    'narration',
    'completed_at',
    'cancelled_by',
    'cancelled_at',
    'cancel_reason',
    'created_by',
])]
class ProductionEntry extends Model
{
    use HasFactory;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABANDONED = 'abandoned';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'planned_quantity' => Decimal::class.':4',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function finishedItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'finished_item_id');
    }

    /**
     * @return HasMany<MaterialIssueNote, $this>
     */
    public function materialIssueNotes(): HasMany
    {
        return $this->hasMany(MaterialIssueNote::class);
    }

    /**
     * @return HasMany<ProductionDirectCost, $this>
     */
    public function directCosts(): HasMany
    {
        return $this->hasMany(ProductionDirectCost::class);
    }

    /**
     * @return HasMany<ProductionCompletion, $this>
     */
    public function completions(): HasMany
    {
        return $this->hasMany(ProductionCompletion::class);
    }

    /**
     * @return HasMany<ProductionWipEntry, $this>
     */
    public function wipEntries(): HasMany
    {
        return $this->hasMany(ProductionWipEntry::class);
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
     * Opens a production entry. At least one posted, unattached Material
     * Issue Note from the same store is mandatory (the spec's required
     * reference): an entry with no material has nothing to cost.
     *
     * Nothing is posted to the GL here. The notes already moved their value
     * into AS13; attaching only re-points their WIP rows at this entry. The
     * entry is numbered from its own header-only series
     * (VoucherType::ProductionEntry), claimed the same way
     * JournalVoucher::nextVoucherNumber() claims a voucher number, after the
     * fiscal year row is locked.
     *
     * Lock order: the notes (documents, ascending id) -> fiscal year ->
     * number sequence. There is no entry row to lock yet.
     *
     * `store_id` falls back to the default (lowest-id active) store, as
     * MaterialIssueNote::post() does.
     *
     * `reason` is the correction reason, required only when the date falls
     * in a closed year reopened for correction. Nothing posts to the GL
     * here, so the correction is logged by this method itself.
     *
     * @param  array{date: string, store_id?: int|string|null, finished_item_id: int|string, planned_quantity: mixed, narration?: string|null, reason?: string|null}  $data
     * @param  array<int, int|string>  $materialIssueNoteIds
     */
    public static function create(array $data, array $materialIssueNoteIds, User $actor): self
    {
        return DB::transaction(function () use ($data, $materialIssueNoteIds, $actor) {
            $ids = static::normaliseIds($materialIssueNoteIds);

            if ($ids === []) {
                throw new InvalidArgumentException('A production entry needs at least one material issue note.');
            }

            $date = static::normaliseDate($data['date']);
            $storeId = isset($data['store_id']) && $data['store_id'] !== ''
                ? (int) $data['store_id']
                : (int) Store::where('is_active', true)->orderBy('id')->value('id');

            if (! Store::query()->whereKey($storeId)->exists()) {
                throw new InvalidArgumentException('No such store is configured.');
            }

            $finishedItem = Item::query()->find((int) $data['finished_item_id']);

            if ($finishedItem === null || $finishedItem->inventory_class !== InventoryClass::FinishedGood) {
                throw new InvalidArgumentException('The item produced must be a finished good.');
            }

            $planned = Quantity::of($data['planned_quantity']);

            if (! $planned->isPositive()) {
                throw new InvalidArgumentException('The planned quantity must be greater than zero.');
            }

            $notes = static::lockAttachableNotes($ids, $storeId);

            $reason = static::normaliseReason($data['reason'] ?? null);

            $fiscalYear = ClosedFiscalYearGuard::assertDateInOpenYear($date, $actor, $reason);
            $fiscalYear = FiscalYear::query()->whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();
            ClosedFiscalYearGuard::ensurePostable($fiscalYear, $reason);

            $entry = new static;
            $entry->fill([
                'fiscal_year_id' => $fiscalYear->id,
                'number' => static::claimNumber($fiscalYear),
                'date' => $date,
                'store_id' => $storeId,
                'finished_item_id' => $finishedItem->id,
                'planned_quantity' => $planned,
                'status' => self::STATUS_IN_PROGRESS,
                'narration' => $data['narration'] ?? null,
                'created_by' => $actor->id,
            ]);
            $entry->save();

            $entry->moveNotesOnto($notes->modelKeys());

            if ($fiscalYear->status !== FiscalYearStatus::Open) {
                ClosedFiscalYearGuard::logCorrection($fiscalYear, (string) $reason, "Production Entry #{$entry->number}");
            }

            return $entry;
        });
    }

    /**
     * Attaches more posted, unattached, same-store notes while the entry is
     * in progress. Lock order: this entry -> the notes.
     *
     * @param  array<int, int|string>  $ids
     */
    public function attachIssueNotes(array $ids, User $actor): void
    {
        $ids = static::normaliseIds($ids);

        if ($ids === []) {
            throw new InvalidArgumentException('Select at least one material issue note to attach.');
        }

        $this->withLockedSelf(function (self $entry) use ($ids) {
            $entry->assertInProgress('attach material issue notes to');

            $notes = static::lockAttachableNotes($ids, (int) $entry->store_id);

            $entry->moveNotesOnto($notes->modelKeys());
        });
    }

    /**
     * Charges a direct cost (labour, power, ...) to this entry's WIP:
     * Dr AS13 / Cr `credit_account_id`, and one `direct_cost` WIP row.
     * The credit account must be a cash, bank or liability account
     * (ProductionDirectCost::creditAccountQuery()).
     *
     * `reason` is the correction reason for a date in a year reopened for
     * correction; JournalVoucher::post() logs the correction.
     *
     * @param  array{date: string, cost_type: string, description?: string|null, amount: mixed, credit_account_id: int|string, reason?: string|null}  $data
     */
    public function addDirectCost(array $data, User $actor): ProductionDirectCost
    {
        return $this->withLockedSelf(function (self $entry) use ($data, $actor) {
            $entry->assertInProgress('add a direct cost to');

            $date = $entry->assertPostingDate($data['date']);

            $type = DirectCostType::tryFrom((string) ($data['cost_type'] ?? ''));

            if ($type === null) {
                throw new InvalidArgumentException('Unknown direct cost type.');
            }

            $amount = Money::of($data['amount']);

            if (! $amount->isPositive()) {
                throw new InvalidArgumentException('A direct cost must be greater than zero.');
            }

            $creditAccountId = (int) $data['credit_account_id'];

            if (! ProductionDirectCost::creditAccountQuery()->whereKey($creditAccountId)->exists()) {
                throw new InvalidArgumentException('A direct cost can only be credited to a cash, bank or liability account.');
            }

            $reason = static::normaliseReason($data['reason'] ?? null);
            $fiscalYear = ClosedFiscalYearGuard::assertDateInOpenYear($date, $actor, $reason);

            $cost = $entry->directCosts()->create([
                'date' => $date,
                'cost_type' => $type,
                'description' => $data['description'] ?? null,
                'amount' => $amount,
                'credit_account_id' => $creditAccountId,
            ]);

            $narration = "Direct cost ({$type->label()}) - Production Entry #{$entry->number}";

            $voucher = JournalVoucher::post([
                'voucher_type' => VoucherType::Production->value,
                'date' => $date,
                'narration' => $narration,
                'fiscal_year_id' => $fiscalYear->id,
                'reason' => $reason,
            ], [
                ['account_id' => ManufacturingAccounts::id(ManufacturingAccounts::WIP), 'debit' => $amount->toString(), 'credit' => '0.00'],
                ['account_id' => $creditAccountId, 'debit' => '0.00', 'credit' => $amount->toString()],
            ], $actor);

            $cost->update(['journal_voucher_id' => $voucher->id]);

            $entry->writeWipRow($date, ProductionWipEntry::KIND_DIRECT_COST, null, null, $amount, 1, $cost);

            return $cost->fresh();
        });
    }

    /**
     * Reverses a direct cost while the entry is in progress: the voucher is
     * reversed (JournalVoucher::reverse, open year only) and its WIP row is
     * cancelled.
     *
     * Refused when the cost exceeds the entry's remaining non-material WIP:
     * after a partial completion part of every direct cost has already moved
     * on to Finished Goods, and taking the whole amount back out of WIP would
     * drive that part of the balance negative.
     */
    public function cancelDirectCost(ProductionDirectCost $cost, User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to cancel a direct cost.');
        }

        $this->withLockedSelf(function (self $entry) use ($cost, $actor, $reason) {
            $entry->assertInProgress('cancel a direct cost of');

            /** @var ProductionDirectCost $locked */
            $locked = ProductionDirectCost::query()->whereKey($cost->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->production_entry_id !== (int) $entry->id) {
                throw new InvalidArgumentException('This direct cost does not belong to this production entry.');
            }

            if ($locked->cancelled_at !== null) {
                throw new InvalidArgumentException('This direct cost has already been cancelled.');
            }

            $amount = Money::of($locked->amount);
            $nonMaterial = $entry->nonMaterialWip();

            if ($amount->isGreaterThan($nonMaterial)) {
                throw new InvalidArgumentException(
                    "This direct cost cannot be cancelled: only {$nonMaterial->format()} of direct cost is still in work in process, the rest has already been transferred to finished goods."
                );
            }

            if ($locked->journal_voucher_id !== null) {
                JournalVoucher::reverse(
                    JournalVoucher::query()->findOrFail($locked->journal_voucher_id),
                    $actor,
                    Str::limit("Cancelled direct cost - Production Entry #{$entry->number}: {$reason}", 250),
                );
            }

            $locked->update([
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            ProductionWipEntry::query()
                ->where('source_type', $locked->getMorphClass())
                ->where('source_id', $locked->getKey())
                ->update(['cancelled' => true]);

            $cost->forceFill($locked->fresh()->getAttributes())->syncOriginal();
        });
    }

    /**
     * Returns unused raw material from WIP back to the entry's store.
     *
     * Costed at this entry's own WIP rate of the item (its WIP value / WIP
     * quantity), not the store's moving average (MC6), and returning the
     * whole WIP quantity returns the whole WIP value, so no paisa residue is
     * left on the item. Posts a MaterialReturn movement (cost_value = that
     * cost), Dr AS12 / Cr AS13, and a `material_out` WIP row whose source is
     * the voucher (a return has no document row of its own; cancel() finds
     * the voucher to reverse through it). The movement's reference is this
     * entry.
     *
     * $reason is the correction reason for a date in a year reopened for
     * correction (see the class docblock).
     *
     * Lock order: this entry -> the item -> fiscal year.
     */
    public function returnMaterial(int $itemId, string $quantity, string $date, User $actor, ?string $reason = null): void
    {
        $reason = static::normaliseReason($reason);

        $this->withLockedSelf(function (self $entry) use ($itemId, $quantity, $date, $actor, $reason) {
            $entry->assertInProgress('return material from');

            $returned = Quantity::of($quantity);

            if (! $returned->isPositive()) {
                throw new InvalidArgumentException('The quantity returned must be greater than zero.');
            }

            /** @var Item|null $item */
            $item = Item::lockForStockOut([$itemId])->first();

            if ($item === null) {
                throw new InvalidArgumentException("Unknown item [{$itemId}].");
            }

            if ($item->inventory_class !== InventoryClass::RawMaterial) {
                throw new InvalidArgumentException("\"{$item->name}\" is not a raw material.");
            }

            $date = $entry->assertPostingDate($date);
            $fiscalYear = ClosedFiscalYearGuard::assertDateInOpenYear($date, $actor, $reason);

            $inWip = WipLedger::quantityByItem($entry)[$item->id] ?? Quantity::zero();
            $wipValue = WipLedger::valueByItem($entry)[$item->id] ?? Money::zero();

            if ($returned->isGreaterThan($inWip)) {
                throw new InvalidArgumentException(
                    "Cannot return {$returned->formatQuantity()} of \"{$item->name}\": only {$inWip->formatQuantity()} is in work in process on this entry."
                );
            }

            $cost = $returned->isEqualTo($inWip)
                ? $wipValue
                : $wipValue->multipliedByFraction($returned, $inWip);

            if (! $cost->isPositive()) {
                throw new InvalidArgumentException("\"{$item->name}\" has no work-in-process value left to return on this entry.");
            }

            $item->recordStockMovement(
                StockMovementType::MaterialReturn,
                $returned,
                $date,
                (int) $entry->store_id,
                $entry,
                null,
                $cost,
                Str::limit("Material return - Production Entry #{$entry->number}", 250),
                costValue: $cost,
            );

            $voucher = JournalVoucher::post([
                'voucher_type' => VoucherType::Production->value,
                'date' => $date,
                'narration' => Str::limit("Material return of {$item->name} - Production Entry #{$entry->number}", 250),
                'fiscal_year_id' => $fiscalYear->id,
                'reason' => $reason,
            ], [
                ['account_id' => ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL), 'debit' => $cost->toString(), 'credit' => '0.00'],
                ['account_id' => ManufacturingAccounts::id(ManufacturingAccounts::WIP), 'debit' => '0.00', 'credit' => $cost->toString()],
            ], $actor);

            $entry->writeWipRow($date, ProductionWipEntry::KIND_MATERIAL_OUT, $item->id, $returned, $cost, -1, $voucher);
        });
    }

    /**
     * Transfers finished units out of WIP into Finished Goods.
     *
     * remainingPlanned = planned_quantity - sum(live non-final completed
     * quantity). A non-final completion takes
     * Money::round(wipBalance x quantity / remainingPlanned) and must be
     * smaller than remainingPlanned (otherwise it is the last one and has to
     * be marked final). A final completion takes the whole WIP balance, so
     * nothing is left orphaned, and closes the entry; its quantity may be
     * more or less than planned (actual yield).
     *
     * The value taken is split across the WIP subledger in the same
     * proportion (see consumptionRows()), so each raw material's WIP
     * quantity and value fall together and a later return is still costed at
     * the right per-item rate.
     *
     * $reason is the correction reason for a date in a year reopened for
     * correction (see the class docblock).
     *
     * Lock order: this entry -> the finished item -> fiscal year.
     */
    public function complete(string $date, string $quantity, bool $isFinal, User $actor, ?string $reason = null): ProductionCompletion
    {
        $reason = static::normaliseReason($reason);

        return $this->withLockedSelf(function (self $entry) use ($date, $quantity, $isFinal, $actor, $reason) {
            $entry->assertInProgress('complete');

            $produced = Quantity::of($quantity);

            if (! $produced->isPositive()) {
                throw new InvalidArgumentException('The quantity completed must be greater than zero.');
            }

            /** @var Item $finishedItem */
            $finishedItem = Item::lockForStockOut([(int) $entry->finished_item_id])->firstOrFail();

            $date = $entry->assertPostingDate($date);
            $fiscalYear = ClosedFiscalYearGuard::assertDateInOpenYear($date, $actor, $reason);

            $wipBalance = WipLedger::balance($entry);

            if (! $wipBalance->isPositive()) {
                throw new InvalidArgumentException('There is no work-in-process value on this entry to transfer to finished goods.');
            }

            $remainingPlanned = $entry->remainingPlannedQuantity();

            if ($isFinal) {
                $cost = $wipBalance;
                $rows = $entry->consumptionRows($wipBalance, null, null);
            } else {
                if ($produced->isGreaterThanOrEqualTo($remainingPlanned)) {
                    throw new InvalidArgumentException(
                        "Only {$remainingPlanned->formatQuantity()} of the planned quantity remains. Mark this completion as final to finish the entry."
                    );
                }

                $cost = $wipBalance->multipliedByFraction($produced, $remainingPlanned);

                if (! $cost->isPositive()) {
                    throw new InvalidArgumentException('This quantity is too small to carry any cost. Complete a larger quantity.');
                }

                $rows = $entry->consumptionRows($cost, $produced, $remainingPlanned);
            }

            $unitCost = Quantity::of(
                $cost->toBigDecimal()->dividedBy($produced->toBigDecimal(), 4, RoundingMode::HalfUp)
            );

            $completion = $entry->completions()->create([
                'date' => $date,
                'quantity' => $produced,
                'cost' => $cost,
                'unit_cost' => $unitCost,
                'is_final' => $isFinal,
            ]);

            $movement = $finishedItem->recordStockMovement(
                StockMovementType::ProductionReceipt,
                $produced,
                $date,
                (int) $entry->store_id,
                $completion,
                null,
                $cost,
                "Production receipt - Production Entry #{$entry->number}",
                costValue: $cost,
            );

            $voucher = JournalVoucher::post([
                'voucher_type' => VoucherType::Production->value,
                'date' => $date,
                'narration' => Str::limit(($isFinal ? 'Final' : 'Partial')." completion of {$finishedItem->name} - Production Entry #{$entry->number}", 250),
                'fiscal_year_id' => $fiscalYear->id,
                'reason' => $reason,
            ], [
                ['account_id' => ManufacturingAccounts::id(ManufacturingAccounts::FINISHED_GOODS), 'debit' => $cost->toString(), 'credit' => '0.00'],
                ['account_id' => ManufacturingAccounts::id(ManufacturingAccounts::WIP), 'debit' => '0.00', 'credit' => $cost->toString()],
            ], $actor);

            $completion->update([
                'stock_movement_id' => $movement->id,
                'journal_voucher_id' => $voucher->id,
            ]);

            foreach ($rows as $row) {
                $entry->writeWipRow($date, ProductionWipEntry::KIND_COMPLETION, $row['item_id'], $row['quantity'], $row['value'], -1, $completion);
            }

            if ($isFinal) {
                $entry->update([
                    'status' => self::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
            }

            return $completion->fresh();
        });
    }

    /**
     * Cancels the latest live completion of this entry.
     *
     * Only the latest: a partial completion is costed from the WIP the
     * previous ones left (wipBalance x quantity / remainingPlanned), so
     * undoing an earlier one would mean re-costing every later one. A
     * non-final completion needs the entry in progress; the final one needs
     * it completed, and cancelling it puts the entry back in progress.
     *
     * Undoing it is the exact mirror of complete(): the Dr AS14 / Cr AS13
     * voucher is reversed (JournalVoucher::reverse(), open year only), the
     * ProductionReceipt movement is cancelled (P1 for the finished good: the
     * same cost_value leaves the subledger that leaves AS14), and the
     * completion's `completion` WIP rows are cancelled, which puts back the
     * very per-item quantities and per-bucket values they took (P2). The
     * remaining planned quantity ignores cancelled completions, so the next
     * completion is costed as if this one had never been posted.
     *
     * Refused when the store no longer holds the finished units from the
     * completion date onwards (sold, issued or moved) - taking them back out
     * would drive finished-good stock negative, which a perpetual item never
     * allows - or when the item's remaining perpetual value is less than the
     * completion's cost.
     *
     * Lock order: this entry -> the completion row -> the finished item ->
     * fiscal year (inside JournalVoucher::reverse()).
     */
    public function cancelCompletion(ProductionCompletion $completion, User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to cancel a completion.');
        }

        $this->withLockedSelf(function (self $entry) use ($completion, $actor, $reason) {
            /** @var ProductionCompletion $locked */
            $locked = ProductionCompletion::query()->whereKey($completion->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->production_entry_id !== (int) $entry->id) {
                throw new InvalidArgumentException('This completion does not belong to this production entry.');
            }

            if ($locked->cancelled_at !== null) {
                throw new InvalidArgumentException('This completion has already been cancelled.');
            }

            $expectedStatus = $locked->is_final ? self::STATUS_COMPLETED : self::STATUS_IN_PROGRESS;

            if ($entry->status !== $expectedStatus) {
                throw new InvalidArgumentException(
                    "Cannot cancel this completion: production entry #{$entry->number} is ".str_replace('_', ' ', (string) $entry->status).'.'
                );
            }

            $hasLater = $entry->completions()
                ->whereNull('cancelled_at')
                ->where('id', '>', $locked->id)
                ->exists();

            if ($hasLater) {
                throw new InvalidArgumentException('Only the latest completion can be cancelled. Cancel the later completions first.');
            }

            $date = $locked->date->toDateString();
            $entry->assertCancellableInOpenYear($date, 'completion');

            /** @var Item $finishedItem */
            $finishedItem = Item::lockForStockOut([(int) $entry->finished_item_id])->firstOrFail();

            $entry->assertStockCanBeWithdrawn(
                $finishedItem,
                Quantity::of($locked->quantity),
                Money::of($locked->cost),
                $date,
                'was produced by this completion',
            );

            JournalVoucher::reverse(
                JournalVoucher::query()->findOrFail($locked->journal_voucher_id),
                $actor,
                Str::limit("Cancelled completion - Production Entry #{$entry->number}: {$reason}", 250),
            );

            if ($locked->stock_movement_id !== null) {
                ItemStockMovement::query()->whereKey($locked->stock_movement_id)->update(['cancelled' => true]);
            }

            ProductionWipEntry::query()
                ->where('source_type', $locked->getMorphClass())
                ->where('source_id', $locked->getKey())
                ->update(['cancelled' => true]);

            $locked->update([
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            if ($locked->is_final) {
                $entry->update([
                    'status' => self::STATUS_IN_PROGRESS,
                    'completed_at' => null,
                ]);
            }

            $completion->forceFill($locked->fresh()->getAttributes())->syncOriginal();
        });
    }

    /**
     * Cancels the latest live material return of this entry, identified by
     * the voucher it posted (a return has no document row of its own; its
     * `material_out` WIP row points at that voucher).
     *
     * Only the latest, for the same reason as cancelCompletion(), and only
     * while the entry is in progress. The Dr AS12 / Cr AS13 voucher is
     * reversed, the MaterialReturn movement and the WIP row are cancelled,
     * so the item's WIP quantity and value on this entry are exactly what
     * they were before the return (P2) and AS12 drops by the movement's
     * cost_value (P1).
     *
     * The MaterialReturn movement references this entry, not the voucher.
     * Because only the latest return can ever be cancelled on its own (and
     * cancel() cancels them all together), the latest live MaterialReturn
     * movement of this entry is always the one this return wrote; it is
     * matched against the WIP row's item, quantity and value before
     * anything is touched, and a mismatch stops the cancel.
     *
     * Refused when the store no longer holds the returned quantity from the
     * return date onwards (it was issued again, sold or moved).
     *
     * Lock order: this entry -> the raw item -> fiscal year (inside
     * JournalVoucher::reverse()).
     */
    public function cancelMaterialReturn(JournalVoucher $voucher, User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to cancel a material return.');
        }

        $this->withLockedSelf(function (self $entry) use ($voucher, $actor, $reason) {
            $entry->assertInProgress('cancel a material return of');

            $returnRows = fn () => $entry->wipEntries()
                ->where('kind', ProductionWipEntry::KIND_MATERIAL_OUT)
                ->where('source_type', (new JournalVoucher)->getMorphClass());

            /** @var ProductionWipEntry|null $row */
            $row = $returnRows()->where('source_id', $voucher->getKey())->first();

            if ($row === null || $row->item_id === null) {
                throw new InvalidArgumentException('This material return does not belong to this production entry.');
            }

            if ($row->cancelled) {
                throw new InvalidArgumentException('This material return has already been cancelled.');
            }

            if ($returnRows()->where('cancelled', false)->where('id', '>', $row->id)->exists()) {
                throw new InvalidArgumentException('Only the latest material return can be cancelled. Cancel the later returns first.');
            }

            $date = $row->date->toDateString();
            $entry->assertCancellableInOpenYear($date, 'material return');

            /** @var Item $item */
            $item = Item::lockForStockOut([(int) $row->item_id])->firstOrFail();
            $quantity = Quantity::of($row->quantity ?? '0');
            $value = Money::of($row->value);

            /** @var ItemStockMovement|null $movement */
            $movement = ItemStockMovement::query()
                ->where('reference_type', $entry->getMorphClass())
                ->where('reference_id', $entry->getKey())
                ->where('movement_type', StockMovementType::MaterialReturn->value)
                ->where('cancelled', false)
                ->orderByDesc('id')
                ->first();

            if ($movement === null
                || (int) $movement->item_id !== (int) $item->id
                || ! Quantity::of($movement->quantity)->isEqualTo($quantity)
                || $movement->cost_value === null
                || ! Money::of($movement->cost_value)->isEqualTo($value)) {
                throw new RuntimeException("The stock movement of this material return on Production Entry #{$entry->number} could not be matched; it cannot be cancelled until that is corrected.");
            }

            $entry->assertStockCanBeWithdrawn($item, $quantity, $value, $date, 'was returned to the store by this return');

            JournalVoucher::reverse(
                JournalVoucher::query()->findOrFail($row->source_id),
                $actor,
                Str::limit("Cancelled material return of {$item->name} - Production Entry #{$entry->number}: {$reason}", 250),
            );

            ItemStockMovement::query()->whereKey($movement->id)->update(['cancelled' => true]);
            ProductionWipEntry::query()->whereKey($row->id)->update(['cancelled' => true]);
        });
    }

    /**
     * Gives up on the entry: the whole remaining WIP balance is written off,
     * Dr EXE24 Production Loss / Cr AS13, with `abandon` WIP rows draining
     * every item and the non-material remainder. Earlier completions stand.
     *
     * Dated today in Nepal, held to the open year's last day exactly as
     * JournalVoucher::reverse() dates a reversal, and never before the
     * entry's own date. The reason is kept in the cancel_* columns; the
     * status (abandoned, not cancelled) tells the two apart.
     */
    public function abandon(User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to abandon a production entry.');
        }

        $this->withLockedSelf(function (self $entry) use ($actor, $reason) {
            $entry->assertInProgress('abandon');

            $openYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

            if ($openYear === null) {
                throw new InvalidArgumentException(FiscalYear::NO_OPEN_YEAR_MESSAGE);
            }

            $date = min(now()->setTimezone('Asia/Kathmandu')->toDateString(), $openYear->end_date->toDateString());
            $date = max($date, $entry->date->toDateString());
            $fiscalYear = ClosedFiscalYearGuard::assertDateInOpenYear($date, $actor);

            $wipBalance = WipLedger::balance($entry);

            if ($wipBalance->isNegative()) {
                throw new RuntimeException("The work-in-process ledger of Production Entry #{$entry->number} is negative; it cannot be abandoned until that is corrected.");
            }

            if ($wipBalance->isPositive()) {
                $rows = $entry->consumptionRows($wipBalance, null, null);

                $voucher = JournalVoucher::post([
                    'voucher_type' => VoucherType::Production->value,
                    'date' => $date,
                    'narration' => Str::limit("Production Entry #{$entry->number} abandoned: {$reason}", 250),
                    'fiscal_year_id' => $fiscalYear->id,
                ], [
                    ['account_id' => ManufacturingAccounts::id(ManufacturingAccounts::PRODUCTION_LOSS), 'debit' => $wipBalance->toString(), 'credit' => '0.00'],
                    ['account_id' => ManufacturingAccounts::id(ManufacturingAccounts::WIP), 'debit' => '0.00', 'credit' => $wipBalance->toString()],
                ], $actor);

                foreach ($rows as $row) {
                    $entry->writeWipRow($date, ProductionWipEntry::KIND_ABANDON, $row['item_id'], $row['quantity'], $row['value'], -1, $voucher);
                }
            }

            $entry->update([
                'status' => self::STATUS_ABANDONED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);
        });
    }

    /**
     * Undoes an in-progress entry that has produced nothing, in the open
     * year only (C4/C5):
     *
     * - every material return is reversed (its voucher through
     *   JournalVoucher::reverse(), its MaterialReturn movement and WIP row
     *   cancelled), after checking the store still holds the returned stock
     *   from the earliest return onwards, so un-returning cannot drive raw
     *   material negative;
     * - every live direct cost is reversed the same way;
     * - the notes are detached back to unattached (their own vouchers
     *   stay), together with their `material_in` WIP rows, so each note can
     *   be cancelled on its own or attached to another entry.
     *
     * Refused while any completion exists: its cost already sits in
     * Finished Goods and may have been sold. Lock order: this entry -> the
     * attached notes -> the direct cost rows -> the returned items -> fiscal
     * year.
     */
    public function cancel(User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to cancel a production entry.');
        }

        $this->withLockedSelf(function (self $entry) use ($actor, $reason) {
            $entry->assertInProgress('cancel');

            $fiscalYear = FiscalYear::query()->findOrFail($entry->fiscal_year_id);

            if ($fiscalYear->status !== FiscalYearStatus::Open) {
                throw new InvalidArgumentException(
                    "This production entry belongs to closed fiscal year {$fiscalYear->name} and cannot be cancelled."
                );
            }

            if ($entry->completions()->whereNull('cancelled_at')->exists()) {
                throw new InvalidArgumentException('A production entry with completed output cannot be cancelled. Abandon it instead.');
            }

            // The attached notes are documents, so they are locked here,
            // before any item, rather than implicitly by the detaching
            // update at the end (MaterialIssueNote::cancel() takes note ->
            // items, and the opposite order could deadlock against it).
            MaterialIssueNote::query()
                ->where('production_entry_id', $entry->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $costs = ProductionDirectCost::query()
                ->where('production_entry_id', $entry->id)
                ->whereNull('cancelled_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $returnRows = $entry->wipEntries()
                ->where('kind', ProductionWipEntry::KIND_MATERIAL_OUT)
                ->where('cancelled', false)
                ->orderBy('id')
                ->get();

            $entry->assertReturnsCanBeUndone($returnRows);

            $narration = Str::limit("Cancelled Production Entry #{$entry->number}: {$reason}", 250);

            foreach ($returnRows as $row) {
                if ($row->source_type === (new JournalVoucher)->getMorphClass() && $row->source_id !== null) {
                    JournalVoucher::reverse(JournalVoucher::query()->findOrFail($row->source_id), $actor, $narration);
                }
            }

            ItemStockMovement::query()
                ->where('reference_type', $entry->getMorphClass())
                ->where('reference_id', $entry->getKey())
                ->where('movement_type', StockMovementType::MaterialReturn->value)
                ->where('cancelled', false)
                ->update(['cancelled' => true]);

            ProductionWipEntry::query()->whereKey($returnRows->modelKeys())->update(['cancelled' => true]);

            foreach ($costs as $cost) {
                if ($cost->journal_voucher_id !== null) {
                    JournalVoucher::reverse(JournalVoucher::query()->findOrFail($cost->journal_voucher_id), $actor, $narration);
                }

                $cost->update([
                    'cancelled_by' => $actor->id,
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason,
                ]);

                ProductionWipEntry::query()
                    ->where('source_type', $cost->getMorphClass())
                    ->where('source_id', $cost->getKey())
                    ->update(['cancelled' => true]);
            }

            ProductionWipEntry::query()
                ->where('production_entry_id', $entry->id)
                ->where('kind', ProductionWipEntry::KIND_MATERIAL_IN)
                ->update(['production_entry_id' => null]);

            MaterialIssueNote::query()
                ->where('production_entry_id', $entry->id)
                ->update(['production_entry_id' => null]);

            $entry->update([
                'status' => self::STATUS_CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);
        });
    }

    /**
     * The production cost sheet (MC6, spec formula): Total Production Cost =
     * sum(raw material used cost) + other direct costs; Cost per Unit = cost
     * transferred to Finished Goods / finished units produced. Money figures
     * are 2dp strings, quantities and the unit cost 4dp strings. Only live
     * (non-cancelled) rows count.
     *
     * "Raw material used" is issued minus returned. Material still sitting
     * in WIP is part of the total production cost but not yet of the
     * transferred figure; `wip_balance` is what is left, so on a completed
     * entry total_production_cost == transferred_to_finished_goods and
     * wip_balance is 0.00.
     *
     * @return array{
     *     entry_id: int,
     *     number: int,
     *     status: string,
     *     finished_item_id: int,
     *     materials: list<array{item_id: int, item_name: string, issued_quantity: string, issued_cost: string, returned_quantity: string, returned_cost: string, net_quantity: string, net_cost: string}>,
     *     materials_total: string,
     *     direct_costs: list<array{cost_type: string, label: string, amount: string}>,
     *     direct_costs_total: string,
     *     total_production_cost: string,
     *     planned_quantity: string,
     *     completed_quantity: string,
     *     transferred_to_finished_goods: string,
     *     cost_per_unit: string|null,
     *     abandoned_value: string,
     *     wip_balance: string,
     * }
     */
    public function costSheet(): array
    {
        $rows = $this->wipEntries()->where('cancelled', false)->orderBy('id')->get();

        $materials = [];

        foreach ($rows as $row) {
            if (! in_array($row->kind, [ProductionWipEntry::KIND_MATERIAL_IN, ProductionWipEntry::KIND_MATERIAL_OUT], true) || $row->item_id === null) {
                continue;
            }

            $itemId = (int) $row->item_id;
            $materials[$itemId] ??= [
                'issued_quantity' => Quantity::zero(),
                'issued_cost' => Money::zero(),
                'returned_quantity' => Quantity::zero(),
                'returned_cost' => Money::zero(),
            ];

            $prefix = $row->kind === ProductionWipEntry::KIND_MATERIAL_IN ? 'issued' : 'returned';
            $materials[$itemId]["{$prefix}_quantity"] = $materials[$itemId]["{$prefix}_quantity"]->plus(Quantity::of($row->quantity ?? '0'));
            $materials[$itemId]["{$prefix}_cost"] = $materials[$itemId]["{$prefix}_cost"]->plus(Money::of($row->value));
        }

        ksort($materials);
        $names = Item::query()->whereKey(array_keys($materials))->pluck('name', 'id');

        $materialLines = [];
        $materialsTotal = Money::zero();

        foreach ($materials as $itemId => $figures) {
            $netCost = $figures['issued_cost']->minus($figures['returned_cost']);
            $materialsTotal = $materialsTotal->plus($netCost);

            $materialLines[] = [
                'item_id' => $itemId,
                'item_name' => (string) ($names[$itemId] ?? "#{$itemId}"),
                'issued_quantity' => $figures['issued_quantity']->toString(),
                'issued_cost' => $figures['issued_cost']->toString(),
                'returned_quantity' => $figures['returned_quantity']->toString(),
                'returned_cost' => $figures['returned_cost']->toString(),
                'net_quantity' => $figures['issued_quantity']->minus($figures['returned_quantity'])->toString(),
                'net_cost' => $netCost->toString(),
            ];
        }

        $costsByType = [];

        foreach ($this->directCosts()->whereNull('cancelled_at')->get() as $cost) {
            $type = $cost->cost_type->value;
            $costsByType[$type] = ($costsByType[$type] ?? Money::zero())->plus(Money::of($cost->amount));
        }

        $directLines = [];
        $directTotal = Money::zero();

        foreach (DirectCostType::cases() as $type) {
            if (! isset($costsByType[$type->value])) {
                continue;
            }

            $directTotal = $directTotal->plus($costsByType[$type->value]);
            $directLines[] = [
                'cost_type' => $type->value,
                'label' => $type->label(),
                'amount' => $costsByType[$type->value]->toString(),
            ];
        }

        $completions = $this->completions()->whereNull('cancelled_at')->get();
        $completedQuantity = Quantity::sum($completions->map(fn (ProductionCompletion $completion) => Quantity::of($completion->quantity)));
        $transferred = Money::sum($completions->map(fn (ProductionCompletion $completion) => Money::of($completion->cost)));

        $abandoned = Money::sum(
            $rows->where('kind', ProductionWipEntry::KIND_ABANDON)->map(fn (ProductionWipEntry $row) => Money::of($row->value))
        );

        $costPerUnit = $completedQuantity->isPositive()
            ? $transferred->toBigDecimal()->dividedBy($completedQuantity->toBigDecimal(), 4, RoundingMode::HalfUp)->toString()
            : null;

        return [
            'entry_id' => (int) $this->id,
            'number' => (int) $this->number,
            'status' => (string) $this->status,
            'finished_item_id' => (int) $this->finished_item_id,
            'materials' => $materialLines,
            'materials_total' => $materialsTotal->toString(),
            'direct_costs' => $directLines,
            'direct_costs_total' => $directTotal->toString(),
            'total_production_cost' => $materialsTotal->plus($directTotal)->toString(),
            'planned_quantity' => Quantity::of($this->planned_quantity)->toString(),
            'completed_quantity' => $completedQuantity->toString(),
            'transferred_to_finished_goods' => $transferred->toString(),
            'cost_per_unit' => $costPerUnit,
            'abandoned_value' => $abandoned->toString(),
            'wip_balance' => WipLedger::balance($this)->toString(),
        ];
    }

    /**
     * planned_quantity minus every live non-final completion. Always
     * positive while the entry is in progress, because a non-final
     * completion is refused unless it is strictly smaller than this.
     */
    public function remainingPlannedQuantity(): Quantity
    {
        $done = Quantity::sum(
            $this->completions()
                ->whereNull('cancelled_at')
                ->where('is_final', false)
                ->pluck('quantity')
                ->map(fn ($quantity) => Quantity::of($quantity))
        );

        return Quantity::of($this->planned_quantity)->minus($done);
    }

    /**
     * Runs $callback in a transaction against a fresh copy of this row taken
     * under lockForUpdate() (the first lock of every method, MC6), then
     * copies the fresh state back onto $this so the caller's instance is not
     * stale.
     *
     * @template TReturn
     *
     * @param  callable(self): TReturn  $callback
     * @return TReturn
     */
    private function withLockedSelf(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            /** @var self $fresh */
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            $result = $callback($fresh);

            $this->forceFill($fresh->fresh()->getAttributes())->syncOriginal();

            return $result;
        });
    }

    private function assertInProgress(string $action): void
    {
        if ($this->status !== self::STATUS_IN_PROGRESS) {
            throw new InvalidArgumentException("Cannot {$action} production entry #{$this->number}: it is ".str_replace('_', ' ', (string) $this->status).'.');
        }
    }

    /**
     * Normalises a posting date and refuses one before the entry's own date.
     */
    private function assertPostingDate(string $date): string
    {
        $date = static::normaliseDate($date);

        if ($date < $this->date->toDateString()) {
            throw new InvalidArgumentException("The date {$date} is before the production entry's date {$this->date->toDateString()}.");
        }

        return $date;
    }

    /**
     * A blank correction reason is no reason: null, so an open-year posting
     * never stores an empty string on its voucher and the guard's "reason
     * required" check sees exactly what the user meant.
     */
    private static function normaliseReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : $reason;
    }

    /**
     * A single completion or return is cancelled through
     * JournalVoucher::reverse(), which only reverses vouchers of the open
     * year (C4/C5). Checked up front so the user gets a message about this
     * row rather than reverse()'s generic "record a return or credit note".
     */
    private function assertCancellableInOpenYear(string $date, string $what): void
    {
        $fiscalYear = FiscalYear::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        if ($fiscalYear === null || $fiscalYear->status !== FiscalYearStatus::Open) {
            $name = $fiscalYear?->name ?? 'a closed fiscal year';

            throw new InvalidArgumentException("This {$what} is dated {$date} in {$name}, which is not the open fiscal year, and cannot be cancelled.");
        }
    }

    /**
     * Refuses to take $quantity of a perpetual $item back out of this
     * entry's store when the store's lowest stock from $from onwards does
     * not cover it (negative perpetual stock is never allowed, whatever
     * allow_negative_stock says), or when the item's remaining perpetual
     * value is less than $value (the moving average would turn negative).
     * The caller holds the item lock.
     */
    private function assertStockCanBeWithdrawn(Item $item, Quantity $quantity, Money $value, string $from, string $how): void
    {
        $lowest = $item->lowestStockFrom($from, (int) $this->store_id);

        if ($lowest->isLessThan($quantity)) {
            $shortfall = $quantity->minus($lowest);

            throw new InvalidArgumentException(
                "Cannot cancel: {$quantity->formatQuantity()} of \"{$item->name}\" {$how}, but the store's lowest stock since {$from} is {$lowest->formatQuantity()} "
                ."(short by {$shortfall->formatQuantity()}). It has since been sold, issued or moved."
            );
        }

        $remainingValue = PerpetualCosting::valueBalance($item);

        if ($remainingValue->isLessThan($value)) {
            throw new InvalidArgumentException(
                "Cannot cancel: \"{$item->name}\" has only {$remainingValue->format()} of stock value left, less than the {$value->format()} this would take back out."
            );
        }
    }

    private static function normaliseDate(string $date): string
    {
        try {
            return CarbonImmutable::parse($date)->toDateString();
        } catch (Throwable) {
            throw new InvalidArgumentException("\"{$date}\" is not a valid date.");
        }
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private static function normaliseIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * Locks the notes ascending by id and checks each one is posted, not yet
     * attached to any entry, and from $storeId.
     *
     * @param  list<int>  $ids
     * @return Collection<int, MaterialIssueNote>
     */
    private static function lockAttachableNotes(array $ids, int $storeId): Collection
    {
        $notes = MaterialIssueNote::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

        if ($notes->count() !== count($ids)) {
            throw new InvalidArgumentException('One or more material issue notes do not exist.');
        }

        foreach ($notes as $note) {
            if ($note->status !== MaterialIssueNote::STATUS_POSTED) {
                throw new InvalidArgumentException("Material issue note #{$note->number} is cancelled and cannot be attached.");
            }

            if ($note->production_entry_id !== null) {
                throw new InvalidArgumentException("Material issue note #{$note->number} is already attached to a production entry.");
            }

            if ((int) $note->store_id !== $storeId) {
                throw new InvalidArgumentException("Material issue note #{$note->number} is from a different store than the production entry.");
            }
        }

        return $notes;
    }

    /**
     * Points the notes and their WIP rows at this entry. Query-builder
     * updates, as MaterialIssueNote::post() does for its movement links.
     *
     * @param  array<int, int>  $noteIds
     */
    private function moveNotesOnto(array $noteIds): void
    {
        MaterialIssueNote::query()->whereKey($noteIds)->update(['production_entry_id' => $this->id]);

        ProductionWipEntry::query()
            ->whereIn('material_issue_note_id', $noteIds)
            ->whereNull('production_entry_id')
            ->update(['production_entry_id' => $this->id]);
    }

    /**
     * WIP value not attributable to any raw material on this entry: the
     * direct costs still in WIP. Never negative by construction (see
     * consumptionRows()).
     */
    private function nonMaterialWip(): Money
    {
        return WipLedger::balance($this)->minus(Money::sum(WipLedger::valueByItem($this)));
    }

    /**
     * The WIP subledger rows that take $amount out of this entry.
     *
     * The entry's WIP is split into one bucket per raw material (its WIP
     * value and quantity) plus one non-material bucket (direct costs).
     *
     * - Drain ($numerator null: final completion, abandon): every bucket is
     *   emptied, so $amount must be the whole balance.
     * - Partial: $amount is allocated across the buckets in proportion to
     *   their values with Money::allocate() (largest remainder), so the rows
     *   sum to exactly $amount and no bucket ever goes below zero (a share
     *   can only exceed floor(value x amount / balance) by the one leftover
     *   paisa, and amount < balance). Each material's quantity falls by
     *   quantity x numerator / denominator, the same proportion as the
     *   completion, which is never more than it holds.
     *
     * Rows that would move neither quantity nor value are skipped.
     *
     * @return list<array{item_id: int|null, quantity: Quantity|null, value: Money}>
     */
    private function consumptionRows(Money $amount, ?Quantity $numerator, ?Quantity $denominator): array
    {
        $quantities = WipLedger::quantityByItem($this);
        $values = WipLedger::valueByItem($this);
        $itemIds = array_unique([...array_keys($quantities), ...array_keys($values)]);
        sort($itemIds);

        $balance = WipLedger::balance($this);
        $nonMaterial = $balance->minus(Money::sum($values));

        foreach ($itemIds as $itemId) {
            if (($quantities[$itemId] ?? Quantity::zero())->isNegative() || ($values[$itemId] ?? Money::zero())->isNegative()) {
                throw new RuntimeException("The work-in-process ledger of Production Entry #{$this->number} holds a negative balance for item #{$itemId}.");
            }
        }

        if ($nonMaterial->isNegative()) {
            throw new RuntimeException("The work-in-process ledger of Production Entry #{$this->number} holds more material value than its balance.");
        }

        if ($numerator === null || $denominator === null) {
            if (! $amount->isEqualTo($balance)) {
                throw new RuntimeException('A final transfer must take the whole work-in-process balance.');
            }

            $shares = array_map(fn (int $itemId) => $values[$itemId] ?? Money::zero(), $itemIds);
            $shares[] = $nonMaterial;
            $consumed = array_map(fn (int $itemId) => $quantities[$itemId] ?? Quantity::zero(), $itemIds);
        } else {
            $weights = array_map(fn (int $itemId) => $values[$itemId] ?? Money::zero(), $itemIds);
            $weights[] = $nonMaterial;
            $shares = $amount->allocate($weights);
            $consumed = array_map(
                fn (int $itemId) => ($quantities[$itemId] ?? Quantity::zero())->multipliedByFraction($numerator, $denominator),
                $itemIds,
            );
        }

        $rows = [];

        foreach ($itemIds as $index => $itemId) {
            if ($shares[$index]->isZero() && $consumed[$index]->isZero()) {
                continue;
            }

            $rows[] = ['item_id' => $itemId, 'quantity' => $consumed[$index], 'value' => $shares[$index]];
        }

        $nonMaterialShare = $shares[count($itemIds)];

        if (! $nonMaterialShare->isZero()) {
            $rows[] = ['item_id' => null, 'quantity' => null, 'value' => $nonMaterialShare];
        }

        return $rows;
    }

    /**
     * Refuses to un-return material the store no longer holds: per item, the
     * lowest stock from the earliest return onwards must cover everything
     * returned, or cancelling the returns would drive raw material negative.
     *
     * @param  Collection<int, ProductionWipEntry>  $returnRows
     */
    private function assertReturnsCanBeUndone(Collection $returnRows): void
    {
        if ($returnRows->isEmpty()) {
            return;
        }

        $byItem = $returnRows->groupBy('item_id');
        $items = Item::lockForStockOut($byItem->keys()->all())->keyBy('id');

        foreach ($byItem as $itemId => $rows) {
            $item = $items->get((int) $itemId);
            $returned = Quantity::sum($rows->map(fn (ProductionWipEntry $row) => Quantity::of($row->quantity ?? '0')));
            $earliest = $rows->map(fn (ProductionWipEntry $row) => $row->date->toDateString())->min();
            $lowest = $item->lowestStockFrom($earliest, (int) $this->store_id);

            if ($lowest->isLessThan($returned)) {
                throw new InvalidArgumentException(
                    "Cannot cancel: {$returned->formatQuantity()} of \"{$item->name}\" was returned from this entry, but the store no longer holds it (lowest stock since {$earliest}: {$lowest->formatQuantity()})."
                );
            }
        }
    }

    private function writeWipRow(string $date, string $kind, ?int $itemId, ?Quantity $quantity, Money $value, int $direction, Model $source): ProductionWipEntry
    {
        return ProductionWipEntry::create([
            'production_entry_id' => $this->id,
            'date' => $date,
            'kind' => $kind,
            'item_id' => $itemId,
            'quantity' => $quantity,
            'value' => $value,
            'direction' => $direction,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'cancelled' => false,
        ]);
    }

    /**
     * Claims the next production entry number in $fiscalYear's
     * VoucherType::ProductionEntry series: a header-only series no voucher
     * ever carries, claimed exactly like
     * JournalVoucher::nextVoucherNumber() (insertOrIgnore so a first claim
     * cannot race, then increment under a row lock). The caller holds the
     * fiscal year lock.
     */
    private static function claimNumber(FiscalYear $fiscalYear): int
    {
        VoucherSequence::query()->insertOrIgnore([
            'fiscal_year_id' => $fiscalYear->id,
            'voucher_type' => VoucherType::ProductionEntry->value,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = VoucherSequence::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('voucher_type', VoucherType::ProductionEntry)
            ->lockForUpdate()
            ->firstOrFail();

        $sequence->increment('last_number');

        return (int) $sequence->last_number;
    }
}
