<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Support\Billing\DocumentCalculator;
use App\Support\Billing\LineTotals;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use App\Support\SettlementNarration;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
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
    'sale_id', 'customer_id', 'is_unlinked', 'journal_voucher_id', 'fiscal_year_id', 'credit_note_number',
    'date', 'store_id', 'reason', 'taxable_amount', 'nontaxable_amount', 'vat_amount', 'vat_rate', 'tds_amount',
    'total', 'status', 'refund_account_id', 'refund_cash_amount', 'refund_bank_amount', 'refund_journal_voucher_id',
    'rejection_reason', 'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'reversal_journal_voucher_id',
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
            'is_unlinked' => 'boolean',
            'taxable_amount' => Decimal::class.':2',
            'nontaxable_amount' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'vat_rate' => Decimal::class.':2',
            'tds_amount' => Decimal::class.':2',
            'total' => Decimal::class.':2',
            'refund_cash_amount' => Decimal::class.':2',
            'refund_bank_amount' => Decimal::class.':2',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Null for an unlinked return (audit section 3 "Sales", "returns without
     * a bill") - see postUnlinked()'s docblock.
     *
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Who this return credits when it has no parent Sale to derive that
     * from - null for a normal, linked return (read `sale->customer`
     * instead).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
     * @param  array{sale_id: int, date: string, reason?: string|null, refund_account_id?: int|null, refund_cash_amount?: string|null, refund_bank_amount?: string|null, store_id?: int|null, expected_total?: string|null}  $data
     * @param  array<int, array{sale_line_id: int, quantity: string|float, bonus_quantity?: string|float}>  $lines
     */
    public static function request(array $data, array $lines, User $actor): self
    {
        return static::record($data, $lines, $actor, post: false);
    }

    /**
     * Shared body of post() and request(): everything except whether the
     * money side is written now or deferred to approve().
     *
     * @param  array{sale_id: int, date: string, reason?: string|null, refund_account_id?: int|null, refund_cash_amount?: string|null, refund_bank_amount?: string|null, store_id?: int|null, expected_total?: string|null}  $data
     * @param  array<int, array{sale_line_id: int, quantity: string|float, bonus_quantity?: string|float}>  $lines
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
            $refundCashAmount = static::validatedRefundSplitAmount($data['refund_cash_amount'] ?? null);
            $refundBankAmount = static::validatedRefundSplitAmount($data['refund_bank_amount'] ?? null);

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
                'refund_cash_amount' => $refundCashAmount,
                'refund_bank_amount' => $refundBankAmount,
                'created_by' => $actor->id,
            ]);

            // Every line of the credit-note voucher carries the same compact
            // narration (audit section 3 "Sales", "ledger narrations") - a
            // credit note has no settlement leg of its own, so it always
            // reads "{number} - Credit" (SettlementNarration::forMode(null)).
            if ($voucher) {
                $voucher->lines()->update([
                    'narration' => SettlementNarration::line($salesReturn->documentNumber(), null),
                ]);
            }

            foreach ($prepared['lines'] as $line) {
                $returnLine = $salesReturn->lines()->create([
                    'sale_line_id' => $line['sale_line']->id,
                    'quantity' => $line['quantity'],
                    'bonus_quantity' => $line['bonus_quantity'],
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
                $sale->loadMissing('customer');
                static::postRefund($salesReturn, $sale->customer->account_id, $prepared['total']->minus($prepared['tds']), $date, $actor);
            }

            return $salesReturn;
        });
    }

    /**
     * A return with no bill this system ever issued to point at (audit
     * section 3 "Sales", "returns without a bill"): a pre-cutover sale, a
     * walk-in who lost their receipt, a return against a paper invoice from
     * before this tenant went live. Each line names its item and an entered
     * rate directly - there is no SaleLine to inherit one from - and is
     * taxed at the company's CURRENT VAT rate (CompanySetting::
     * default_vat_rate), never a rate frozen on some other document. Shares
     * the same gapless credit-note series and `credit_note_number` format
     * as a linked return (C7) - on paper it is still the same kind of
     * document, so VAT books and the credit-note register read it exactly
     * like any other posted return.
     *
     * Deliberately simpler than a linked return: no header discount, no TDS
     * (there is no withholding relationship to reconstruct without a bill),
     * and no per-line VAT breakdown is stored (the document-level `vat_
     * amount` already carries the true total; a fabricated per-line split
     * would only ever be a display nicety). Always posts directly - there
     * is no original invoice for a later approve() to re-check quantities
     * against, so the request()/approve() workflow does not apply here.
     * Goods only ever move INTO stock on a return, so - unlike a sale -
     * there is no negative-stock check to make.
     *
     * @param  array{customer_id: int, date: string, reason?: string|null, store_id?: int|null, refund_account_id?: int|null, refund_cash_amount?: string|null, refund_bank_amount?: string|null, expected_total?: string|null}  $data
     * @param  array<int, array{item_id: int, item_unit_id?: int|null, quantity: string|float, rate: string|float, bonus_quantity?: string|float}>  $lines
     */
    public static function postUnlinked(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            $customer = Customer::findOrFail($data['customer_id']);
            $date = static::validatedUnlinkedDate($data['date'], $actor);
            $storeId = static::resolveUnlinkedStoreId($data['store_id'] ?? null);
            $refundAccountId = static::validatedRefundAccountId($data['refund_account_id'] ?? null);
            $refundCashAmount = static::validatedRefundSplitAmount($data['refund_cash_amount'] ?? null);
            $refundBankAmount = static::validatedRefundSplitAmount($data['refund_bank_amount'] ?? null);

            $settings = CompanySetting::current();
            $items = Item::with('units')->whereIn('id', collect($lines)->pluck('item_id'))->get()->keyBy('id');

            [$calculatorLines, $preparedLines] = static::prepareUnlinkedLines($lines, $items);

            $totals = DocumentCalculator::calculate($calculatorLines, [
                'vat_rate' => $settings->default_vat_rate,
                'expected_total' => $data['expected_total'] ?? null,
            ]);

            $voucherLines = [];
            $salesAccountId = Account::where('code', 'INI20')->firstOrFail()->id;

            $revenueEntries = array_map(
                static fn (array $prepared, LineTotals $line): array => ['item' => $prepared['item'], 'net' => $line->lineTotal],
                $preparedLines,
                $totals->lines,
            );

            foreach (static::revenueByAccount($revenueEntries, $salesAccountId) as $accountId => $amount) {
                $voucherLines[] = ['account_id' => $accountId, 'debit' => $amount->toString(), 'credit' => '0', 'narration' => 'Sales return'];
            }

            if ($totals->vatAmount->isPositive()) {
                $voucherLines[] = [
                    'account_id' => Account::where('code', 'LIA20')->firstOrFail()->id,
                    'debit' => $totals->vatAmount->toString(),
                    'credit' => '0',
                    'narration' => 'VAT reversed',
                ];
            }

            $voucherLines[] = [
                'account_id' => $customer->account_id,
                'debit' => '0',
                'credit' => $totals->total->toString(),
                'narration' => 'Sales return credit note',
            ];

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::SaleReturn->value,
                    'date' => $date,
                    'narration' => $data['reason'] ?? "Unlinked return from {$customer->name}",
                ],
                $voucherLines,
                $actor,
            );

            $salesReturn = static::create([
                'sale_id' => null,
                'customer_id' => $customer->id,
                'is_unlinked' => true,
                'journal_voucher_id' => $voucher->id,
                'fiscal_year_id' => $voucher->fiscal_year_id,
                'credit_note_number' => static::creditNoteNumberFor($voucher),
                'date' => $date,
                'store_id' => $storeId,
                'reason' => $data['reason'] ?? null,
                'taxable_amount' => $totals->taxableAmount,
                'nontaxable_amount' => $totals->nontaxableAmount,
                'vat_amount' => $totals->vatAmount,
                'vat_rate' => $totals->vatRate,
                'tds_amount' => Money::zero(),
                'total' => $totals->total,
                'status' => 'posted',
                'refund_account_id' => $refundAccountId,
                'refund_cash_amount' => $refundCashAmount,
                'refund_bank_amount' => $refundBankAmount,
                'created_by' => $actor->id,
            ]);

            $voucher->lines()->update([
                'narration' => SettlementNarration::line($salesReturn->documentNumber(), null),
            ]);

            foreach ($preparedLines as $index => $prepared) {
                /** @var LineTotals $line */
                $line = $totals->lines[$index];
                $item = $prepared['item'];
                $bonusQuantity = $prepared['bonus_quantity'];

                $returnLine = $salesReturn->lines()->create([
                    'item_id' => $item->id,
                    'item_unit_id' => $prepared['item_unit_id'],
                    'unit_conversion_factor' => $line->conversionFactor,
                    'vatable' => $line->vatable,
                    'quantity' => $line->quantity,
                    'bonus_quantity' => $bonusQuantity,
                    'rate' => $line->rate,
                    'line_total' => $line->lineTotal,
                    'net_amount' => $line->lineTotal,
                    'vat_amount' => Money::zero(),
                    'tds_amount' => Money::zero(),
                ]);

                if ($item->is_stockable) {
                    $bonusBaseQuantity = $bonusQuantity->multipliedBy($line->conversionFactor);

                    $item->recordStockMovement(
                        StockMovementType::SaleReturn,
                        $line->baseQuantity->plus($bonusBaseQuantity),
                        $date,
                        $storeId,
                        $returnLine,
                    );
                }
            }

            static::postRefund($salesReturn, $customer->account_id, $totals->total, $date, $actor);

            return $salesReturn;
        });
    }

    /**
     * Turns an unlinked-return request's lines into calculator input plus
     * the item/unit context persistence needs afterwards - the same shape
     * Sale::prepareLines() builds, since an unlinked return is priced
     * exactly like a fresh sale of the same goods (just credited instead of
     * charged).
     *
     * @param  array<int, array{item_id: int, item_unit_id?: int|null, quantity: string|float, rate: string|float, bonus_quantity?: string|float}>  $lines
     * @param  Collection<int, Item>  $items
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private static function prepareUnlinkedLines(array $lines, $items): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A return needs at least one line.');
        }

        $calculatorLines = [];
        $preparedLines = [];

        foreach ($lines as $line) {
            if (! $items->has($line['item_id'])) {
                throw new InvalidArgumentException("Unknown item [{$line['item_id']}].");
            }

            $item = $items[$line['item_id']];
            [$itemUnitId, $conversionFactor] = static::resolveUnlinkedItemUnit($item, $line['item_unit_id'] ?? null);

            $calculatorLines[] = [
                'quantity' => $line['quantity'],
                'rate' => $line['rate'],
                'vatable' => $item->is_vatable,
                'conversion_factor' => $conversionFactor,
            ];

            $bonusQuantity = Quantity::of($line['bonus_quantity'] ?? '0');

            if ($bonusQuantity->isNegative()) {
                throw new InvalidArgumentException('Bonus return quantity cannot be negative.');
            }

            $preparedLines[] = [
                'item' => $item,
                'item_unit_id' => $itemUnitId,
                'bonus_quantity' => $bonusQuantity,
            ];
        }

        return [$calculatorLines, $preparedLines];
    }

    /**
     * @return array{0: int|null, 1: Quantity}
     */
    private static function resolveUnlinkedItemUnit(Item $item, mixed $itemUnitId): array
    {
        if ($itemUnitId === null || $itemUnitId === '') {
            return [null, Quantity::of(1)];
        }

        $itemUnit = $item->units->firstWhere('id', (int) $itemUnitId);

        if (! $itemUnit) {
            throw new InvalidArgumentException("Unit [{$itemUnitId}] does not belong to item [{$item->id}].");
        }

        return [$itemUnit->id, Quantity::of($itemUnit->conversion_factor)];
    }

    /**
     * The store an unlinked return's goods come back into: the explicit
     * choice, else the tenant's configured default store, else the
     * lowest-id active store - same fallback chain Sale::resolveStoreId()
     * uses (there is no parent document here to default to instead).
     */
    private static function resolveUnlinkedStoreId(mixed $storeId): int
    {
        $storeId = $storeId !== null && $storeId !== ''
            ? (int) $storeId
            : (CompanySetting::current()->default_store_id ?? Store::where('is_active', true)->orderBy('id')->value('id'));

        if (! $storeId) {
            throw new InvalidArgumentException('No active store is configured.');
        }

        return (int) $storeId;
    }

    /**
     * An unlinked return is dated inside a fiscal year that is open (or
     * deliberately reopened for correction) - same guard every dated
     * posting in this app goes through (C4). There is no parent sale date
     * to compare against, unlike validatedDate() above.
     */
    private static function validatedUnlinkedDate(string $date, User $actor): string
    {
        $date = substr(trim($date), 0, 10);
        ClosedFiscalYearGuard::assertDateInOpenYear($date, $actor);

        return $date;
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

            $storedLines = $salesReturn->lines()->with('saleLine.item:id,account_id')->orderBy('sale_line_id')->get();
            $salesReturn->assertQuantitiesStillAvailable($sale, $storedLines);

            $prepared = [
                'taxable' => Money::of($salesReturn->taxable_amount),
                'nontaxable' => Money::of($salesReturn->nontaxable_amount),
                'vat' => Money::of($salesReturn->vat_amount),
                'tds' => Money::of($salesReturn->tds_amount),
                'total' => Money::of($salesReturn->total),
                'lines' => $storedLines->map(fn (SaleReturnLine $line): array => [
                    'sale_line' => $line->saleLine,
                    'net' => Money::of($line->net_amount),
                ])->all(),
            ];

            $voucher = static::postCreditNote($sale, $prepared, $date, $salesReturn->reason, $actor);

            $salesReturn->update([
                'journal_voucher_id' => $voucher->id,
                'fiscal_year_id' => $voucher->fiscal_year_id,
                'credit_note_number' => static::creditNoteNumberFor($voucher),
                'status' => 'posted',
            ]);

            $voucher->lines()->update([
                'narration' => SettlementNarration::line($salesReturn->documentNumber(), null),
            ]);

            $movedStock = static::saleLinesThatMovedStock($storedLines->pluck('sale_line_id')->all());

            foreach ($storedLines as $line) {
                if (! in_array($line->sale_line_id, $movedStock, true)) {
                    continue;
                }

                static::recordReturnMovement(
                    [
                        'sale_line' => $line->saleLine,
                        'quantity' => Quantity::of($line->quantity),
                        'bonus_quantity' => Quantity::of($line->bonus_quantity ?? '0'),
                    ],
                    $line,
                    $date,
                    $salesReturn->store_id,
                );
            }

            $sale->loadMissing('customer');
            static::postRefund($salesReturn, $sale->customer->account_id, $prepared['total']->minus($prepared['tds']), $date, $actor);

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
            $bonusQuantity = Quantity::of($saleLine->bonus_quantity ?? '0');
            $bonusReturned = $credited[$saleLine->id]['bonus_quantity'];

            $lines[] = [
                'sale_line_id' => $saleLine->id,
                'item' => $saleLine->item?->name ?? '',
                'unit' => $saleLine->itemUnit?->name ?? ($saleLine->item?->unit ?? ''),
                'quantity' => $quantity->toString(),
                'rate' => Quantity::of($saleLine->rate)->toString(),
                'returned' => $returned->toString(),
                'remaining' => $quantity->minus($returned)->toString(),
                // Bonus units (audit section 3 "Sales"): can be returned
                // alongside the paid quantity above, at zero value.
                'bonus_quantity' => $bonusQuantity->toString(),
                'bonus_returned' => $bonusReturned->toString(),
                'bonus_remaining' => $bonusQuantity->minus($bonusReturned)->toString(),
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
     * @param  array<int, array{sale_line_id: int, quantity: string|float, bonus_quantity?: string|float}>  $lines
     * @param  int|null  $excludeReturnId  Ignore this return's own reserved quantity (re-validating an approval).
     * @return array{lines: list<array{sale_line: SaleLine, quantity: Quantity, bonus_quantity: Quantity, net: Money, vat: Money, tds: Money, moves_stock: bool}>, taxable: Money, nontaxable: Money, vat: Money, tds: Money, total: Money}
     */
    private static function prepareLines(Sale $sale, array $lines, ?int $excludeReturnId = null): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A return needs at least one line.');
        }

        $requested = [];
        $requestedBonus = [];

        foreach ($lines as $line) {
            $saleLineId = (int) $line['sale_line_id'];
            $quantity = Quantity::of($line['quantity']);

            if (! $quantity->isPositive()) {
                throw new InvalidArgumentException('Return quantity must be greater than zero.');
            }

            $requested[$saleLineId] = isset($requested[$saleLineId])
                ? $requested[$saleLineId]->plus($quantity)
                : $quantity;

            // Bonus units returned alongside this line's paid quantity
            // (audit section 3 "Sales", "bonus/free quantity"): credits
            // nothing (see the money loop below, which never reads this),
            // only restocks - a return is always anchored on a positive paid
            // quantity, a bonus-only return is out of scope for this pass.
            $bonusQuantity = Quantity::of($line['bonus_quantity'] ?? '0');

            if ($bonusQuantity->isNegative()) {
                throw new InvalidArgumentException('Bonus return quantity cannot be negative.');
            }

            $requestedBonus[$saleLineId] = isset($requestedBonus[$saleLineId])
                ? $requestedBonus[$saleLineId]->plus($bonusQuantity)
                : $bonusQuantity;
        }

        ksort($requested);

        // Every line of the sale is locked, not just the returned ones: the
        // components below are split across all of them, so a concurrent
        // return against any line changes what this one may credit.
        $saleLines = SaleLine::where('sale_id', $sale->id)->with('item:id,account_id')->orderBy('id')->lockForUpdate()->get()->keyBy('id');

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

            $bonusQuantity = $requestedBonus[$saleLineId] ?? Quantity::zero();
            $lineBonusQuantity = Quantity::of($saleLine->bonus_quantity ?? '0');
            $alreadyReturnedBonus = $credited[$saleLineId]['bonus_quantity'];
            $remainingBonus = $lineBonusQuantity->minus($alreadyReturnedBonus);

            if ($bonusQuantity->isGreaterThan($remainingBonus)) {
                throw new InvalidArgumentException(
                    "Cannot return {$bonusQuantity->formatQuantity()} bonus units of line [{$saleLineId}]; only {$remainingBonus->formatQuantity()} remain returnable."
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
                'bonus_quantity' => $bonusQuantity,
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
     * @return array<int, array{quantity: Quantity, bonus_quantity: Quantity, net: Money, vat: Money, tds: Money}>
     */
    private static function alreadyCredited(array $saleLineIds, ?int $excludeReturnId = null): array
    {
        $credited = [];

        foreach ($saleLineIds as $saleLineId) {
            $credited[$saleLineId] = [
                'quantity' => Quantity::zero(),
                'bonus_quantity' => Quantity::zero(),
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
            $credited[$row->sale_line_id]['bonus_quantity'] = $credited[$row->sale_line_id]['bonus_quantity']->plus(Quantity::of($row->bonus_quantity ?? '0'));
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
     * Goods come back in BASE units: the returned quantity (paid plus any
     * bonus units returned alongside it) times the original line's
     * `unit_conversion_factor` (audit P0-12, and section 3 "Sales" for the
     * bonus addition). Returning 1 Box of an item sold in Boxes of 12 puts
     * 12 base units back.
     *
     * @param  array{sale_line: SaleLine, quantity: Quantity, bonus_quantity?: Quantity}  $line
     */
    private static function recordReturnMovement(array $line, SaleReturnLine $returnLine, string $date, int $storeId): void
    {
        $saleLine = $line['sale_line'];
        $returnedQuantity = $line['quantity']->plus($line['bonus_quantity'] ?? Quantity::zero());
        $baseQuantity = $returnedQuantity->multipliedBy(Quantity::of($saleLine->unit_conversion_factor));

        $saleLine->item->recordStockMovement(
            StockMovementType::SaleReturn,
            $baseQuantity->toString(),
            $date,
            $storeId,
            $returnLine,
        );
    }

    /**
     * Revenue debited/credited back per account (audit section 3 "Sales",
     * "service revenue"): reverses each returned line against the SAME
     * account the original sale would have credited (`item->account_id ??
     * INI20`), rather than a single hardcoded Sales Revenue line - matching
     * Purchase::post()'s own per-item grouping and Sale::revenueByAccount()'s
     * mirror of it. Shared by a linked return (postCreditNote(), which maps
     * each line's `sale_line->item` in) and an unlinked one (postUnlinked(),
     * which already has the item), so both group revenue the same way.
     *
     * No header-discount reallocation is needed here (unlike Sale::
     * revenueByAccount()): a linked line's `net` already has its share of
     * the header discount baked in (saleComponents()/netOfHeaderDiscount()),
     * and an unlinked return supports no header discount at all, so summing
     * `net` per account is exact on its own either way.
     *
     * @param  list<array{item: Item, net: Money}>  $entries
     * @return array<int, Money>
     */
    private static function revenueByAccount(array $entries, int $fallbackAccountId): array
    {
        $byAccount = [];

        foreach ($entries as $entry) {
            $accountId = $entry['item']->account_id ?? $fallbackAccountId;
            $byAccount[$accountId] = ($byAccount[$accountId] ?? Money::zero())->plus($entry['net']);
        }

        return array_filter($byAccount, static fn (Money $amount): bool => ! $amount->isZero());
    }

    /**
     * @param  array{taxable: Money, nontaxable: Money, vat: Money, tds: Money, total: Money, lines: list<array{sale_line: SaleLine, net: Money}>}  $prepared
     */
    private static function postCreditNote(Sale $sale, array $prepared, string $date, ?string $reason, User $actor): JournalVoucher
    {
        $sale->loadMissing('customer');

        $voucherLines = [];

        $salesAccountId = Account::where('code', 'INI20')->firstOrFail()->id;

        $revenueEntries = array_map(
            static fn (array $line): array => ['item' => $line['sale_line']->item, 'net' => $line['net']],
            $prepared['lines'],
        );

        foreach (static::revenueByAccount($revenueEntries, $salesAccountId) as $accountId => $amount) {
            $voucherLines[] = ['account_id' => $accountId, 'debit' => $amount->toString(), 'credit' => '0', 'narration' => 'Sales return'];
        }

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
     * Split cash+bank refund (audit section 4 polish, "split cash+bank
     * refund"), for both a linked and an unlinked return: `refund_cash_
     * amount`/`refund_bank_amount` must add up to $customerCredit exactly
     * (DocumentCalculator::assertExactSplit(), C3) when either is set. A
     * return posted before this feature existed (or one that only ever set
     * `refund_account_id`, matching the form's original single-account
     * picker) still refunds the whole credit through that one account -
     * additive, not a breaking change.
     *
     * `$customerAccountId` is passed in rather than derived from `$sale`
     * here, because an unlinked return (postUnlinked()) has no Sale to read
     * a customer off at all (C7 "returns without a bill").
     */
    private static function postRefund(self $salesReturn, int $customerAccountId, Money $customerCredit, string $date, User $actor): void
    {
        if (! $customerCredit->isPositive()) {
            return;
        }

        $hasSplit = $salesReturn->refund_cash_amount !== null || $salesReturn->refund_bank_amount !== null;

        if ($hasSplit) {
            $cash = Money::of($salesReturn->refund_cash_amount ?? '0');
            $bank = Money::of($salesReturn->refund_bank_amount ?? '0');

            DocumentCalculator::assertExactSplit($customerCredit, $cash, $bank);

            if ($bank->isPositive() && ! $salesReturn->refund_account_id) {
                throw new InvalidArgumentException('A bank account is required for the bank portion of a refund.');
            }
        } elseif ($salesReturn->refund_account_id) {
            $cash = Money::zero();
            $bank = $customerCredit;
        } else {
            return;
        }

        // Money leaves the business, so every money account is CREDITED and
        // the customer is DEBITED: the credit note already credited them,
        // and the refund clears that credit balance back to where it stood
        // before the sale. Reversing these two legs would both double the
        // customer's credit and inflate cash/bank.
        $voucherLines = [];

        if ($cash->isPositive()) {
            $voucherLines[] = [
                'account_id' => Account::where('code', 'AS1')->firstOrFail()->id,
                'debit' => '0',
                'credit' => $cash->toString(),
                'narration' => 'Refund settlement',
            ];
        }

        if ($bank->isPositive()) {
            $voucherLines[] = ['account_id' => $salesReturn->refund_account_id, 'debit' => '0', 'credit' => $bank->toString(), 'narration' => 'Refund settlement'];
        }

        if ($voucherLines === []) {
            return;
        }

        $voucherLines[] = ['account_id' => $customerAccountId, 'debit' => $cash->plus($bank)->toString(), 'credit' => '0', 'narration' => 'Refund settlement'];

        $refundVoucher = JournalVoucher::post(
            [
                'voucher_type' => VoucherType::Journal->value,
                'date' => $date,
                'narration' => "Refund for {$salesReturn->documentNumber()}",
            ],
            $voucherLines,
            $actor,
        );

        $salesReturn->update(['refund_journal_voucher_id' => $refundVoucher->id]);

        $mode = $cash->isPositive() && $bank->isPositive() ? 'partial' : ($cash->isPositive() ? 'cash' : 'bank');
        $refundVoucher->lines()->update(['narration' => SettlementNarration::line($salesReturn->documentNumber(), $mode)]);
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

            $remainingBonus = Quantity::of($saleLine->bonus_quantity ?? '0')->minus($credited[$line->sale_line_id]['bonus_quantity']);

            if (Quantity::of($line->bonus_quantity ?? '0')->isGreaterThan($remainingBonus)) {
                throw new InvalidArgumentException(
                    "This request can no longer be approved: only {$remainingBonus->formatQuantity()} bonus units of line [{$line->sale_line_id}] remain returnable."
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
     * A blank cash/bank refund split field reads as "not given" (postRefund()
     * falls back to the pre-split single-account shape), never a silent
     * zero - the same convention `validatedRefundAccountId()` above uses.
     */
    private static function validatedRefundSplitAmount(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $money = Money::of($amount);

        if ($money->isNegative()) {
            throw new InvalidArgumentException('A refund amount cannot be negative.');
        }

        return $money->toString();
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
