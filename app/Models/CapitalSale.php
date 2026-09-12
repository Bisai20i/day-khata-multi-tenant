<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\VoucherType;
use App\Support\Billing\BillingException;
use App\Support\Billing\DocumentCalculator;
use App\Support\Billing\DocumentTotals;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A lightweight, non-inventory ledger-posting sale: the user picks one or
 * more existing ledger accounts directly (no items), marks each line vatable
 * or exempt, types an amount per line, and settles via cash/bank/partial/
 * credit. Unlike the purchase side there is no capital/service split - just
 * "Capital Sale".
 *
 * It is nonetheless a real tax invoice: it credits Output VAT (LIA20) and
 * belongs in the Sales VAT book, so it carries the same taxable /
 * non-taxable / rate breakdown, the same stored invoice number and the same
 * buyer snapshot a Sale carries. VAT used to be a number the user typed with
 * nothing tying it to the lines (audit P0-20); it is now computed by
 * DocumentCalculator from the vatable lines and the rate, exactly like a sale.
 *
 * customer_id is only required for the credit and partial payment modes,
 * which route the transaction's receivable through the customer's ledger
 * account (mirroring Sale::post()'s settlement shape). A cash or bank
 * payment needs no customer at all - the settlement account is debited
 * directly for the full total, since there is no receivable left
 * outstanding to book.
 */
#[Fillable([
    'customer_id', 'buyer_name', 'buyer_pan', 'buyer_address', 'store_id',
    'journal_voucher_id', 'fiscal_year_id', 'invoice_number', 'date', 'narration',
    'payment_mode', 'bank_account_id', 'cash_amount', 'bank_amount',
    'taxable_amount', 'nontaxable_amount', 'vat_rate', 'vat_amount',
    'total', 'status', 'created_by',
    'cancelled_at', 'cancelled_by', 'cancel_reason', 'reversal_journal_voucher_id',
])]
class CapitalSale extends Model
{
    /** The invoice prefix used when the tenant has not configured one. */
    public const DEFAULT_INVOICE_PREFIX = 'CS';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cancelled_at' => 'datetime',
            'cash_amount' => Decimal::class.':2',
            'bank_amount' => Decimal::class.':2',
            'taxable_amount' => Decimal::class.':2',
            'nontaxable_amount' => Decimal::class.':2',
            'vat_rate' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'total' => Decimal::class.':2',
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
     * @return HasMany<CapitalSaleLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CapitalSaleLine::class);
    }

    /**
     * The series prefix for capital sale invoice numbers.
     *
     * Read from the company settings when a `capital_sale_prefix` column
     * exists (T03 owns that settings screen), otherwise the built-in default.
     * Resolving it through getAttribute() rather than the property keeps this
     * working whether or not the setting has been added yet.
     */
    public static function invoicePrefix(): string
    {
        $configured = CompanySetting::current()->getAttribute('capital_sale_prefix');

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_INVOICE_PREFIX;
    }

    /**
     * The number printed on this invoice. Always the stored one; the fallback
     * only covers rows posted before the column existed and never re-derives a
     * number from the current settings.
     */
    public function documentNumber(): string
    {
        return $this->invoice_number ?: self::invoicePrefix()."-{$this->id}";
    }

    /**
     * Builds and posts the capital sale's JournalVoucher, then creates the
     * CapitalSale + CapitalSaleLine rows.
     *
     * Every figure comes from DocumentCalculator, which is why each line is
     * handed over as quantity 1 at a rate of the line amount: a capital sale
     * carries no items and no units, so quantity and conversion factor are
     * both fixed at 1 and the line's gross is the amount itself, exactly.
     *
     * @param  array{customer_id?: int|null, date: string, narration?: string|null, payment_mode: string, bank_account_id?: int|null, cash_amount?: mixed, bank_amount?: mixed, vat_rate?: mixed, expected_total?: mixed, store_id?: int}  $data
     * @param  array<int, array{account_id: int, amount: mixed, narration?: string|null, vatable?: bool}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            if (count($lines) < 1) {
                throw new InvalidArgumentException('A capital sale needs at least one line.');
            }

            $storeId = isset($data['store_id']) ? (int) $data['store_id'] : Store::where('is_active', true)->orderBy('id')->value('id');
            if (! $storeId) {
                throw new InvalidArgumentException('No active store is configured.');
            }

            $customerId = $data['customer_id'] ?? null;
            $customer = $customerId ? Customer::findOrFail($customerId) : null;

            $totals = static::calculateTotals($data, $lines);
            $total = $totals->total;

            $voucherLines = [];
            $preparedLines = [];

            foreach (array_values($lines) as $index => $line) {
                $lineTotal = $totals->lines[$index]->lineTotal;

                $preparedLines[] = [
                    'account_id' => $line['account_id'],
                    'narration' => $line['narration'] ?? null,
                    'amount' => $lineTotal->toString(),
                    'vatable' => $totals->lines[$index]->vatable,
                ];

                $voucherLines[] = [
                    'account_id' => $line['account_id'],
                    'debit' => '0.00',
                    'credit' => $lineTotal->toString(),
                    'narration' => $line['narration'] ?? null,
                ];
            }

            if ($totals->vatAmount->isPositive()) {
                $lia20 = Account::where('code', 'LIA20')->firstOrFail();
                $voucherLines[] = [
                    'account_id' => $lia20->id,
                    'debit' => '0.00',
                    'credit' => $totals->vatAmount->toString(),
                    'narration' => 'Output VAT',
                ];
            }

            $paymentMode = $data['payment_mode'];
            $cashAmount = null;
            $bankAmount = null;

            if ($paymentMode === 'credit') {
                if (! $customer) {
                    throw new InvalidArgumentException('A customer is required for a credit payment.');
                }

                $voucherLines[] = ['account_id' => $customer->account_id, 'debit' => $total->toString(), 'credit' => '0.00'];
            } elseif ($paymentMode === 'partial') {
                if (! $customer) {
                    throw new InvalidArgumentException('A customer is required for a partial payment.');
                }

                $cashAmount = Money::of($data['cash_amount'] ?? 0);
                $bankAmount = Money::of($data['bank_amount'] ?? 0);

                if ($bankAmount->isPositive() && empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a partial payment with a bank portion.');
                }

                // Exact to the paisa. The old `abs(diff) > 0.01` guard accepted
                // a one-paisa mismatch and left it on the customer's ledger
                // forever (audit P0-4).
                DocumentCalculator::assertExactSplit($total, $cashAmount, $bankAmount);

                $voucherLines[] = ['account_id' => $customer->account_id, 'debit' => $total->toString(), 'credit' => '0.00'];

                $settlementLines = [];
                if ($cashAmount->isPositive()) {
                    $cashAccount = Account::where('code', 'AS1')->firstOrFail();
                    $settlementLines[] = ['account_id' => $cashAccount->id, 'debit' => $cashAmount->toString(), 'credit' => '0.00'];
                }
                if ($bankAmount->isPositive()) {
                    $settlementLines[] = ['account_id' => $data['bank_account_id'], 'debit' => $bankAmount->toString(), 'credit' => '0.00'];
                }

                if ($settlementLines) {
                    $voucherLines = [
                        ...$voucherLines,
                        ...$settlementLines,
                        ['account_id' => $customer->account_id, 'debit' => '0.00', 'credit' => $total->toString()],
                    ];
                }

            } elseif ($paymentMode === 'cash') {
                $cashAccount = Account::where('code', 'AS1')->firstOrFail();
                $voucherLines[] = ['account_id' => $cashAccount->id, 'debit' => $total->toString(), 'credit' => '0.00'];
            } elseif ($paymentMode === 'bank') {
                if (empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a bank payment.');
                }

                $voucherLines[] = ['account_id' => $data['bank_account_id'], 'debit' => $total->toString(), 'credit' => '0.00'];
            } else {
                throw new InvalidArgumentException("Unknown payment mode: {$paymentMode}");
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::CapitalSale->value,
                    'date' => $data['date'],
                    'narration' => $data['narration'] ?? ($customer ? "Capital sale to {$customer->name}" : 'Capital sale'),
                ],
                $voucherLines,
                $actor,
            );

            $capitalSale = static::create([
                'customer_id' => $customer?->id,
                // Snapshotted at posting: a customer who later changes their
                // name or PAN must not change an invoice already issued.
                'buyer_name' => $customer?->name,
                'buyer_pan' => $customer?->tpin,
                'buyer_address' => $customer?->address,
                'store_id' => $storeId,
                'journal_voucher_id' => $voucher->id,
                'fiscal_year_id' => $voucher->fiscal_year_id,
                'invoice_number' => static::invoicePrefix()."-{$voucher->voucher_number}",
                'date' => $data['date'],
                'narration' => $data['narration'] ?? null,
                'payment_mode' => $paymentMode,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'cash_amount' => $paymentMode === 'partial' ? $cashAmount?->toString() : null,
                'bank_amount' => $paymentMode === 'partial' ? $bankAmount?->toString() : null,
                'taxable_amount' => $totals->taxableAmount->toString(),
                'nontaxable_amount' => $totals->nontaxableAmount->toString(),
                'vat_rate' => $totals->vatRate,
                'vat_amount' => $totals->vatAmount->toString(),
                'total' => $total->toString(),
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            foreach ($preparedLines as $line) {
                $capitalSale->lines()->create($line);
            }

            return $capitalSale;
        });
    }

    /**
     * Runs the document through DocumentCalculator.
     *
     * Exposed so the controller can validate a payload (and honour the
     * browser's `expected_total`) with the identical calculation the posting
     * path uses.
     *
     * @param  array{vat_rate?: mixed, expected_total?: mixed}  $data
     * @param  array<int, array{amount: mixed, vatable?: bool}>  $lines
     *
     * @throws BillingException
     */
    public static function calculateTotals(array $data, array $lines): DocumentTotals
    {
        return DocumentCalculator::calculate(
            array_map(static fn (array $line): array => [
                'quantity' => '1',
                'rate' => $line['amount'],
                'discount' => '0',
                'discount_type' => 'flat',
                'vatable' => (bool) ($line['vatable'] ?? false),
                'conversion_factor' => '1',
            ], array_values($lines)),
            [
                'vat_rate' => $data['vat_rate'] ?? CompanySetting::current()->default_vat_rate ?? '13.00',
                'discount' => '0',
                'discount_type' => 'flat',
                'expected_total' => $data['expected_total'] ?? null,
            ],
        );
    }

    /**
     * Cancels this capital sale (contract C5).
     *
     * The row is re-read with lockForUpdate() and re-checked inside the
     * transaction, so two simultaneous cancels cannot both post a reversal
     * (audit P0-16). The mirroring itself is JournalVoucher::reverse()'s job:
     * it posts the reversal in the dedicated Reversal series (so no invoice
     * number is ever consumed by a cancellation), dated today, and refuses
     * outright once the document's fiscal year has been closed.
     */
    public function cancel(User $actor, string $reason): void
    {
        DB::transaction(function () use ($actor, $reason): void {
            /** @var self $capitalSale */
            $capitalSale = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($capitalSale->status === 'cancelled') {
                throw new InvalidArgumentException('This capital sale has already been cancelled.');
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw new InvalidArgumentException('A reason is required to cancel a capital sale.');
            }

            if (mb_strlen($reason) > 500) {
                throw new InvalidArgumentException('The cancellation reason cannot be longer than 500 characters.');
            }

            $reversal = JournalVoucher::reverse(
                $capitalSale->journalVoucher()->firstOrFail(),
                $actor,
                "Cancellation of capital sale {$capitalSale->documentNumber()}: {$reason}",
            );

            $capitalSale->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'reversal_journal_voucher_id' => $reversal->id,
            ]);

            $this->setRawAttributes($capitalSale->getAttributes(), true);
        });
    }
}
