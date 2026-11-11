<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\InventoryClass;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\PlacesStockDocument;
use App\Support\Branches\BranchContext;
use App\Support\Branches\SeriesNumber;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A posted issue of raw material from a store into work in process (MC5).
 * `number` is unique per (fiscal year, series_branch_id) and is also the number of the
 * MaterialIssue voucher it posts (Dr AS13 Work in Process / Cr AS12 Raw
 * Material Inventory, for the sum of the movements' cost_value), so the
 * printed note, the voucher and the ledger all quote the same number.
 *
 * One post() writes four things in one transaction and cancel() unwinds all
 * four: the MaterialIssue stock movements (cost_value from PerpetualCosting),
 * the voucher, the note and item rows, and the WIP ledger rows (kind
 * material_in). A note posted unattached can be picked up later by a
 * production entry (which sets production_entry_id); once attached it can no
 * longer be cancelled here, because its value is part of that entry's cost.
 *
 * Branches (plan 12, BR08): the note is filed in its store's branch (I5,
 * PlacesStockDocument) and both voucher lines post there, so the branch's
 * AS12 falls by exactly the cost that left its store and its AS13 rises by
 * the WIP it now holds (I8). An entry it is attached to has the same store,
 * hence the same branch. Cancelling reverses the voucher in that branch.
 */
