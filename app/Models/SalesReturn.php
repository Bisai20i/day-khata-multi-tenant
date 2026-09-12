<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A partial-line credit note against a prior Sale - the customer returned
 * some (not necessarily all) quantity from one or more of the original
 * sale's lines. Reverses only the revenue/VAT side proportional to what was
 * returned and credits the customer's account (a credit note reduces what
 * they owe, or creates a credit balance if the sale was already settled in
 * cash/bank), optionally followed by an immediate cash/bank refund voucher
 * if `refund_account_id` is supplied (see post()'s docblock).
 *
 * Stock: unlike Sale::cancel() (which flags existing movements cancelled),
 * a partial return can't flag a whole original movement since only part of
 * its quantity came back - so this WRITES a new inverse StockMovementType::
 * SaleReturn movement (direction +1, goods physically return to stock) per
 * returned line, in BASE units (return quantity x the original line's
 * `unit_conversion_factor`): returning 1 Box of an item sold in Boxes of 12
 * puts 12 base units back, not 1 (audit P0-12).
 *
 * Amounts (CONTRACTS C6, audit P0-3). Nothing here ever re-derives a value
 * by `line_total / quantity x returnQty`: that rounds twice, so returning a
 * 3-unit line worth 100.00 as 1 + 1 + 1 credited 99.99 and the missing
 * paisa hung on the customer forever. Instead every original line carries
 * three components - its value after the line AND header discount, its
 * share of the invoice VAT, its share of the invoice TDS - and a return
 * credits `component.multipliedByFraction(returnQty, lineQty)` of each,
 * EXCEPT the return that consumes a line's last remaining quantity, which
 * credits `component` minus everything already credited for that line. The
 * components themselves are split across lines with `Money::allocate()`, so
 * they sum back to the invoice exactly; the last-quantity rule then makes
 * the credits sum back to the components exactly. A return that completes
 * the whole invoice therefore reverses its VAT and TDS to the paisa.
 *
 * Immutable once posted, EXCEPT via cancel() (see its own docblock) - same
 * append-only correction philosophy as every other voucher-backed document
 * in this app.
 *
 * `status` lifecycle: 'posted' (direct one-step post(), or a 'pending'
 * request() that was later approve()'d) -> 'cancelled' (reversed, see
 * cancel()). A `request()`'d return instead starts at 'pending' -> either
 * 'posted' (approve()) or 'rejected' (reject(), a dead end - a rejected
 * request never posts anything and is not itself cancellable). Only a
 * 'posted' return has any money, VAT, TDS or outstanding-balance effect
 * anywhere in the app (C6, audit P0-13); a 'pending' one reserves quantity
 * (so two requests cannot jointly over-return a line) and nothing else, and
 * is never titled or numbered as a credit note (C7). Kept as a plain string
 * rather than a backed enum (unlike Quotation's QuotationStatus) because
 * existing tests already assert this column as a raw string ('cancelled') -
 * changing the cast would break them for no behavioural gain.
 */
#[Fillable([
    'sale_id', 'journal_voucher_id', 'fiscal_year_id', 'credit_note_number', 'date', 'store_id', 'reason',
    'taxable_amount', 'nontaxable_amount', 'vat_amount', 'tds_amount', 'total', 'status',
    'refund_account_id', 'refund_journal_voucher_id', 'rejection_reason', 'created_by',
    'cancelled_at', 'cancelled_by', 'cancel_reason', 'reversal_journal_voucher_id',
])]
class SalesReturn extends Model
{
    /**
     * The fallback credit-note series prefix, used only when the tenant has
     * no company settings row at all. It is the literal the print view used
     * before `company_settings.sale_return_prefix` existed, so a note issued
     * back then reprints with exactly the number it always carried (C7).
     */
    private const DEFAULT_CREDIT_NOTE_PREFIX = 'SR';

    /**
     * Statuses that reserve quantity against the original sale line, and
     * whose already-credited components the last-quantity rule subtracts.
     * A 'cancelled' or 'rejected' return frees both again.
     *
     * @var list<string>
     */
    private const ACTIVE_STATUSES = ['pending', 'posted'];

