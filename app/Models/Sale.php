<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Support\Billing\BillingException;
use App\Support\Billing\DocumentCalculator;
use App\Support\Billing\DocumentTotals;
use App\Support\Billing\LineTotals;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use App\Support\SettlementNarration;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A sale posts only the money side to the ledger (customer/cash-bank,
 * sales revenue, VAT, optional TDS) via JournalVoucher::post() - it never
 * posts an inventory-asset/COGS line. Legacy day_khata runs periodic, not
 * perpetual, inventory accounting (confirmed via an explicit docblock in
 * the legacy StockAdjustmentController); stock quantity is tracked
 * separately through Item::recordStockMovement(), fully decoupled from
 * the ledger. See day-khata-multi-tenant mem.md for the full research
 * this was built from.
 *
 * Every rupee on this document is computed by App\Support\Billing\
 * DocumentCalculator (CONTRACTS C3) and held as App\Support\Money\Money.
 * There is no float arithmetic anywhere in this class: the 2026-09-11 audit
 * measured 0.39% of two-decimal products rounding the wrong way under PHP's
 * round() (P0-1), and the browser preview disagreeing with the stored bill
 * (P0-8). Both sides now run the same algorithm on exact decimals.
 */
#[Fillable([
    'customer_id', 'agent_id', 'commission_amount', 'store_id', 'journal_voucher_id', 'fiscal_year_id',
    'invoice_number', 'buyer_name', 'buyer_pan', 'buyer_address',
    'invoice_type', 'chalani_number', 'date', 'payment_mode',
    'bank_account_id', 'discount', 'discount_type', 'discount_amount', 'taxable_amount', 'nontaxable_amount',
    'vat_rate', 'vat_amount', 'total', 'cash_amount', 'bank_amount',
    'tds_account_id', 'tds_amount', 'narration', 'status', 'created_by',
    'cancelled_at', 'cancelled_by', 'cancel_reason', 'reversal_journal_voucher_id',
])]
class Sale extends Model
{
    /** An abbreviated tax invoice may not be issued above this value (IRD rule). */
    private const ABBREVIATED_INVOICE_CEILING = '10000.00';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cancelled_at' => 'datetime',
            'discount' => Decimal::class.':2',
            'discount_amount' => Decimal::class.':2',
            'taxable_amount' => Decimal::class.':2',
            'nontaxable_amount' => Decimal::class.':2',
            'vat_rate' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'total' => Decimal::class.':2',
            'cash_amount' => Decimal::class.':2',
            'bank_amount' => Decimal::class.':2',
            'tds_amount' => Decimal::class.':2',
            'commission_amount' => Decimal::class.':2',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }

    /**
     * The Reversal voucher posted when this sale was cancelled (C5), or null
     * while it is still live.
     *
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function reversalJournalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class, 'reversal_journal_voucher_id');
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
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function tdsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'tds_account_id');
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
     * @return HasMany<SaleLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    /**
     * @return HasMany<SalesReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    /**
     * @return HasMany<ReceiptAllocation, $this>
     */
    public function receiptAllocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class);
    }

    /**
     * What the customer still owes on this invoice, exactly (CONTRACTS C7/T04
     * item 4). The single source of truth for Receipt::post()'s
     * over-allocation guard, the aged-receivables report and the debtors list,
     * so those can never drift apart.
     *
     *   total
     *   - tds_amount                (withheld by the buyer, never collectable)
     *   - settled_at_posting        (settlement_due for cash/bank/partial, 0 for credit)
     *   - net credit of posted returns
     *   + refunds already paid out on those returns
     *   - allocations of non-cancelled receipts
     *
     * Two rules the audit forced:
     *
     * - TDS is subtracted (P1 "Ledger and balances"): a credit invoice with
     *   TDS could never be settled before, because the buyer only ever pays
     *   `total - tds`, leaving the invoice permanently short.
     * - Only `status = 'posted'` returns count (P0-13). A pending request has
     *   no money effect at all and a rejected one never had any; counting them
     *   permanently understated what the customer owed.
     *
     * A return's own credit to the customer is `return.total` less the TDS
     * that return actually reversed, read from `sales_returns.tds_amount`.
     * That column stores what the credit-note voucher posted (the sum of the
     * per-line C6 shares), so the customer balance and the ledger can never
     * disagree. Re-deriving the share here as `tds x returned / total` looked
     * equivalent but drifts by a paisa once a note has several lines and a
     * header discount, because the per-line shares round independently.
     */
    public function outstandingAmount(): Money
    {
        $total = Money::of($this->total);
        $tds = Money::of($this->tds_amount);
        $settlementDue = $total->minus($tds);

        $settledAtPosting = $this->payment_mode === 'credit' ? Money::zero() : $settlementDue;

        $returnedNet = Money::zero();
        $refunded = Money::zero();

        $postedReturns = $this->returns()
            ->where('status', 'posted')
            ->orderBy('id')
            ->get(['id', 'total', 'tds_amount', 'refund_journal_voucher_id']);

        foreach ($postedReturns as $return) {
            $credit = Money::of($return->total)->minus(Money::of($return->tds_amount));

            $returnedNet = $returnedNet->plus($credit);

            if ($return->refund_journal_voucher_id !== null) {
                $refunded = $refunded->plus($credit);
            }
        }

        $allocated = Money::sum(
            $this->receiptAllocations()
                ->whereHas('receipt', fn ($query) => $query->where('status', '!=', 'cancelled'))
                ->pluck('amount')
                ->map(fn ($amount) => Money::of($amount))
        );

        return $settlementDue
            ->minus($settledAtPosting)
            ->minus($returnedNet)
            ->plus($refunded)
            ->minus($allocated);
    }

    /**
     * The rupee amount the header discount removed from this bill.
     *
     * Stored at posting since this rewrite (see the 2026_09_12_040000
     * migration): `discount` alone is ambiguous (a raw percentage when
     * `discount_type` is 'percentage') and the old algebraic reconstruction
     * from `taxable_amount` cannot be exact once the discount is split
     * proportionally between the taxable and exempt subtotals (C3 step 4).
     */
    public function discountAmount(): Money
    {
        return Money::of($this->discount_amount);
    }

    /**
     * Computes the sale's totals, posts one balanced JournalVoucher for
     * the money side, creates the Sale + SaleLine rows, and records a
     * stock movement per stockable line.
     *
     * The VAT rate is never taken from the request: it is always the tenant's
     * `CompanySetting::default_vat_rate` (audit P1 - the rate used to be
     * whatever the browser sent). A PAN invoice forces the rate to 0 and every
     * line non-taxable through the calculator's `force_non_taxable`, which is
     * what closes P0-10: PAN bills used to charge a hidden 13% that the PDF
     * then hid from the customer while it still posted to LIA20.
     *
     * @param  array{customer_id: int, invoice_type: string, chalani_number?: string|null, date: string, payment_mode: string, bank_account_id?: int|null, discount?: string|float, discount_type?: string, cash_amount?: string|float|null, bank_amount?: string|float|null, tds_account_id?: int|null, tds_amount?: string|float, agent_id?: int|null, commission_amount?: string|float, narration?: string|null, store_id?: int|null, expected_total?: string|null}  $data
     * @param  array<int, array{item_id: int, item_unit_id?: int|null, quantity: string|float, rate: string|float, discount?: string|float, discount_type?: string}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            $settings = CompanySetting::current();
            $customer = Customer::findOrFail($data['customer_id']);
            $invoiceType = static::validatedInvoiceType($data['invoice_type'] ?? 'full', $settings);
            $storeId = static::resolveStoreId($data, $settings);

            $items = Item::with('units')->whereIn('id', collect($lines)->pluck('item_id'))->get()->keyBy('id');

            [$calculatorLines, $preparedLines] = static::prepareLines($lines, $items);

            $totals = DocumentCalculator::calculate($calculatorLines, [
                'vat_rate' => $settings->default_vat_rate,
                'discount' => $data['discount'] ?? '0',
                'discount_type' => $data['discount_type'] ?? 'flat',
                'tds_amount' => $data['tds_amount'] ?? '0',
                'force_non_taxable' => $invoiceType === 'pan',
                'expected_total' => $data['expected_total'] ?? null,
            ]);

            static::assertAbbreviatedCeiling($invoiceType, $totals->total);

            $commissionAmount = static::validatedCommission($data, $totals->total);
            $agentId = $data['agent_id'] ?? null;
            $agent = $agentId ? Agent::findOrFail($agentId) : null;

            static::assertStockAvailable($preparedLines, $totals, $storeId, $settings);

            $tdsAccountId = $data['tds_account_id'] ?? null;

            if ($totals->tdsAmount->isPositive() && ! $tdsAccountId) {
                throw new InvalidArgumentException('A TDS account is required when a TDS amount is set.');
            }

            [$paymentMode, $cashAmount, $bankAmount, $bankAccountId] = static::resolvePayment($data, $totals->settlementDue);

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => static::voucherTypeFor($invoiceType)->value,
                    'date' => $data['date'],
                    'narration' => $data['narration'] ?? "Sale to {$customer->name}",
                ],
                static::voucherLines($customer, $totals, $preparedLines, $paymentMode, $cashAmount, $bankAmount, $bankAccountId, $tdsAccountId, $agent, $commissionAmount),
                $actor,
            );

            $sale = static::create([
                'customer_id' => $customer->id,
                'agent_id' => $agentId,
                'commission_amount' => $commissionAmount,
                'store_id' => $storeId,
                'journal_voucher_id' => $voucher->id,
                'fiscal_year_id' => $voucher->fiscal_year_id,
                'invoice_number' => static::invoiceNumberFor($invoiceType, $voucher, $settings),
                'buyer_name' => $customer->name,
                'buyer_pan' => $customer->tpin,
                'buyer_address' => $customer->address,
                'invoice_type' => $invoiceType,
                'chalani_number' => $data['chalani_number'] ?? null,
                'date' => $data['date'],
                'payment_mode' => $paymentMode,
                'bank_account_id' => $bankAccountId,
                'discount' => static::storedHeaderDiscount($data, $totals),
                'discount_type' => $data['discount_type'] ?? 'flat',
                'discount_amount' => $totals->headerDiscount,
                'taxable_amount' => $totals->taxableAmount,
                'nontaxable_amount' => $totals->nontaxableAmount,
                'vat_rate' => $totals->vatRate,
                'vat_amount' => $totals->vatAmount,
                'total' => $totals->total,
                'cash_amount' => $paymentMode === 'partial' ? $cashAmount : null,
                'bank_amount' => $paymentMode === 'partial' ? $bankAmount : null,
                'tds_account_id' => $tdsAccountId,
                'tds_amount' => $totals->tdsAmount,
                'narration' => $data['narration'] ?? null,
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            static::persistLines($sale, $preparedLines, $totals, $storeId, $data['date']);

            // Every line of this voucher carries the same compact narration
            // (audit section 3 "Sales", "ledger narrations"), so the
            // customer's ledger reads "SL-42 - Cash Settlement" instead of a
            // bare "Sale total"/"Settlement" and tells a sale from a receipt
            // at a glance. Applied AFTER posting because the invoice number
            // itself is derived from this same voucher's number (C7) - there
            // is no way to know it before JournalVoucher::post() returns.
            $voucher->lines()->update([
                'narration' => SettlementNarration::line($sale->invoice_number, $paymentMode),
            ]);

            return $sale;
        });
    }

    /**
     * Turns the request's lines into calculator input plus the item/unit
     * context the persistence step needs afterwards.
     *
     * `vatable` comes from the item, never from the request - the browser has
     * no say in whether a line carries VAT.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  Collection<int, Item>  $items
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private static function prepareLines(array $lines, $items): array
    {
        $calculatorLines = [];
        $preparedLines = [];

        foreach ($lines as $line) {
            if (! $items->has($line['item_id'])) {
                throw new InvalidArgumentException("Unknown item [{$line['item_id']}].");
            }

            $item = $items[$line['item_id']];
            [$itemUnitId, $conversionFactor] = static::resolveItemUnit($item, $line['item_unit_id'] ?? null);

            $calculatorLines[] = [
                'quantity' => $line['quantity'],
                'rate' => $line['rate'],
                'discount' => $line['discount'] ?? '0',
                'discount_type' => $line['discount_type'] ?? 'flat',
                'vatable' => $item->is_vatable,
                'conversion_factor' => $conversionFactor,
            ];

            // Bonus/free quantity (audit section 3 "Sales"): extra pieces
            // handed over at no charge alongside the paid quantity.
            // Deliberately kept OUT of $calculatorLines above - the money
            // side (revenue, VAT, discounts) is computed on the paid
            // quantity only; only the stock movement sees the bonus
            // (persistLines()/assertStockAvailable() below).
            $bonusQuantity = Quantity::of($line['bonus_quantity'] ?? '0');

            if ($bonusQuantity->isNegative()) {
                throw new InvalidArgumentException('Bonus quantity cannot be negative.');
            }

            $preparedLines[] = [
                'item' => $item,
                'item_unit_id' => $itemUnitId,
                'raw_discount' => $line['discount'] ?? '0',
                'bonus_quantity' => $bonusQuantity,
            ];
        }

        return [$calculatorLines, $preparedLines];
    }

    /**
     * Writes the SaleLine rows and one stock movement per stockable line.
     *
     * The stock movement always uses the calculator's `baseQuantity` (the
     * as-entered quantity times the line's unit conversion factor), so 2 "Box"
     * of 12 moves 24 base units while the money side keeps using the
     * as-entered quantity and rate.
     *
     * @param  array<int, array<string, mixed>>  $preparedLines
     */
    private static function persistLines(self $sale, array $preparedLines, DocumentTotals $totals, int $storeId, string $date): void
    {
        foreach ($preparedLines as $index => $prepared) {
            /** @var LineTotals $line */
            $line = $totals->lines[$index];
            $item = $prepared['item'];
            $bonusQuantity = $prepared['bonus_quantity'];

            $saleLine = $sale->lines()->create([
                'item_id' => $item->id,
                'item_unit_id' => $prepared['item_unit_id'],
                'quantity' => $line->quantity,
                'bonus_quantity' => $bonusQuantity,
                'unit_conversion_factor' => $line->conversionFactor,
                'rate' => $line->rate,
                'discount' => $line->discountValue,
                'discount_type' => $line->discountType,
                'discount_amount' => $line->discountAmount,
                'vatable' => $line->vatable,
                'line_total' => $line->lineTotal,
            ]);

            if ($item->is_stockable) {
                // The bonus quantity leaves the shelf exactly like the paid
                // quantity does - it is real physical stock, just priced at
                // zero - so the stock movement moves both, converted to base
                // units by the same factor (audit section 3 "Sales").
                $bonusBaseQuantity = $bonusQuantity->multipliedBy($line->conversionFactor);

                $item->recordStockMovement(
                    StockMovementType::Sale,
                    $line->baseQuantity->plus($bonusBaseQuantity),
                    $date,
                    $storeId,
                    $saleLine,
                );
            }
        }
    }

    /**
     * Rejects an invoice type the tenant has switched off in Settings. The
     * enabled flags were saved but never enforced until now (audit P1
     * "Invoice and IRD compliance").
     */
    private static function validatedInvoiceType(string $invoiceType, CompanySetting $settings): string
    {
        $enabled = match ($invoiceType) {
            'full' => (bool) $settings->sale_full_enabled,
            'abbreviated' => (bool) $settings->sale_abbreviated_enabled,
            'pan' => (bool) $settings->sale_pan_enabled,
            default => throw new InvalidArgumentException("Invalid invoice type [{$invoiceType}]."),
        };

        if (! $enabled) {
            throw new InvalidArgumentException('This invoice type is turned off in Settings.');
        }

        return $invoiceType;
    }

    /**
     * An abbreviated tax invoice may not be issued for a bill above Rs 10,000
     * - until now that ceiling was only a printed note on the PDF, with
     * nothing stopping the bill being issued (audit P1).
     */
    private static function assertAbbreviatedCeiling(string $invoiceType, Money $total): void
    {
        if ($invoiceType === 'abbreviated' && $total->isGreaterThan(Money::of(self::ABBREVIATED_INVOICE_CEILING))) {
            throw new InvalidArgumentException('Use a full tax invoice above Rs 10,000.');
        }
    }

    /**
     * PAN invoices get their own gapless series (VoucherType::SalePan) rather
     * than sharing the full-invoice sequence and only differing by printed
     * prefix, which is how each printed series ended up with gaps (P0-15).
     */
    private static function voucherTypeFor(string $invoiceType): VoucherType
    {
        return match ($invoiceType) {
            'abbreviated' => VoucherType::SaleAbbreviated,
            'pan' => VoucherType::SalePan,
            default => VoucherType::Sale,
        };
    }

    /**
     * The invoice number stored on the row and printed forever after. Exactly
     * the `{prefix}-{voucher_number}` format the PDF derived on the fly until
     * now, so bills posted before this change reprint identically - the
     * difference is that changing the prefix in Settings no longer rewrites
     * the number on an already-issued invoice (C7).
     */
    private static function invoiceNumberFor(string $invoiceType, JournalVoucher $voucher, CompanySetting $settings): string
    {
        $prefix = match ($invoiceType) {
            'abbreviated' => $settings->sale_abbreviated_prefix,
            'pan' => $settings->sale_pan_prefix,
            default => $settings->sale_full_prefix,
        };

        return "{$prefix}-{$voucher->voucher_number}";
    }

    /**
     * What goes in the `discount` column: the raw percentage the user typed
     * when the type is 'percentage' (so the bill can print "5%"), or the flat
     * rupee amount otherwise. Both come back from the calculator already
     * validated and normalised.
     *
     * @param  array<string, mixed>  $data
     */
    private static function storedHeaderDiscount(array $data, DocumentTotals $totals): Money
    {
        return ($data['discount_type'] ?? 'flat') === 'percentage'
            ? Money::of($data['discount'] ?? '0')
            : $totals->headerDiscount;
    }

    /**
     * The store this sale moves stock out of: the explicit choice, else the
     * tenant's configured default store (which used to be saved and never
     * read - audit P1), else the lowest-id active store.
     *
     * @param  array<string, mixed>  $data
     */
    private static function resolveStoreId(array $data, CompanySetting $settings): int
    {
        $storeId = $data['store_id'] ?? $settings->default_store_id
            ?? Store::where('is_active', true)->orderBy('id')->value('id');

        if (! $storeId) {
            throw new InvalidArgumentException('No active store is configured.');
        }

        return (int) $storeId;
    }

    /**
     * Negative-stock enforcement, skipped entirely when the tenant has opted
     * into overselling via CompanySetting::allow_negative_stock.
     *
     * Item::lockForStockOut() locks the item rows in ascending id order before
     * anything is read (C10), so two terminals selling the last unit at the
     * same moment serialise instead of both passing the check (audit P1
     * "Negative-stock check reads without a lock"). Quantities are compared
     * exactly as Quantity values: the old float comparison rejected valid
     * operations and printed errors like "0.19999999999999998".
     *
     * @param  array<int, array<string, mixed>>  $preparedLines
     */
    private static function assertStockAvailable(array $preparedLines, DocumentTotals $totals, int $storeId, CompanySetting $settings): void
    {
        if ($settings->allow_negative_stock) {
            return;
        }

        $requestedByItem = [];

        foreach ($preparedLines as $index => $prepared) {
            $item = $prepared['item'];

            if (! $item->is_stockable) {
                continue;
            }

            $line = $totals->lines[$index];
            $bonusBaseQuantity = $prepared['bonus_quantity']->multipliedBy($line->conversionFactor);

            // Several lines can name the same item; the caps are checked
            // against the whole bill's demand, not line by line. Bonus units
            // leave the shelf too (audit section 3 "Sales"), so they count
            // against available stock exactly like the paid quantity.
            $requestedByItem[$item->id] = ($requestedByItem[$item->id] ?? Quantity::zero())
                ->plus($line->baseQuantity)
                ->plus($bonusBaseQuantity);
        }

        if ($requestedByItem === []) {
            return;
        }

        $locked = Item::lockForStockOut(array_keys($requestedByItem))->keyBy('id');
        $shortages = [];

        foreach ($requestedByItem as $itemId => $requested) {
            $item = $locked[$itemId];
            $available = $item->currentStock($storeId);

            if ($available->isLessThan($requested)) {
                $shortages[] = "{$item->name} (available {$available->formatQuantity()}, requested {$requested->formatQuantity()})";
            }
        }

        if ($shortages !== []) {
            throw new InvalidArgumentException('Insufficient stock for: '.implode(', ', $shortages));
        }
    }

    /**
     * Soft sanity guard only (not a hard business rule, per design) - catches
     * an obvious data-entry mistake (e.g. an extra digit) without blocking a
     * legitimate high-commission scenario.
     *
     * @param  array<string, mixed>  $data
     */
    private static function validatedCommission(array $data, Money $total): Money
    {
        $commission = Money::of($data['commission_amount'] ?? '0');

        if ($commission->isNegative()) {
            throw new InvalidArgumentException('Commission amount cannot be negative.');
        }

        if ($commission->isPositive() && $total->isPositive() && $commission->isGreaterThan($total->multipliedBy(5))) {
            throw new InvalidArgumentException('Commission amount is implausibly large relative to the sale total.');
        }

        return $commission;
    }

    /**
     * Resolves the payment mode's cash/bank split. A partial payment must add
     * up to the settlement due to the paisa: the old `abs(diff) > 0.01`
     * tolerance accepted a one-paisa mismatch and left it on the customer's
     * ledger forever (audit P0-4).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: Money|null, 2: Money|null, 3: int|null}
     */
    private static function resolvePayment(array $data, Money $settlementDue): array
    {
        $paymentMode = $data['payment_mode'];
        $bankAccountId = $data['bank_account_id'] ?? null;

        if (in_array($paymentMode, ['bank', 'partial'], true) && ! $bankAccountId) {
            throw new InvalidArgumentException('A bank account is required for bank or partial payment.');
        }

        $cashAmount = Money::ofNullable($data['cash_amount'] ?? null);
        $bankAmount = Money::ofNullable($data['bank_amount'] ?? null);

        if ($paymentMode === 'partial') {
            DocumentCalculator::assertExactSplit(
                $settlementDue,
                $cashAmount ?? Money::zero(),
                $bankAmount ?? Money::zero(),
            );
        }

        return [$paymentMode, $cashAmount, $bankAmount, $bankAccountId];
    }

    /**
     * Revenue grouped by which account it belongs to (audit section 3
     * "Sales", "service revenue"): an item with its own posting account
     * (`items.account_id`, the same field T08 gave the purchase side, per
     * `Item::account()`'s docblock) credits that account instead of the
     * default Sales Revenue account (`INI20`) - a service item ("Repair
     * Service Income", say) then shows up on its own ledger rather than
     * mixed into general merchandise sales.
     *
     * The header discount is removed from the whole document, not per line,
     * so it has to be allocated back across the lines (same largest-
     * remainder rule as C3 step 4) before grouping - otherwise the sum of
     * the per-account credits would overstate revenue by the discount and
     * the voucher would not balance. `Money::allocate()` refuses a negative
     * weight, which a negative-quantity in-bill adjustment line would be, so
     * that (rare) combination falls back to the single INI20 credit this
     * class always used before this feature existed rather than throwing.
     *
     * @param  array<int, array<string, mixed>>  $preparedLines
     * @return array<int, Money> keyed by account_id, zero entries dropped
     */
    private static function revenueByAccount(array $preparedLines, DocumentTotals $totals, int $fallbackAccountId): array
    {
        $hasDistinctAccounts = false;
        $hasNegativeLine = false;

        foreach ($preparedLines as $prepared) {
            if ($prepared['item']->account_id !== null) {
                $hasDistinctAccounts = true;
            }
        }

        $lineTotals = [];

        foreach ($totals->lines as $line) {
            $lineTotals[] = $line->lineTotal;

            if ($line->lineTotal->isNegative()) {
                $hasNegativeLine = true;
            }
        }

        if (! $hasDistinctAccounts || $hasNegativeLine) {
            $revenue = $totals->taxableAmount->plus($totals->nontaxableAmount);

            return $revenue->isZero() ? [] : [$fallbackAccountId => $revenue];
        }

        $discount = $totals->headerDiscount;
        $netPerLine = $discount->isZero() ? $lineTotals : array_map(
            static fn (Money $line, Money $share): Money => $line->minus($share),
            $lineTotals,
            $discount->allocate($lineTotals),
        );

        $byAccount = [];

        foreach ($preparedLines as $index => $prepared) {
            $accountId = $prepared['item']->account_id ?? $fallbackAccountId;
            $byAccount[$accountId] = ($byAccount[$accountId] ?? Money::zero())->plus($netPerLine[$index]);
        }

        return array_filter($byAccount, static fn (Money $amount): bool => ! $amount->isZero());
    }

    /**
     * The money side of the sale, unchanged in structure from before this
     * rewrite (the audit verified the account choices as correct) - only the
     * amounts differ, now exact Money strings rather than rounded floats.
     *
     * @param  array<int, array<string, mixed>>  $preparedLines
     * @return array<int, array{account_id: int, debit: string, credit: string, narration: string}>
     */
    private static function voucherLines(
        Customer $customer,
        DocumentTotals $totals,
        array $preparedLines,
        string $paymentMode,
        ?Money $cashAmount,
        ?Money $bankAmount,
        ?int $bankAccountId,
        ?int $tdsAccountId,
        ?Agent $agent,
        Money $commissionAmount,
    ): array {
        $zero = Money::zero()->toString();
        $voucherLines = [];

        $voucherLines[] = ['account_id' => $customer->account_id, 'debit' => $totals->total->toString(), 'credit' => $zero, 'narration' => 'Sale total'];

        $salesAccountId = Account::where('code', 'INI20')->firstOrFail()->id;

        foreach (static::revenueByAccount($preparedLines, $totals, $salesAccountId) as $accountId => $amount) {
            $voucherLines[] = ['account_id' => $accountId, 'debit' => $zero, 'credit' => $amount->toString(), 'narration' => 'Sales revenue'];
        }

        if ($totals->vatAmount->isPositive()) {
            $vatPayableId = Account::where('code', 'LIA20')->firstOrFail()->id;
            $voucherLines[] = ['account_id' => $vatPayableId, 'debit' => $zero, 'credit' => $totals->vatAmount->toString(), 'narration' => 'VAT payable'];
        }

        if ($totals->tdsAmount->isPositive()) {
            $voucherLines[] = ['account_id' => $tdsAccountId, 'debit' => $totals->tdsAmount->toString(), 'credit' => $zero, 'narration' => 'TDS withheld'];
            $voucherLines[] = ['account_id' => $customer->account_id, 'debit' => $zero, 'credit' => $totals->tdsAmount->toString(), 'narration' => 'TDS withheld'];
        }

        // Commission is a real expense the business owes the agent, not a
        // customer-side adjustment like TDS above - posted as two extra, fully
        // independent lines rather than netted against the customer's
        // settlement amount.
        if ($agent && $commissionAmount->isPositive()) {
            $commissionExpenseId = Account::where('code', 'EXE22')->firstOrFail()->id;
            $voucherLines[] = ['account_id' => $commissionExpenseId, 'debit' => $commissionAmount->toString(), 'credit' => $zero, 'narration' => 'Sales commission'];
            $voucherLines[] = ['account_id' => $agent->account_id, 'debit' => $zero, 'credit' => $commissionAmount->toString(), 'narration' => 'Commission payable to agent'];
        }

        if ($paymentMode !== 'credit') {
            $cashAccountId = Account::where('code', 'AS1')->firstOrFail()->id;
            $settlementDue = $totals->settlementDue;

            if ($paymentMode === 'cash') {
                $voucherLines[] = ['account_id' => $cashAccountId, 'debit' => $settlementDue->toString(), 'credit' => $zero, 'narration' => 'Cash received'];
            } elseif ($paymentMode === 'bank') {
                $voucherLines[] = ['account_id' => $bankAccountId, 'debit' => $settlementDue->toString(), 'credit' => $zero, 'narration' => 'Bank receipt'];
            } else {
                if ($cashAmount && $cashAmount->isPositive()) {
                    $voucherLines[] = ['account_id' => $cashAccountId, 'debit' => $cashAmount->toString(), 'credit' => $zero, 'narration' => 'Cash received'];
                }
                if ($bankAmount && $bankAmount->isPositive()) {
                    $voucherLines[] = ['account_id' => $bankAccountId, 'debit' => $bankAmount->toString(), 'credit' => $zero, 'narration' => 'Bank receipt'];
                }
            }

            $voucherLines[] = ['account_id' => $customer->account_id, 'debit' => $zero, 'credit' => $settlementDue->toString(), 'narration' => 'Settlement'];
        }

        return $voucherLines;
    }

    /**
     * Resolves a line's optional item_unit_id against the item's already-
     * loaded `units` relation, returning [item_unit_id, conversion_factor].
     * A null/missing item_unit_id resolves to [null, Quantity 1] - the item's
     * own base unit, with a conversion factor that's a pure no-op - so every
     * existing caller that never sends item_unit_id gets byte-for-byte the
     * same behavior as before this feature existed.
     *
     * @return array{0: int|null, 1: Quantity}
     */
    private static function resolveItemUnit(Item $item, mixed $itemUnitId): array
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
     * Full-invoice cancellation only (no partial-line returns in this pass).
     *
     * Per CONTRACTS C5: everything happens inside one transaction, the sale
     * row and its blockers are re-read with lockForUpdate() and re-checked
     * there (so a double click or two users cannot both pass the status check
     * - audit P0-16), and the reversal goes through JournalVoucher::reverse(),
     * which posts it in the dedicated Reversal series. That is what stops a
     * cancellation from consuming an invoice number and leaving a permanent
     * gap in the printed series (P0-15), and what refuses to cancel a bill
     * whose fiscal year has already been filed and closed.
     *
     * Stock movements this sale generated are flagged cancelled rather than
     * reversed with inverse rows, matching how every other module in this app
     * undoes a stock effect.
     */
    public function cancel(User $actor, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to cancel a sale.');
        }

        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('The cancellation reason may not be longer than 500 characters.');
        }

        DB::transaction(function () use ($actor, $reason) {
            /** @var self $sale */
            $sale = static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($sale->status === 'cancelled') {
                throw new InvalidArgumentException('This sale has already been cancelled.');
            }

            // A 'rejected' return request never posted anything real, so it
            // must not block cancellation (same reasoning as excluding
            // 'cancelled'); a 'pending' one deliberately still blocks - it
            // represents a live decision someone still has to make against
            // this exact sale.
            if (SaleReturnLine::whereIn('sale_line_id', $sale->lines()->pluck('id'))
                ->whereHas('salesReturn', fn ($query) => $query->whereNotIn('status', ['cancelled', 'rejected']))
                ->exists()) {
                throw new InvalidArgumentException('Cannot cancel a sale that has partial returns against it.');
            }

            if ($sale->receiptAllocations()->whereHas('receipt', fn ($query) => $query->where('status', '!=', 'cancelled'))->exists()) {
                throw new InvalidArgumentException('Cannot cancel a sale that has a payment received against it.');
            }

            $reversal = JournalVoucher::reverse(
                $sale->journalVoucher()->firstOrFail(),
                $actor,
                "Cancellation of sale {$sale->invoice_number}: {$reason}",
            );

            ItemStockMovement::query()
                ->where('reference_type', (new SaleLine)->getMorphClass())
                ->whereIn('reference_id', $sale->lines()->pluck('id'))
                ->update(['cancelled' => true]);

            $sale->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'reversal_journal_voucher_id' => $reversal->id,
            ]);

            $this->setRawAttributes($sale->getAttributes(), true);
        });
    }

    /**
     * Turns a calculator failure into the same InvalidArgumentException shape
     * every caller of post() already handles, while keeping the machine
     * `reason` code available for the controller's 422 mapping (C8).
     */
    public static function billingReason(InvalidArgumentException $exception): ?string
    {
        return $exception instanceof BillingException ? $exception->reason : null;
    }
}