#[Fillable([
    'fiscal_year_id',
    'number',
    'series_branch_id',
    'date',
    'store_id',
    'branch_id',
    'production_entry_id',
    'total_cost',
    'narration',
    'status',
    'journal_voucher_id',
    'reversal_journal_voucher_id',
    'cancelled_by',
    'cancelled_at',
    'cancel_reason',
    'created_by',
])]
class MaterialIssueNote extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use PlacesStockDocument;

    /**
     * Memoised series code of a branch-series note (documentNumber()).
     */
    private ?string $seriesCode = null;

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'total_cost' => Decimal::class.':2',
            'cancelled_at' => 'datetime',
            'branch_id' => 'integer',
            'series_branch_id' => 'integer',
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
     * @return BelongsTo<ProductionEntry, $this>
     */
    public function productionEntry(): BelongsTo
    {
        return $this->belongsTo(ProductionEntry::class);
    }

    /**
     * @return HasMany<MaterialIssueItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MaterialIssueItem::class);
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }

    /**
     * The printed note number: `MI-{n}` in the shared series (unchanged),
     * `MI-{series_code}-{n}` when the note was numbered in a branch series
     * of a per_branch year (plan 12, BR20), the same format its voucher
     * prints. A shared note costs no query.
     */
    public function documentNumber(): string
    {
        $seriesBranchId = (int) $this->series_branch_id;

        if ($seriesBranchId === 0) {
            return SeriesNumber::format((int) $this->number, null, 'MI');
        }

        $this->seriesCode ??= trim((string) Branch::query()->whereKey($seriesBranchId)->value('series_code'));

        return SeriesNumber::format((int) $this->number, $this->seriesCode !== '' ? $this->seriesCode : (string) $seriesBranchId, 'MI');
    }

    /**
     * Resolves the series codes of every branch-series note in the list with
     * one query (none when all are shared), so documentNumber() can be called
     * per row without an N+1. Notes must carry series_branch_id.
     *
     * @param  iterable<self>  $notes
     */
    public static function loadSeriesCodes(iterable $notes): void
    {
        $pending = [];

        foreach ($notes as $note) {
            if ($note instanceof self && (int) $note->series_branch_id !== 0 && $note->seriesCode === null) {
                $pending[] = $note;
            }
        }

        if ($pending === []) {
            return;
        }

        $codes = Branch::query()
            ->whereIn('id', array_values(array_unique(array_map(fn (self $note): int => (int) $note->series_branch_id, $pending))))
            ->pluck('series_code', 'id');

        foreach ($pending as $note) {
            $note->seriesCode = trim((string) $codes->get((int) $note->series_branch_id));
        }
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function reversalJournalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class, 'reversal_journal_voucher_id');
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
     * Issues raw material from a store into work in process.
     *
     * Lock order (MC5, matching the global posting order in
     * Item::lockForStockOut()): production entry -> items ascending id ->
     * fiscal year (taken inside JournalVoucher::post()).
     *
     * The stock check ignores `allow_negative_stock` on purpose: a negative
     * raw-material balance would make the moving average meaningless, so a
     * perpetual issue can never overdraw. It is store-scoped and aggregated
     * per item (an item may appear on several lines), checked both at the
     * document date and overall so a backdated issue cannot reach before the
     * first receipt either.
     *
     * Movements are written before the voucher because the voucher amount is
     * their summed cost_value; they are pointed at their MaterialIssueItem
     * once that row exists.
     *
     * `reason` is the correction reason, required (with fiscal_year.edit)
     * only when the date falls in a closed year reopened for correction. It
     * goes to ClosedFiscalYearGuard and JournalVoucher::post(), which rolls
     * the voucher forward and logs the correction, exactly as Purchase and
     * StockAdjustment do. cancel() needs no correction reason: it reverses
     * through JournalVoucher::reverse(), which only ever reverses a voucher
     * of the open year (C4/C5).
     *
     * @param  array{date: string, store_id?: int|string|null, branch_id?: int|string|null, production_entry_id?: int|string|null, narration?: string|null, reason?: string|null}  $data
     * @param  array<int, array{item_id: int|string, item_unit_id?: int|string|null, quantity: mixed}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            if ($lines === []) {
                throw new InvalidArgumentException('At least one raw material line is required.');
            }

            $reason = trim((string) ($data['reason'] ?? ''));
            $reason = $reason === '' ? null : $reason;

            $fiscalYear = ClosedFiscalYearGuard::assertDateInOpenYear($data['date'], $actor, $reason);

            $entry = null;

            if (! empty($data['production_entry_id'])) {
                $entry = ProductionEntry::query()->whereKey($data['production_entry_id'])->lockForUpdate()->firstOrFail();

                if ($entry->status !== ProductionEntry::STATUS_IN_PROGRESS) {
                    throw new InvalidArgumentException('Materials can only be issued to a production entry that is in progress.');
                }
            }

            // An issue to an entry with no store named defaults to the
            // entry's store (and so its branch); otherwise the usual stock
            // document default. Placement only reads stores and branches, so
            // the lock order (entry -> items -> fiscal year) is unchanged.
            $requestedStoreId = $data['store_id'] ?? null;

            if (($requestedStoreId === null || $requestedStoreId === '') && $entry !== null) {
                $requestedStoreId = (int) $entry->store_id;
            }

            $placement = static::placeStockDocument($actor, $data['branch_id'] ?? null, $requestedStoreId);
            $storeId = $placement->requireStoreId();

            if ($entry !== null) {
                if ((int) $entry->store_id !== $storeId) {
                    throw new InvalidArgumentException('The material issue store must match the production entry store.');
                }
            }

            $itemIds = collect($lines)->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            $items = Item::lockForStockOut($itemIds)->keyBy('id')->load('units');

            $prepared = [];
            $requested = [];

            foreach ($lines as $line) {
                $item = $items->get((int) $line['item_id']);

                if ($item === null) {
                    throw new InvalidArgumentException("Unknown item [{$line['item_id']}].");
                }

                if ($item->inventory_class !== InventoryClass::RawMaterial) {
                    throw new InvalidArgumentException("\"{$item->name}\" is not a raw material. Only raw material items can be issued to production.");
                }

                $quantity = Quantity::of($line['quantity']);

                if (! $quantity->isPositive()) {
                    throw new InvalidArgumentException('Quantity must be greater than zero.');
                }

                [$unitId, $factor] = static::resolveItemUnit($item, $line['item_unit_id'] ?? null);
                $base = $quantity->multipliedBy($factor);

                $prepared[] = ['item' => $item, 'unit_id' => $unitId, 'quantity' => $quantity, 'base' => $base];
                $requested[$item->id] = ($requested[$item->id] ?? Quantity::zero())->plus($base);
            }

            $onHandAtDate = Item::currentStockByItem(array_keys($requested), $storeId, $data['date']);
            $onHandNow = Item::currentStockByItem(array_keys($requested), $storeId);

            foreach ($requested as $itemId => $required) {
                foreach ([$onHandAtDate, $onHandNow] as $onHand) {
                    $have = $onHand[$itemId] ?? Quantity::zero();

                    if ($have->isLessThan($required)) {
                        $shortfall = $required->minus($have);

                        throw new InvalidArgumentException(
                            "Insufficient stock for \"{$items[$itemId]->name}\" at this store "
                            ."(available {$have->formatQuantity()}, required {$required->formatQuantity()}, short by {$shortfall->formatQuantity()})."
                        );
                    }
                }
            }

            $costed = [];

            foreach ($prepared as $line) {
                $movement = $line['item']->recordStockMovement(
                    StockMovementType::MaterialIssue,
                    $line['base'],
                    $data['date'],
                    $storeId,
                    narration: $data['narration'] ?? null,
                    costValue: null,
                );

                if ($movement->cost_value === null) {
                    throw new InvalidArgumentException("No cost could be determined for \"{$line['item']->name}\".");
                }

                $costed[] = [...$line, 'movement' => $movement, 'cost' => Money::of($movement->cost_value)];
            }

            $total = Money::sum(array_map(fn (array $line) => $line['cost'], $costed));

            $voucher = JournalVoucher::post([
                'voucher_type' => VoucherType::MaterialIssue->value,
                'date' => $data['date'],
                'narration' => 'Material issue to production',
                'fiscal_year_id' => $fiscalYear->id,
                'reason' => $reason,
                'branch_id' => $placement->branchId,
            ], [
                ['account_id' => ManufacturingAccounts::id(ManufacturingAccounts::WIP), 'debit' => $total->toString(), 'credit' => '0.00'],
                ['account_id' => ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL), 'debit' => '0.00', 'credit' => $total->toString()],
            ], $actor, true, fn (JournalVoucher $voucher) => "Material Issue {$voucher->printedNumber('MI')}");

            $note = static::create([
                'fiscal_year_id' => $fiscalYear->id,
                'number' => $voucher->voucher_number,
                // Part of the note's unique key with number (BR08 F3, BR20):
                // in a per_branch year every branch has its own MI series.
                'series_branch_id' => $voucher->series_branch_id,
                'date' => $data['date'],
                'store_id' => $storeId,
                'branch_id' => $placement->branchId,
                'production_entry_id' => $entry?->id,
                'total_cost' => $total,
                'narration' => $data['narration'] ?? null,
                'status' => self::STATUS_POSTED,
                'journal_voucher_id' => $voucher->id,
                'created_by' => $actor->id,
            ]);

            foreach ($costed as $line) {
                $unitCostRate = Quantity::of(
                    $line['cost']->toBigDecimal()->dividedBy($line['base']->toBigDecimal(), 4, RoundingMode::HalfUp)
                );

                $noteItem = $note->items()->create([
                    'item_id' => $line['item']->id,
                    'item_unit_id' => $line['unit_id'],
                    'quantity' => $line['quantity'],
                    'base_quantity' => $line['base'],
                    'unit_cost_rate' => $unitCostRate,
                    'line_cost' => $line['cost'],
                    'stock_movement_id' => $line['movement']->id,
                ]);

                ItemStockMovement::query()->whereKey($line['movement']->id)->update([
                    'reference_type' => $noteItem->getMorphClass(),
                    'reference_id' => $noteItem->getKey(),
                ]);

                ProductionWipEntry::create([
                    'material_issue_note_id' => $note->id,
                    'production_entry_id' => $entry?->id,
                    'date' => $data['date'],
                    'kind' => ProductionWipEntry::KIND_MATERIAL_IN,
                    'item_id' => $line['item']->id,
                    'quantity' => $line['base'],
                    'value' => $line['cost'],
                    'direction' => 1,
                    'source_type' => $noteItem->getMorphClass(),
                    'source_id' => $noteItem->getKey(),
                    'cancelled' => false,
                ]);
            }

            return $note;
        });
    }

    /**
     * Cancels an unattached note: reverses its voucher in the Reversal series
     * (open year only, C4/C5) and marks its movements and WIP rows cancelled,
     * so stock, GL and the WIP ledger all drop back together. A note already
     * attached to a production entry is refused: its cost is part of that
     * entry's cost sheet, so the entry has to be cancelled first, which
     * detaches the note.
     */
    public function cancel(User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required to cancel a material issue note.');
        }

        DB::transaction(function () use ($actor, $reason) {
            /** @var self $fresh */
            $fresh = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status === self::STATUS_CANCELLED) {
                throw new InvalidArgumentException('This material issue note has already been cancelled.');
            }

            if ($fresh->production_entry_id !== null) {
                throw new InvalidArgumentException('This note is attached to a production entry and cannot be cancelled. Cancel the production entry first.');
            }

            ClosedFiscalYearGuard::assertDateInOpenYear($fresh->date->toDateString(), $actor, $reason);

            $lines = $fresh->items()->orderBy('id')->get();
            Item::lockForStockOut($lines->pluck('item_id')->all());

            $reversal = JournalVoucher::reverse(
                BranchContext::unrestricted(fn () => $fresh->journalVoucher()->firstOrFail()),
                $actor,
                "Cancelled Material Issue {$fresh->documentNumber()}: {$reason}",
            );

            ItemStockMovement::query()
                ->whereIn('id', $lines->pluck('stock_movement_id')->filter()->all())
                ->update(['cancelled' => true]);

            ProductionWipEntry::query()
                ->where('material_issue_note_id', $fresh->id)
                ->update(['cancelled' => true]);

            $fresh->update([
                'status' => self::STATUS_CANCELLED,
                'reversal_journal_voucher_id' => $reversal->id,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            $this->forceFill($fresh->getAttributes())->syncOriginal();
        });
    }

    /**
     * Resolves a line's optional item_unit_id against the item's loaded
     * `units` relation into [item_unit_id, conversion_factor]; a missing id
     * is the base unit (factor 1), same as Sale/Purchase.
     *
     * @return array{0: int|null, 1: Quantity}
     */
    private static function resolveItemUnit(Item $item, mixed $itemUnitId): array
    {
        if ($itemUnitId === null || $itemUnitId === '') {
            return [null, Quantity::of(1)];
        }

        $unit = $item->units->firstWhere('id', (int) $itemUnitId);

        if (! $unit) {
            throw new InvalidArgumentException("Unit [{$itemUnitId}] does not belong to item [{$item->id}].");
        }

        return [$unit->id, Quantity::of($unit->conversion_factor)];
    }
}
