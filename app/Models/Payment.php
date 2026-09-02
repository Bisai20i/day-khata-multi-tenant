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
 * A payment is a plain cash/bank settlement voucher against a supplier -
 * the mirror image of Receipt. Posts `[debit supplier account (reduces the
 * payable), credit settlement account (cash/bank going out)]`. May
 * optionally allocate against one or more specific outstanding Purchases so
 * Purchase::outstandingAmount() (and therefore
 * SalesPurchaseReportController::agedPayables()) nets correctly per-invoice
 * - allocations may under-allocate (an accepted on-account remainder not
 * tied to any invoice) but never over-allocate a single purchase beyond its
 * own remaining outstanding balance, nor exceed the payment's own amount in
 * total. See day-khata-multi-tenant mem.md for the full research this was
 * built from.
 */
#[Fillable([
    'supplier_id', 'date', 'amount', 'payment_mode', 'bank_account_id',
    'reference_number', 'narration', 'status', 'journal_voucher_id',
    'created_by',
])]
class Payment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
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
     * @return BelongsTo<Account, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /**
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
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @param  array{supplier_id: int, date: string, amount: float, payment_mode: string, bank_account_id?: int|null, reference_number?: string|null, narration?: string|null, allocations?: array<int, array{purchase_id: int, amount: float}>}  $data
     */
    public static function post(array $data, User $actor): self
    {
        return DB::transaction(function () use ($data, $actor) {
            $supplier = Supplier::findOrFail($data['supplier_id']);
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0.01) {
                throw new InvalidArgumentException('The payment amount must be greater than zero.');
            }

            $paymentMode = $data['payment_mode'];

            if ($paymentMode === 'cash') {
                $settlementAccountId = Account::where('code', 'AS1')->firstOrFail()->id;
            } elseif ($paymentMode === 'bank') {
                if (empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a bank payment.');
                }
                $settlementAccountId = $data['bank_account_id'];
            } else {
                throw new InvalidArgumentException("Unknown payment mode: {$paymentMode}");
            }

            $allocations = $data['allocations'] ?? [];
            $preparedAllocations = [];
            $allocatedTotal = 0.0;

            foreach ($allocations as $allocation) {
                $purchase = Purchase::findOrFail($allocation['purchase_id']);

                if ($purchase->supplier_id !== $supplier->id) {
                    throw new InvalidArgumentException('This purchase does not belong to the selected supplier.');
                }

                if ($purchase->status === 'cancelled') {
                    throw new InvalidArgumentException('Cannot allocate a payment to a cancelled purchase.');
                }

                $allocationAmount = round((float) $allocation['amount'], 2);

                if ($allocationAmount <= 0) {
                    throw new InvalidArgumentException('Each allocation amount must be greater than zero.');
                }

                $outstanding = $purchase->outstandingAmount();

                if ($allocationAmount > $outstanding + 0.01) {
                    throw new InvalidArgumentException("Allocation of {$allocationAmount} exceeds purchase #{$purchase->id}'s outstanding balance of {$outstanding}.");
                }

                $preparedAllocations[] = ['purchase_id' => $purchase->id, 'amount' => $allocationAmount];
                $allocatedTotal = round($allocatedTotal + $allocationAmount, 2);
            }

            if ($allocatedTotal > $amount + 0.01) {
                throw new InvalidArgumentException('Allocations cannot exceed the payment amount.');
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::Payment->value,
                    'date' => $data['date'],
                    'narration' => $data['narration'] ?? "Payment to {$supplier->name}",
                ],
                [
                    ['account_id' => $supplier->account_id, 'debit' => $amount, 'credit' => 0, 'narration' => 'Settlement'],
                    ['account_id' => $settlementAccountId, 'debit' => 0, 'credit' => $amount, 'narration' => 'Amount paid'],
                ],
                $actor,
            );

            $payment = static::create([
                'supplier_id' => $supplier->id,
                'date' => $data['date'],
                'amount' => $amount,
                'payment_mode' => $paymentMode,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => 'posted',
                'journal_voucher_id' => $voucher->id,
                'created_by' => $actor->id,
            ]);

            foreach ($preparedAllocations as $allocation) {
                $payment->allocations()->create($allocation);
            }

            return $payment;
        });
    }

    /**
     * Reverses this payment: posts a brand-new voucher mirroring the
     * original (debit/credit swapped), never edits the original (voucher
     * immutability rule, matches every other module). Allocation rows are
     * left in place as a historical record - Purchase::outstandingAmount()
     * already excludes allocations whose Payment is cancelled, so
     * cancelling correctly un-nets them automatically. $reason is required,
     * matching every other cancel-with-reversal method in this app (Sale::
     * cancel(), Purchase::cancel(), SalesReturn::cancel(), PurchaseReturn::
     * cancel()) - reversing money already paid out needs an audit trail as
     * much as any of those.
     */
    public function cancel(User $actor, string $reason): void
    {
        if ($this->status === 'cancelled') {
            throw new InvalidArgumentException('This payment has already been cancelled.');
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
                    'voucher_type' => VoucherType::Payment->value,
                    'date' => now()->toDateString(),
                    'narration' => "Cancellation of payment #{$this->id}: {$reason}",
                ],
                $mirroredLines,
                $actor,
            );

            $this->update(['status' => 'cancelled']);
        });
    }
}
