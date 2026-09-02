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
 * A plain 2-line cash/bank settlement voucher against a customer - closes
 * the real, previously-documented MVP gap where a credit sale settled via a
 * generic Journal Voucher kept aging forever in Aged Receivables. Never a
 * `credit`/`partial` payment_mode (unlike Sale/Purchase) - a receipt IS the
 * settlement.
 *
 * Optionally allocated against one or more specific outstanding sales (see
 * ReceiptAllocation) so Sale::outstandingAmount() nets correctly per
 * invoice. Allocations may sum to LESS than the receipt's own amount - the
 * unapplied remainder is an accepted "on-account" payment, not tied to any
 * invoice (same honestly-documented MVP-gap shape as this app's other
 * deliberate limitations, e.g. SalesReturn not reversing header discount).
 */
#[Fillable([
    'customer_id', 'date', 'amount', 'payment_mode', 'bank_account_id',
    'reference_number', 'narration', 'status', 'journal_voucher_id',
    'created_by',
])]
class Receipt extends Model
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
     * @return HasMany<ReceiptAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class);
    }

    /**
     * Resolves the settlement account (cash `AS1` or `bank_account_id`,
     * same branching as FixedAsset::post()), validates every allocation
     * against Sale::outstandingAmount() (allowing a 0.01 rounding
     * tolerance, matching Sale::post()'s own partial-payment check), posts
     * one balanced `[debit settlement, credit customer]` JournalVoucher,
     * then creates the Receipt + ReceiptAllocation rows.
     *
     * @param  array{customer_id: int, date: string, amount: float, payment_mode: string, bank_account_id?: int|null, reference_number?: string|null, narration?: string|null, allocations?: array<int, array{sale_id: int, amount: float}>}  $data
     */
    public static function post(array $data, User $actor): self
    {
        return DB::transaction(function () use ($data, $actor) {
            $customer = Customer::findOrFail($data['customer_id']);
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0.01) {
                throw new InvalidArgumentException('Receipt amount must be greater than zero.');
            }

            $paymentMode = $data['payment_mode'];

            if ($paymentMode === 'cash') {
                $settlementAccountId = Account::where('code', 'AS1')->firstOrFail()->id;
            } elseif ($paymentMode === 'bank') {
                if (empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a bank receipt.');
                }
                $settlementAccountId = $data['bank_account_id'];
            } else {
                throw new InvalidArgumentException("Unknown payment mode: {$paymentMode}");
            }

            $allocations = $data['allocations'] ?? [];
            $preparedAllocations = [];
            $allocatedTotal = 0.0;

            foreach ($allocations as $allocation) {
                $sale = Sale::findOrFail($allocation['sale_id']);

                if ($sale->customer_id !== $customer->id) {
                    throw new InvalidArgumentException('This sale does not belong to the selected customer.');
                }

                if ($sale->status === 'cancelled') {
                    throw new InvalidArgumentException('Cannot allocate a receipt to a cancelled sale.');
                }

                $allocationAmount = round((float) $allocation['amount'], 2);

                if ($allocationAmount <= 0) {
                    throw new InvalidArgumentException('Allocation amount must be greater than zero.');
                }

                $outstanding = $sale->outstandingAmount();

                if ($allocationAmount > $outstanding + 0.01) {
                    throw new InvalidArgumentException("Allocation of {$allocationAmount} exceeds sale #{$sale->id}'s outstanding balance of {$outstanding}.");
                }

                $preparedAllocations[] = ['sale_id' => $sale->id, 'amount' => $allocationAmount];
                $allocatedTotal = round($allocatedTotal + $allocationAmount, 2);
            }

            if ($allocatedTotal > $amount + 0.01) {
                throw new InvalidArgumentException('Allocations cannot exceed the receipt amount.');
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::Receipt->value,
                    'date' => $data['date'],
                    'narration' => $data['narration'] ?? "Receipt from {$customer->name}",
                ],
                [
                    ['account_id' => $settlementAccountId, 'debit' => $amount, 'credit' => 0, 'narration' => 'Amount received'],
                    ['account_id' => $customer->account_id, 'debit' => 0, 'credit' => $amount, 'narration' => 'Settlement'],
                ],
                $actor,
            );

            $receipt = static::create([
                'customer_id' => $customer->id,
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
                $receipt->allocations()->create($allocation);
            }

            return $receipt;
        });
    }

    /**
     * Reverses this receipt's voucher (debit/credit swapped, mirroring
     * SalesReturn::cancel()'s pattern) as one new voucher - the original is
     * never edited. Allocation rows are left as historical record;
     * Sale::outstandingAmount() already excludes allocations whose receipt
     * is cancelled, so the netting un-does itself automatically.
     *
     * $reason is required, matching every other cancel-with-reversal method
     * in this app (Sale::cancel(), Purchase::cancel(), SalesReturn::
     * cancel(), PurchaseReturn::cancel()) - reversing money already
     * collected needs an audit trail as much as any of those.
     */
    public function cancel(User $actor, string $reason): void
    {
        if ($this->status === 'cancelled') {
            throw new InvalidArgumentException('This receipt has already been cancelled.');
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
                    'voucher_type' => VoucherType::Receipt->value,
                    'date' => now()->toDateString(),
                    'narration' => "Cancellation of receipt #{$this->id}: {$reason}",
                ],
                $mirroredLines,
                $actor,
            );

            $this->update(['status' => 'cancelled']);
        });
    }
}
