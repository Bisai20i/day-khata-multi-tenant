<?php

namespace App\Models;

use App\Enums\VoucherType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A lightweight, non-inventory ledger-posting sale: the user picks one or
 * more existing ledger accounts directly (no items), types an amount per
 * line, optionally adds a VAT amount, and settles via cash/bank/partial/
 * credit. Unlike the purchase side there is no capital/service split - just
 * "Capital Sale".
 *
 * customer_id is only required for the credit and partial payment modes,
 * which route the transaction's receivable through the customer's ledger
 * account (mirroring Sale::post()'s settlement shape). A cash or bank
 * payment needs no customer at all - the settlement account is debited
 * directly for the full total, since there is no receivable left
 * outstanding to book.
 */
#[Fillable([
    'customer_id', 'store_id', 'journal_voucher_id', 'date', 'narration',
    'payment_mode', 'bank_account_id', 'cash_amount', 'bank_amount', 'vat_amount',
    'total', 'status', 'created_by',
])]
class CapitalSale extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cash_amount' => 'decimal:2',
            'bank_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
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
     * @return HasMany<CapitalSaleLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CapitalSaleLine::class);
    }

    /**
     * Builds and posts the capital sale's JournalVoucher, then creates the
     * CapitalSale + CapitalSaleLine rows.
     *
     * @param  array{customer_id?: int|null, date: string, narration?: string|null, payment_mode: string, bank_account_id?: int|null, cash_amount?: float|null, bank_amount?: float|null, vat_amount?: float, store_id?: int}  $data
     * @param  array<int, array{account_id: int, amount: float, narration?: string}>  $lines
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

            $subtotal = 0.0;
            $preparedLines = [];

            foreach ($lines as $line) {
                $amount = round((float) $line['amount'], 2);
                if ($amount <= 0) {
                    throw new InvalidArgumentException('Each line amount must be greater than zero.');
                }

                $preparedLines[] = [
                    'account_id' => $line['account_id'],
                    'narration' => $line['narration'] ?? null,
                    'amount' => $amount,
                ];

                $subtotal = round($subtotal + $amount, 2);
            }

            $vatAmount = round((float) ($data['vat_amount'] ?? 0), 2);
            $total = round($subtotal + $vatAmount, 2);

            $voucherLines = [];
            foreach ($preparedLines as $line) {
                $voucherLines[] = [
                    'account_id' => $line['account_id'],
                    'debit' => 0,
                    'credit' => $line['amount'],
                    'narration' => $line['narration'],
                ];
            }

            if ($vatAmount > 0) {
                $lia20 = Account::where('code', 'LIA20')->firstOrFail();
                $voucherLines[] = ['account_id' => $lia20->id, 'debit' => 0, 'credit' => $vatAmount, 'narration' => 'Output VAT'];
            }

            $paymentMode = $data['payment_mode'];
            $cashAmount = null;
            $bankAmount = null;

            if ($paymentMode === 'credit') {
                if (! $customer) {
                    throw new InvalidArgumentException('A customer is required for a credit payment.');
                }

                $voucherLines[] = ['account_id' => $customer->account_id, 'debit' => $total, 'credit' => 0];
            } elseif ($paymentMode === 'partial') {
                if (! $customer) {
                    throw new InvalidArgumentException('A customer is required for a partial payment.');
                }

                $cashAmount = round((float) ($data['cash_amount'] ?? 0), 2);
                $bankAmount = round((float) ($data['bank_amount'] ?? 0), 2);

                if ($bankAmount > 0 && empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a partial payment with a bank portion.');
                }

                if (abs(($cashAmount + $bankAmount) - $total) > 0.01) {
                    throw new InvalidArgumentException('Cash and bank amounts must add up to the amount due.');
                }

                $voucherLines[] = ['account_id' => $customer->account_id, 'debit' => $total, 'credit' => 0];

                $settlementLines = [];
                if ($cashAmount > 0) {
                    $cashAccount = Account::where('code', 'AS1')->firstOrFail();
                    $settlementLines[] = ['account_id' => $cashAccount->id, 'debit' => $cashAmount, 'credit' => 0];
                }
                if ($bankAmount > 0) {
                    $settlementLines[] = ['account_id' => $data['bank_account_id'], 'debit' => $bankAmount, 'credit' => 0];
                }

                if ($settlementLines) {
                    $settledTotal = round((float) array_sum(array_column($settlementLines, 'debit')), 2);
                    $voucherLines = [...$voucherLines, ...$settlementLines, ['account_id' => $customer->account_id, 'debit' => 0, 'credit' => $settledTotal]];
                }
            } elseif ($paymentMode === 'cash') {
                if ($total > 0) {
                    $cashAccount = Account::where('code', 'AS1')->firstOrFail();
                    $voucherLines[] = ['account_id' => $cashAccount->id, 'debit' => $total, 'credit' => 0];
                }
            } elseif ($paymentMode === 'bank') {
                if (empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a bank payment.');
                }
                if ($total > 0) {
                    $voucherLines[] = ['account_id' => $data['bank_account_id'], 'debit' => $total, 'credit' => 0];
                }
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
                'store_id' => $storeId,
                'journal_voucher_id' => $voucher->id,
                'date' => $data['date'],
                'narration' => $data['narration'] ?? null,
                'payment_mode' => $paymentMode,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'cash_amount' => $paymentMode === 'partial' ? $cashAmount : null,
                'bank_amount' => $paymentMode === 'partial' ? $bankAmount : null,
                'vat_amount' => $vatAmount,
                'total' => $total,
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
     * Cancels this capital sale: posts a NEW voucher that exactly mirrors
     * the original voucher's lines (every debit becomes a credit and vice
     * versa - JournalVoucher rows are immutable everywhere in this app, so
     * this is a real reversal, not a flag flip).
     */
    public function cancel(User $actor, string $reason): void
    {
        if ($this->status === 'cancelled') {
            throw new InvalidArgumentException('This capital sale has already been cancelled.');
        }

        DB::transaction(function () use ($actor, $reason) {
            $original = $this->journalVoucher()->with('lines')->firstOrFail();

            $mirroredLines = $original->lines->map(fn (JournalVoucherLine $line) => [
                'account_id' => $line->account_id,
                'debit' => (float) $line->credit,
                'credit' => (float) $line->debit,
                'narration' => $line->narration,
            ])->all();

            JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::CapitalSale->value,
                    'date' => now()->toDateString(),
                    'narration' => "Cancellation of capital sale #{$this->id}: {$reason}",
                ],
                $mirroredLines,
                $actor,
            );

            $this->update(['status' => 'cancelled']);
        });
    }
}