    /**
     * The two Current Asset subgroups this app's chart of accounts uses for
     * things that are not money, and out of which a refund therefore cannot
     * be paid: the parties themselves and stock on hand.
     *
     * @var list<string>
     */
    private const NON_MONEY_SUBGROUPS = ['Sundry Debtors', 'Stock'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'taxable_amount' => Decimal::class.':2',
            'nontaxable_amount' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'tds_amount' => Decimal::class.':2',
            'total' => Decimal::class.':2',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function refundAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'refund_account_id');
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function refundJournalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class, 'refund_journal_voucher_id');
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
     * @return HasMany<SaleReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * What this document is called on screen and on paper. Only a return
     * that actually posted is a credit note with a number; a pending or
     * rejected request is neither (C7), which is why nothing may fall back
     * to the row id as a "number" here.
     */
    public function documentNumber(): string
    {
        if (! in_array($this->status, ['posted', 'cancelled'], true)) {
            return "Return request #{$this->id}";
        }

        if ($this->credit_note_number) {
            return $this->credit_note_number;
        }

        $prefix = static::creditNotePrefix();

        return $this->journalVoucher
            ? "{$prefix}-{$this->journalVoucher->voucher_number}"
            : "{$prefix}-{$this->id}";
    }

    /**
     * The credit-note series prefix an admin set on the Settings screen
     * (T03's `company_settings.sale_return_prefix`, default "SR"). Read here
     * rather than hardcoded, otherwise that screen's prefix-distinctness
     * check would be checking a value nothing uses.
     */
    private static function creditNotePrefix(): string
    {
        $prefix = trim((string) CompanySetting::current()->sale_return_prefix);

        return $prefix === '' ? self::DEFAULT_CREDIT_NOTE_PREFIX : $prefix;
    }

    /**
     * The number stored on a return the moment it posts: exactly the
     * `{prefix}-{voucher_number}` format the print view derived on the fly
     * before this column existed, so notes issued either side of this change
     * read identically. Changing the prefix in Settings from now on only
     * affects notes issued afterwards (C7).
     */
    private static function creditNoteNumberFor(JournalVoucher $voucher): string
    {
        return static::creditNotePrefix()."-{$voucher->voucher_number}";
    }

    /**
     * Posts a return immediately: one balanced credit-note voucher, one
     * inverse stock movement per line that moved stock on the way out, and
     * the optional cash/bank refund settlement.
     *
     * Voucher shape (unchanged from the original build, only the numbers
     * are now exact):
     * - debit Sales Account (INI20) with the returned net value,
     * - debit VAT Payable (LIA20) with the reversed VAT,
     * - credit the customer with `total - tdsShare`,
     * - credit the TDS account with `tdsShare`.
     * At sale time TDS was booked as `[debit tds_account, credit customer]`,
     * netting the receivable down to `total - tds`; reversing a share of it
     * the same way keeps the customer's ledger and `Sale::outstandingAmount()`
     * in step. The two credits always sum to the return's own `total`, so
     * the voucher balances whatever the share turns out to be.
     *
     * @param  array{sale_id: int, date: string, reason?: string|null, refund_account_id?: int|null, store_id?: int|null, expected_total?: string|null}  $data
     * @param  array<int, array{sale_line_id: int, quantity: string|float}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return static::record($data, $lines, $actor, post: true);
    }

    /**
     * Requests a partial-line return WITHOUT posting anything yet - no
     * journal voucher, no stock movement, `journal_voucher_id` stays null.
     * Mirrors legacy day_khata's `sales_return_requests` intake queue
     * (`listreturnoutstockrequest`/`approveReturnRequest`/
     * `updateCancelResoanForSalesReturnRequest`) at the workflow level - a
     * request just sits pending until a second person approve()s or
     * reject()s it - but keeps this app's own real line-level detail (item +
     * sale_line_id + quantity via SaleReturnLine) rather than legacy's
     * single flattened item_id/quantity/"other_details" text fields, since
     * duplicating a second, thinner table would just re-import legacy's own
     * gap.
     *
     * The amounts are computed and stored here, exactly as post() computes
     * them, and approve() posts those stored numbers unchanged: the person
     * approving must see the same figures the requester was shown, and the
     * last-remaining-quantity rule (see the class docblock) already accounts
     * for a pending request's reserved quantity, so a later return can never
     * credit a paisa this one has claimed.
     *
     * @param  array{sale_id: int, date: string, reason?: string|null, refund_account_id?: int|null, store_id?: int|null, expected_total?: string|null}  $data
     * @param  array<int, array{sale_line_id: int, quantity: string|float}>  $lines
     */
    public static function request(array $data, array $lines, User $actor): self
    {
        return static::record($data, $lines, $actor, post: false);
    }

    /**
     * Shared body of post() and request(): everything except whether the
     * money side is written now or deferred to approve().
     *
     * @param  array{sale_id: int, date: string, reason?: string|null, refund_account_id?: int|null, store_id?: int|null, expected_total?: string|null}  $data
     * @param  array<int, array{sale_line_id: int, quantity: string|float}>  $lines
     */
    private static function record(array $data, array $lines, User $actor, bool $post): self
    {
        return DB::transaction(function () use ($data, $lines, $actor, $post) {
            $sale = Sale::whereKey($data['sale_id'])->lockForUpdate()->firstOrFail();

            if ($sale->status === 'cancelled') {
                throw new InvalidArgumentException(
                    $post
                        ? 'Cannot post a return against a cancelled sale.'
                        : 'Cannot request a return against a cancelled sale.'
                );
            }

            $date = static::validatedDate($data['date'], $sale, $actor);
            $storeId = static::resolveStoreId($data, $sale);
            $refundAccountId = static::validatedRefundAccountId($data['refund_account_id'] ?? null);

            $prepared = static::prepareLines($sale, $lines);

            if (isset($data['expected_total']) && $data['expected_total'] !== ''
                && ! $prepared['total']->isEqualTo(Money::of($data['expected_total']))) {
                throw new InvalidArgumentException('The credit note total changed. Please review it before saving.');
            }

            $voucher = $post ? static::postCreditNote($sale, $prepared, $date, $data['reason'] ?? null, $actor) : null;

            $salesReturn = static::create([
                'sale_id' => $sale->id,
                'journal_voucher_id' => $voucher?->id,
                'fiscal_year_id' => $voucher?->fiscal_year_id,
                'credit_note_number' => $voucher ? static::creditNoteNumberFor($voucher) : null,
                'date' => $date,
                'store_id' => $storeId,
                'reason' => $data['reason'] ?? null,
                'taxable_amount' => $prepared['taxable'],
                'nontaxable_amount' => $prepared['nontaxable'],
                'vat_amount' => $prepared['vat'],
                'tds_amount' => $prepared['tds'],
                'total' => $prepared['total'],
                'status' => $post ? 'posted' : 'pending',
                'refund_account_id' => $refundAccountId,
                'created_by' => $actor->id,
            ]);

            foreach ($prepared['lines'] as $line) {
                $returnLine = $salesReturn->lines()->create([
                    'sale_line_id' => $line['sale_line']->id,
                    'quantity' => $line['quantity'],
                    'rate' => $line['sale_line']->rate,
                    'line_total' => $line['net'],
                    'net_amount' => $line['net'],
                    'vat_amount' => $line['vat'],
                    'tds_amount' => $line['tds'],
                ]);

                if ($post && $line['moves_stock']) {
                    static::recordReturnMovement($line, $returnLine, $date, $storeId);
                }
            }

            if ($post) {
                static::postRefund($salesReturn, $sale, $prepared, $date, $actor);
            }

            return $salesReturn;
        });
    }

    /**
     * Approves a 'pending' request(): NOW it posts, exactly what post()
     * itself does in one step for a direct return - a journal voucher, one
     * ItemStockMovement per line that moved stock on the way out, and the
     * optional refund voucher - using this row's own already-computed
     * amounts and already-persisted lines rather than recomputing them (see
     * request()'s docblock for why). Posts against the return's own
     * requested `date`, not "today", same as a direct post() would have.
     *
     * The quantity caps are re-checked here against current state, inside
     * the transaction and behind a row lock: between the request and this
     * approval another return may have posted, the sale may have been
     * cancelled, or a second approver may have clicked the same button
     * (audit P0-16).
     */
    public function approve(User $actor): self
    {
        return DB::transaction(function () use ($actor) {
            /** @var self $salesReturn */
            $salesReturn = static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($salesReturn->status !== 'pending') {
                throw new InvalidArgumentException('Only a pending return request can be approved.');
            }

            $sale = Sale::whereKey($salesReturn->sale_id)->lockForUpdate()->firstOrFail();

            if ($sale->status === 'cancelled') {
                throw new InvalidArgumentException('Cannot approve a return against a cancelled sale.');
            }

            $date = $salesReturn->date->format('Y-m-d');
            ClosedFiscalYearGuard::assertDateInOpenYear($date, $actor);

            $storedLines = $salesReturn->lines()->with('saleLine')->orderBy('sale_line_id')->get();
            $salesReturn->assertQuantitiesStillAvailable($sale, $storedLines);

            $prepared = [
                'taxable' => Money::of($salesReturn->taxable_amount),
                'nontaxable' => Money::of($salesReturn->nontaxable_amount),
                'vat' => Money::of($salesReturn->vat_amount),
                'tds' => Money::of($salesReturn->tds_amount),
                'total' => Money::of($salesReturn->total),
            ];

            $voucher = static::postCreditNote($sale, $prepared, $date, $salesReturn->reason, $actor);

            $salesReturn->update([
                'journal_voucher_id' => $voucher->id,
                'fiscal_year_id' => $voucher->fiscal_year_id,
                'credit_note_number' => static::creditNoteNumberFor($voucher),
                'status' => 'posted',
            ]);

            $movedStock = static::saleLinesThatMovedStock($storedLines->pluck('sale_line_id')->all());

            foreach ($storedLines as $line) {
                if (! in_array($line->sale_line_id, $movedStock, true)) {
                    continue;
                }

                static::recordReturnMovement(
                    ['sale_line' => $line->saleLine, 'quantity' => Quantity::of($line->quantity)],
                    $line,
                    $date,
                    $salesReturn->store_id,
                );
            }

            static::postRefund($salesReturn, $sale, $prepared, $date, $actor);

            return $salesReturn->fresh();
        });
    }

    /**
     * Rejects a 'pending' request with a mandatory reason. Nothing was ever
     * posted for a pending request, so there is nothing to reverse - this
     * just records why and marks it a dead end (never itself cancellable,
     * never postable again), which also frees the quantity it was
     * reserving. Mirrors legacy's `updateCancelResoanForSalesReturnRequest`
     * (`is_request_accepted=2` + `reson_for_not_accept`), modernized to a
     * named status + column. The status is re-read under a lock so a
     * simultaneous approve() and reject() cannot both win (audit P0-16).
     */
    public function reject(string $reason): self
    {
        $reason = static::validatedReason($reason, 'reject a return request');

        return DB::transaction(function () use ($reason) {
            /** @var self $salesReturn */
            $salesReturn = static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($salesReturn->status !== 'pending') {
                throw new InvalidArgumentException('Only a pending return request can be rejected.');
            }

            $salesReturn->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ]);

            $this->setRawAttributes($salesReturn->getAttributes(), true);

            return $this;
        });
    }

    /**
     * Reverses this return - and its refund settlement voucher, if one was
     * posted - through JournalVoucher::reverse(), which posts the mirrored
     * lines under VoucherType::Reversal. That type has its own gapless
     * series, so cancelling a credit note no longer burns a sales invoice
     * number (audit P0-15); the original vouchers are never edited.
     * Reversing both vouchers together matters when a refund was paid out:
     * reversing only the return would leave the customer's account looking
     * like they owe money again even though the refund cash already left.
     *
     * Only a 'posted' return has anything posted to reverse - a 'pending'
     * request or a 'rejected' one both guard here too (with their own clear
     * message) rather than falling through to journalVoucher()->firstOrFail()
     * and surfacing a confusing ModelNotFoundException instead.
     */
    public function cancel(User $actor, string $reason): void
    {
        $reason = static::validatedReason($reason, 'cancel a sales return');

        DB::transaction(function () use ($actor, $reason) {
            /** @var self $salesReturn */
            $salesReturn = static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($salesReturn->status !== 'posted') {
                throw new InvalidArgumentException(match ($salesReturn->status) {
                    'cancelled' => 'This sales return has already been cancelled.',
                    'pending' => 'A pending return request cannot be cancelled - approve or reject it instead.',
                    'rejected' => 'A rejected return request cannot be cancelled - nothing was ever posted for it.',
                    default => 'Only a posted sales return can be cancelled.',
                });
            }

            $original = $salesReturn->journalVoucher()->firstOrFail();
            $reversal = JournalVoucher::reverse($original, $actor, "Cancellation of {$salesReturn->documentNumber()}: {$reason}");

            if ($salesReturn->refund_journal_voucher_id) {
                JournalVoucher::reverse(
                    JournalVoucher::findOrFail($salesReturn->refund_journal_voucher_id),
                    $actor,
                    "Cancellation of the refund for {$salesReturn->documentNumber()}: {$reason}",
                );
            }

            ItemStockMovement::query()
                ->where('reference_type', (new SaleReturnLine)->getMorphClass())
                ->whereIn('reference_id', $salesReturn->lines()->pluck('id'))
                ->update(['cancelled' => true]);

            $salesReturn->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'reversal_journal_voucher_id' => $reversal->id,
            ]);

            $this->setRawAttributes($salesReturn->getAttributes(), true);
        });
    }

    /**
     * What is still returnable on a sale, line by line, for the return form
     * and its live preview: the sale line with its item and unit, how much
     * has already been claimed by an active return, and the three C6
     * components plus what earlier returns already credited of each.
     *
     * The browser previews a credit with the very same rule the server
     * applies (fraction of the component, or the component minus what is
     * already credited when the line is being finished), so the number on
     * screen and the number posted cannot disagree - and the posted document
     * still renders stored server values, never this preview (C8).
     *
     * @return list<array{sale_line_id: int, item: string, unit: string, quantity: string, rate: string, returned: string, remaining: string, vatable: bool, net: string, vat: string, tds: string, credited_net: string, credited_vat: string, credited_tds: string}>
     */
    public static function returnableLines(Sale $sale): array
    {
        $saleLines = $sale->lines()->with(['item:id,name,unit', 'itemUnit:id,name'])->orderBy('id')->get()->keyBy('id');

        if ($saleLines->isEmpty()) {
            return [];
        }

        $components = static::saleComponents($sale, $saleLines);
        $credited = static::alreadyCredited($saleLines->keys()->all());

        $lines = [];

        foreach ($saleLines as $saleLine) {
            $quantity = Quantity::of($saleLine->quantity);
            $returned = $credited[$saleLine->id]['quantity'];

            $lines[] = [
                'sale_line_id' => $saleLine->id,
                'item' => $saleLine->item?->name ?? '',
                'unit' => $saleLine->itemUnit?->name ?? ($saleLine->item?->unit ?? ''),
                'quantity' => $quantity->toString(),
                'rate' => Quantity::of($saleLine->rate)->toString(),
                'returned' => $returned->toString(),
                'remaining' => $quantity->minus($returned)->toString(),
                'vatable' => (bool) $saleLine->vatable,
                'net' => $components[$saleLine->id]['net']->toString(),
                'vat' => $components[$saleLine->id]['vat']->toString(),
                'tds' => $components[$saleLine->id]['tds']->toString(),
                'credited_net' => $credited[$saleLine->id]['net']->toString(),
                'credited_vat' => $credited[$saleLine->id]['vat']->toString(),
                'credited_tds' => $credited[$saleLine->id]['tds']->toString(),
            ];
        }

        return $lines;
    }

    /**
     * Validates and prices every returned line against its original sale
     * line, and returns the document totals alongside.
     *
     * Duplicate rows for the same `sale_line_id` are summed before any cap
     * is checked (audit P0-14: `[{line 7, qty 5}, {line 7, qty 5}]` against
     * a 5-unit line used to credit 10), and the sale's lines are locked in
     * ascending id order so two concurrent returns cannot both pass the cap
     * (P0-16). Quantity comparisons are exact `Quantity` comparisons, never
     * a float with a 0.0001 tolerance (P0-4), so returning 0.3 - 0.1 - 0.2
     * of a 0.3 line lands exactly on zero and is accepted.
     *
     * @param  array<int, array{sale_line_id: int, quantity: string|float}>  $lines
     * @param  int|null  $excludeReturnId  Ignore this return's own reserved quantity (re-validating an approval).
     * @return array{lines: list<array{sale_line: SaleLine, quantity: Quantity, net: Money, vat: Money, tds: Money, moves_stock: bool}>, taxable: Money, nontaxable: Money, vat: Money, tds: Money, total: Money}
     */
    private static function prepareLines(Sale $sale, array $lines, ?int $excludeReturnId = null): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A return needs at least one line.');
        }

        $requested = [];

        foreach ($lines as $line) {
            $saleLineId = (int) $line['sale_line_id'];
            $quantity = Quantity::of($line['quantity']);

            if (! $quantity->isPositive()) {
                throw new InvalidArgumentException('Return quantity must be greater than zero.');
            }

            $requested[$saleLineId] = isset($requested[$saleLineId])
                ? $requested[$saleLineId]->plus($quantity)
                : $quantity;
        }

        ksort($requested);

        // Every line of the sale is locked, not just the returned ones: the
        // components below are split across all of them, so a concurrent
        // return against any line changes what this one may credit.
        $saleLines = SaleLine::where('sale_id', $sale->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach (array_keys($requested) as $saleLineId) {
            if (! $saleLines->has($saleLineId)) {
                throw new InvalidArgumentException("Line [{$saleLineId}] does not belong to this sale.");
            }
        }

        $components = static::saleComponents($sale, $saleLines);
        $credited = static::alreadyCredited(array_keys($requested), $excludeReturnId);
        $movedStock = static::saleLinesThatMovedStock(array_keys($requested));

        $preparedLines = [];
        $taxable = Money::zero();
        $nontaxable = Money::zero();
        $vat = Money::zero();
        $tds = Money::zero();

        foreach ($requested as $saleLineId => $quantity) {
            $saleLine = $saleLines[$saleLineId];
            $lineQuantity = Quantity::of($saleLine->quantity);
            $alreadyReturned = $credited[$saleLineId]['quantity'];
            $remaining = $lineQuantity->minus($alreadyReturned);

            if ($quantity->isGreaterThan($remaining)) {
                throw new InvalidArgumentException(
                    "Cannot return {$quantity->formatQuantity()} of line [{$saleLineId}]; only {$remaining->formatQuantity()} remains returnable."
                );
            }

            // The return that takes a line's last remaining quantity gets
            // "the component minus everything already credited for it", so
            // no paisa is ever stranded or invented (C6).
            $isFinalReturn = $quantity->isEqualTo($remaining);

            $share = function (string $component) use ($isFinalReturn, $components, $credited, $saleLineId, $quantity, $lineQuantity): Money {
                $whole = $components[$saleLineId][$component];

                return $isFinalReturn
                    ? $whole->minus($credited[$saleLineId][$component])
                    : $whole->multipliedByFraction($quantity, $lineQuantity);
            };

            $net = $share('net');
            $lineVat = $share('vat');
            $lineTds = $share('tds');

            if ($saleLine->vatable) {
                $taxable = $taxable->plus($net);
            } else {
                $nontaxable = $nontaxable->plus($net);
            }

            $vat = $vat->plus($lineVat);
            $tds = $tds->plus($lineTds);

            $preparedLines[] = [
                'sale_line' => $saleLine,
                'quantity' => $quantity,
                'net' => $net,
                'vat' => $lineVat,
                'tds' => $lineTds,
                'moves_stock' => in_array($saleLineId, $movedStock, true),
            ];
        }

        $total = $taxable->plus($nontaxable)->plus($vat);

        if (! $total->isPositive()) {
            throw new InvalidArgumentException('This return has no value left to credit.');
        }

        if ($tds->isGreaterThan($total)) {
            throw new InvalidArgumentException('The TDS being reversed cannot exceed the credit note total.');
        }

        return [
            'lines' => $preparedLines,
            'taxable' => $taxable,
            'nontaxable' => $nontaxable,
            'vat' => $vat,
            'tds' => $tds,
            'total' => $total,
        ];
    }

    /**
     * The three returnable components of every line of the original sale,
     * keyed by sale line id:
     *
     * - `net`: the line's value after its own discount AND its share of the
     *   header discount. The header discount is not reconstructed from a
     *   ratio (the old code divided by a subtotal it never stored and lost a
     *   paisa doing it): the amount the header discount removed from each
     *   group is the group's own subtotal minus the stored `taxable_amount`
     *   / `nontaxable_amount`, and `Money::allocate()` splits exactly that
     *   across the group's lines. The nets therefore sum back to
     *   `taxable_amount` and `nontaxable_amount` to the paisa.
     * - `vat`: the invoice's VAT allocated across the vatable lines by net.
     * - `tds`: the invoice's TDS allocated across every line by net (TDS is
     *   withheld on the taxable + exempt base, C3 step 8).
     *
     * @param  EloquentCollection<int, SaleLine>  $saleLines
     * @return array<int, array{net: Money, vat: Money, tds: Money}>
     */
    private static function saleComponents(Sale $sale, EloquentCollection $saleLines): array
    {
        $vatableTotals = [];
        $nonVatableTotals = [];

        foreach ($saleLines as $saleLine) {
            $lineTotal = Money::of($saleLine->line_total);

            if ($lineTotal->isNegative()) {
                throw new InvalidArgumentException("Line [{$saleLine->id}] has a negative value and cannot be returned.");
            }

            if ($saleLine->vatable) {
                $vatableTotals[$saleLine->id] = $lineTotal;
            } else {
                $nonVatableTotals[$saleLine->id] = $lineTotal;
            }
        }

        $net = static::netOfHeaderDiscount($vatableTotals, Money::of($sale->taxable_amount))
            + static::netOfHeaderDiscount($nonVatableTotals, Money::of($sale->nontaxable_amount));

        ksort($net);

        $vat = static::shareByLine(
            Money::of($sale->vat_amount),
            array_intersect_key($net, $vatableTotals),
        );

        $tds = static::shareByLine(Money::of($sale->tds_amount), $net);

        $components = [];

        foreach ($net as $saleLineId => $lineNet) {
            $components[$saleLineId] = [
                'net' => $lineNet,
                'vat' => $vat[$saleLineId] ?? Money::zero(),
                'tds' => $tds[$saleLineId] ?? Money::zero(),
            ];
        }

        return $components;
    }

    /**
     * Each line's value after the header discount: the group's share of the
     * discount is `subtotal - $groupTotalAfterDiscount` (exact, both sides
     * are stored values) and `Money::allocate()` hands it out by line value,
     * so the results sum back to $groupTotalAfterDiscount exactly.
     *
     * @param  array<int, Money>  $lineTotals
     * @return array<int, Money>
     */
    private static function netOfHeaderDiscount(array $lineTotals, Money $groupTotalAfterDiscount): array
    {
        if ($lineTotals === []) {
            return [];
        }

        $discount = Money::sum($lineTotals)->minus($groupTotalAfterDiscount);

        if ($discount->isZero()) {
            return $lineTotals;
        }

        $shares = static::shareByLine($discount, $lineTotals);
        $net = [];

        foreach ($lineTotals as $saleLineId => $lineTotal) {
            $net[$saleLineId] = $lineTotal->minus($shares[$saleLineId]);
        }

        return $net;
    }

    /**
     * `Money::allocate()` keyed by sale line id. A zero amount needs no
     * split (and allocate() rightly refuses all-zero weights), so it short
     * circuits to zero shares.
     *
     * @param  array<int, Money>  $weights
     * @return array<int, Money>
     */
    private static function shareByLine(Money $amount, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        if ($amount->isZero()) {
            return array_map(static fn (): Money => Money::zero(), $weights);
        }

        $ids = array_keys($weights);
        $parts = $amount->allocate(array_values($weights));

        return array_combine($ids, $parts);
    }

    /**
     * Quantity and credited components already claimed against each sale
     * line by a 'pending' or 'posted' return, keyed by sale line id.
     *
     * Summed in PHP as exact decimals rather than with a SQL `SUM()`:
     * SQLite hands a DECIMAL sum back as a float, which is precisely the
     * class of error this whole change exists to remove.
     *
     * @param  array<int, int>  $saleLineIds
     * @return array<int, array{quantity: Quantity, net: Money, vat: Money, tds: Money}>
     */
    private static function alreadyCredited(array $saleLineIds, ?int $excludeReturnId = null): array
    {
        $credited = [];

        foreach ($saleLineIds as $saleLineId) {
            $credited[$saleLineId] = [
                'quantity' => Quantity::zero(),
                'net' => Money::zero(),
                'vat' => Money::zero(),
                'tds' => Money::zero(),
            ];
        }

        $rows = SaleReturnLine::query()
            ->whereIn('sale_line_id', $saleLineIds)
            ->whereHas('salesReturn', fn ($query) => $query->whereIn('status', self::ACTIVE_STATUSES))
            ->when($excludeReturnId !== null, fn ($query) => $query->where('sales_return_id', '!=', $excludeReturnId))
            ->get();

        foreach ($rows as $row) {
            $credited[$row->sale_line_id]['quantity'] = $credited[$row->sale_line_id]['quantity']->plus(Quantity::of($row->quantity));
            $credited[$row->sale_line_id]['net'] = $credited[$row->sale_line_id]['net']->plus(Money::of($row->net_amount));
            $credited[$row->sale_line_id]['vat'] = $credited[$row->sale_line_id]['vat']->plus(Money::of($row->vat_amount));
            $credited[$row->sale_line_id]['tds'] = $credited[$row->sale_line_id]['tds']->plus(Money::of($row->tds_amount));
        }

        return $credited;
    }

    /**
     * Which of these sale lines actually moved stock when the sale posted.
     *
     * Stockability is read from the movement the sale wrote, not from the
     * item's current `is_stockable` flag: an item switched to non-stockable
     * after the sale must still return its goods to stock, and one switched
     * ON afterwards must not invent stock that never left.
     *
     * @param  array<int, int>  $saleLineIds
     * @return list<int>
     */
    private static function saleLinesThatMovedStock(array $saleLineIds): array
    {
        return ItemStockMovement::query()
            ->where('reference_type', (new SaleLine)->getMorphClass())
            ->whereIn('reference_id', $saleLineIds)
            ->pluck('reference_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Goods come back in BASE units: the returned quantity times the
     * original line's `unit_conversion_factor` (audit P0-12). Returning 1
     * Box of an item sold in Boxes of 12 puts 12 base units back.
     *
     * @param  array{sale_line: SaleLine, quantity: Quantity}  $line
     */
    private static function recordReturnMovement(array $line, SaleReturnLine $returnLine, string $date, int $storeId): void
    {
        $saleLine = $line['sale_line'];
        $baseQuantity = $line['quantity']->multipliedBy(Quantity::of($saleLine->unit_conversion_factor));

        $saleLine->item->recordStockMovement(
            StockMovementType::SaleReturn,
            $baseQuantity->toString(),
            $date,
            $storeId,
            $returnLine,
        );
    }

    /**
     * @param  array{taxable: Money, nontaxable: Money, vat: Money, tds: Money, total: Money}  $prepared
     */
    private static function postCreditNote(Sale $sale, array $prepared, string $date, ?string $reason, User $actor): JournalVoucher
    {
        $sale->loadMissing('customer');

        $voucherLines = [];

        $salesAccountId = Account::where('code', 'INI20')->firstOrFail()->id;
        $voucherLines[] = [
            'account_id' => $salesAccountId,
            'debit' => $prepared['taxable']->plus($prepared['nontaxable'])->toString(),
            'credit' => '0',
            'narration' => 'Sales return',
        ];

        if ($prepared['vat']->isPositive()) {
            $vatPayableId = Account::where('code', 'LIA20')->firstOrFail()->id;
            $voucherLines[] = [
                'account_id' => $vatPayableId,
                'debit' => $prepared['vat']->toString(),
                'credit' => '0',
                'narration' => 'VAT reversed',
            ];
        }

        $customerCredit = $prepared['total']->minus($prepared['tds']);

        // A zero line is not a posting (C4); it only happens when the whole
        // credit was withheld as TDS, in which case the customer's ledger
        // genuinely does not move.
        if ($customerCredit->isPositive()) {
            $voucherLines[] = [
                'account_id' => $sale->customer->account_id,
                'debit' => '0',
                'credit' => $customerCredit->toString(),
                'narration' => 'Sales return credit note',
            ];
        }

        if ($prepared['tds']->isPositive()) {
            $voucherLines[] = [
                'account_id' => $sale->tds_account_id,
                'debit' => '0',
                'credit' => $prepared['tds']->toString(),
                'narration' => 'TDS reversed',
            ];
        }

        return JournalVoucher::post(
            [
                'voucher_type' => VoucherType::SaleReturn->value,
                'date' => $date,
                'narration' => "Return against sale #{$sale->id}".($reason ? ": {$reason}" : ''),
            ],
            $voucherLines,
            $actor,
        );
    }

    /**
     * The optional immediate cash/bank refund: the customer's credit note
     * balance is paid straight back out, so their ledger nets to where it
     * stood before the sale.
     *
     * @param  array{taxable: Money, nontaxable: Money, vat: Money, tds: Money, total: Money}  $prepared
     */
    private static function postRefund(self $salesReturn, Sale $sale, array $prepared, string $date, User $actor): void
    {
        if (! $salesReturn->refund_account_id) {
            return;
        }

        $customerCredit = $prepared['total']->minus($prepared['tds']);

        if (! $customerCredit->isPositive()) {
            return;
        }

        $sale->loadMissing('customer');

        $refundVoucher = JournalVoucher::post(
            [
                'voucher_type' => VoucherType::Journal->value,
                'date' => $date,
                'narration' => "Refund for sales return #{$salesReturn->id}",
            ],
            [
                ['account_id' => $sale->customer->account_id, 'debit' => $customerCredit->toString(), 'credit' => '0', 'narration' => 'Refund settlement'],
                ['account_id' => $salesReturn->refund_account_id, 'debit' => '0', 'credit' => $customerCredit->toString(), 'narration' => 'Refund settlement'],
            ],
            $actor,
        );

        $salesReturn->update(['refund_journal_voucher_id' => $refundVoucher->id]);
    }

    /**
     * Re-checks, at approval time, that every line of this already-persisted
     * request still fits inside what its sale line has left - counting every
     * OTHER active return but not this one.
     *
     * @param  EloquentCollection<int, SaleReturnLine>  $storedLines
     */
    private function assertQuantitiesStillAvailable(Sale $sale, EloquentCollection $storedLines): void
    {
        $saleLines = SaleLine::where('sale_id', $sale->id)
            ->whereIn('id', $storedLines->pluck('sale_line_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $credited = static::alreadyCredited($storedLines->pluck('sale_line_id')->all(), $this->getKey());

        foreach ($storedLines as $line) {
            $saleLine = $saleLines->get($line->sale_line_id);

            if (! $saleLine) {
                throw new InvalidArgumentException("Line [{$line->sale_line_id}] no longer belongs to this sale.");
            }

            $remaining = Quantity::of($saleLine->quantity)->minus($credited[$line->sale_line_id]['quantity']);

            if (Quantity::of($line->quantity)->isGreaterThan($remaining)) {
                throw new InvalidArgumentException(
                    "This request can no longer be approved: only {$remaining->formatQuantity()} of line [{$line->sale_line_id}] remains returnable."
                );
            }
        }
    }

    /**
     * A return is dated on or after the sale it credits, and inside a fiscal
     * year that is open (or deliberately reopened for correction, which the
     * guard gates on admin + reason). A credit note dated before its own
     * invoice is not a thing the VAT book can represent.
     */
    private static function validatedDate(string $date, Sale $sale, User $actor): string
    {
        $date = substr(trim($date), 0, 10);
        $saleDate = $sale->date->format('Y-m-d');

        if ($date < $saleDate) {
            throw new InvalidArgumentException("A return cannot be dated before its sale ({$saleDate}).");
        }

        ClosedFiscalYearGuard::assertDateInOpenYear($date, $actor);

        return $date;
    }

    /**
     * The store the goods come back into. Defaults to the store the sale
     * went out of, not "the first active store": a two-store tenant used to
     * silently restock returns in the wrong branch.
     *
     * @param  array{store_id?: int|null}  $data
     */
    private static function resolveStoreId(array $data, Sale $sale): int
    {
        $storeId = isset($data['store_id']) && $data['store_id'] !== ''
            ? (int) $data['store_id']
            : ($sale->store_id ?? Store::where('is_active', true)->orderBy('id')->value('id'));

        if (! $storeId) {
            throw new InvalidArgumentException('No active store is configured.');
        }

        return (int) $storeId;
    }

    /**
     * Money can only be refunded out of a cash or bank account: anything
     * under Current Assets except the party (Sundry Debtors) and Stock
     * subgroups, which are the two Current Asset subgroups this app's chart
     * of accounts uses for things that are not money. Refunding out of a
     * customer or income account was accepted before and silently corrupted
     * both balances.
     */
    private static function validatedRefundAccountId(mixed $refundAccountId): ?int
    {
        if ($refundAccountId === null || $refundAccountId === '') {
            return null;
        }

        $refundAccountId = (int) $refundAccountId;

        if (! static::refundAccountQuery()->whereKey($refundAccountId)->exists()) {
            throw new InvalidArgumentException('A refund can only be paid out of a cash or bank account.');
        }

        return $refundAccountId;
    }

    /**
     * The cash/bank accounts a refund may be paid out of - shared by the
     * server-side check above and the picker the return form renders, so the
     * two can never disagree.
     *
     * @return Builder<Account>
     */
    public static function refundAccountQuery(): Builder
    {
        return Account::query()
            ->where(function (Builder $query) {
                // An account is filed EITHER directly under a group (a bank
                // account the tenant adds under Current Assets) or under a
                // subgroup (seeded Cash In Hand lives under Cash-In-Hand),
                // so both routes to "Current Assets" have to be checked.
                $query->whereHas('group', fn (Builder $group) => $group->where('name', 'Current Assets'))
                    ->orWhereHas('subgroup.accountGroup', fn (Builder $group) => $group->where('name', 'Current Assets'));
            })
            ->whereDoesntHave('subgroup', fn (Builder $subgroup) => $subgroup->whereIn('name', self::NON_MONEY_SUBGROUPS));
    }

    /**
     * Every state transition in this app takes a real reason, capped at the
     * 500 characters C5 fixes so the column and the form agree.
     */
    private static function validatedReason(string $reason, string $action): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException("A reason is required to {$action}.");
        }

        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('The reason may not be longer than 500 characters.');
        }

        return $reason;
    }
}
