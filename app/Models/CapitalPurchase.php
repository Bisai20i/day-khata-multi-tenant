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
 * A lightweight, non-inventory ledger-posting purchase: the user picks one
 * or more existing ledger accounts directly (no items), types an amount per
 * line, optionally adds a VAT amount, and settles via cash/bank/partial/
 * credit. "capital" vs "service" is purely a narration/type label -
 * mechanically identical, with no depreciation tracking and no
 * auto-created asset account (unlike the Fixed Asset module, which this is
 * deliberately not part of).
 *
 * supplier_id is only required for the credit and partial payment modes,
 * which route the transaction's liability through the supplier's ledger
 * account (mirroring Purchase::post()'s full-liability-then-settle shape).
 * A cash or bank payment needs no supplier at all - the settlement account
 * is credited directly for the full total, since there is no liability
 * left outstanding to book.
 */
#[Fillable([
    'supplier_id', 'store_id', 'journal_voucher_id', 'type', 'date', 'narration',
    'payment_mode', 'bank_account_id', 'cash_amount', 'bank_amount', 'vat_amount',
    'total', 'status', 'created_by',
])]
class CapitalPurchase extends Model
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
     * @return HasMany<CapitalPurchaseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CapitalPurchaseLine::class);
    }

    /**
     * Builds and posts the capital purchase's JournalVoucher, then creates
     * the CapitalPurchase + CapitalPurchaseLine rows.
     *
     * @param  array{supplier_id?: int|null, type: string, date: string, narration?: string|null, payment_mode: string, bank_account_id?: int|null, cash_amount?: float|null, bank_amount?: float|null, vat_amount?: float, store_id?: int}  $data
     * @param  array<int, array{account_id: int, amount: float, narration?: string}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            if (count($lines) < 1) {
                throw new InvalidArgumentException('A capital purchase needs at least one line.');
            }

            $type = $data['type'] ?? null;
            if (! in_array($type, ['capital', 'service'], true)) {
                throw new InvalidArgumentException('Type must be either "capital" or "service".');
            }

            $storeId = isset($data['store_id']) ? (int) $data['store_id'] : Store::where('is_active', true)->orderBy('id')->value('id');
            if (! $storeId) {
                throw new InvalidArgumentException('No active store is configured.');
            }

            $supplierId = $data['supplier_id'] ?? null;
            $supplier = $supplierId ? Supplier::findOrFail($supplierId) : null;

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
                    'debit' => $line['amount'],
                    'credit' => 0,
                    'narration' => $line['narration'],
                ];
            }

            if ($vatAmount > 0) {
                $asa23 = Account::where('code', 'ASA23')->firstOrFail();
                $voucherLines[] = ['account_id' => $asa23->id, 'debit' => $vatAmount, 'credit' => 0, 'narration' => 'Input VAT'];
            }

            $paymentMode = $data['payment_mode'];
            $cashAmount = null;
            $bankAmount = null;

            if ($paymentMode === 'credit') {
                if (! $supplier) {
                    throw new InvalidArgumentException('A supplier is required for a credit payment.');
                }

                $voucherLines[] = ['account_id' => $supplier->account_id, 'debit' => 0, 'credit' => $total];
            } elseif ($paymentMode === 'partial') {
                if (! $supplier) {
                    throw new InvalidArgumentException('A supplier is required for a partial payment.');
                }

                $cashAmount = round((float) ($data['cash_amount'] ?? 0), 2);
                $bankAmount = round((float) ($data['bank_amount'] ?? 0), 2);

                if ($bankAmount > 0 && empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a partial payment with a bank portion.');
                }

                if (abs(($cashAmount + $bankAmount) - $total) > 0.01) {
                    throw new InvalidArgumentException('Cash and bank amounts must add up to the amount due.');
                }

                $voucherLines[] = ['account_id' => $supplier->account_id, 'debit' => 0, 'credit' => $total];

                $settlementLines = [];
                if ($cashAmount > 0) {
                    $cashAccount = Account::where('code', 'AS1')->firstOrFail();
                    $settlementLines[] = ['account_id' => $cashAccount->id, 'debit' => 0, 'credit' => $cashAmount];
                }
                if ($bankAmount > 0) {
                    $settlementLines[] = ['account_id' => $data['bank_account_id'], 'debit' => 0, 'credit' => $bankAmount];
                }

                if ($settlementLines) {
                    $settledTotal = round((float) array_sum(array_column($settlementLines, 'credit')), 2);
                    $voucherLines = [...$voucherLines, ...$settlementLines, ['account_id' => $supplier->account_id, 'debit' => $settledTotal, 'credit' => 0]];
                }
            } elseif ($paymentMode === 'cash') {
                if ($total > 0) {
                    $cashAccount = Account::where('code', 'AS1')->firstOrFail();
                    $voucherLines[] = ['account_id' => $cashAccount->id, 'debit' => 0, 'credit' => $total];
                }
            } elseif ($paymentMode === 'bank') {
                if (empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a bank payment.');
                }
                if ($total > 0) {
                    $voucherLines[] = ['account_id' => $data['bank_account_id'], 'debit' => 0, 'credit' => $total];
                }
            } else {
                throw new InvalidArgumentException("Unknown payment mode: {$paymentMode}");
            }

            $typeLabel = $type === 'capital' ? 'Capital purchase' : 'Capital service purchase';

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::CapitalPurchase->value,
                    'date' => $data['date'],
                    'narration' => $data['narration'] ?? ($supplier ? "{$typeLabel} from {$supplier->name}" : $typeLabel),
                ],
                $voucherLines,
                $actor,
            );

            $capitalPurchase = static::create([
                'supplier_id' => $supplier?->id,
                'store_id' => $storeId,
                'journal_voucher_id' => $voucher->id,
                'type' => $type,
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
                $capitalPurchase->lines()->create($line);
            }

            return $capitalPurchase;
        });
    }

    /**
     * Cancels this capital purchase: posts a NEW voucher that exactly
     * mirrors the original voucher's lines (every debit becomes a credit
     * and vice versa - JournalVoucher rows are immutable everywhere in
     * this app, so this is a real reversal, not a flag flip).
     */
    public function cancel(User $actor, string $reason): void
    {
        if ($this->status === 'cancelled') {
            throw new InvalidArgumentException('This capital purchase has already been cancelled.');
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
                    'voucher_type' => VoucherType::CapitalPurchase->value,
                    'date' => now()->toDateString(),
                    'narration' => "Cancellation of capital purchase #{$this->id}: {$reason}",
                ],
                $mirroredLines,
                $actor,
            );

            $this->update(['status' => 'cancelled']);
        });
    }
}
