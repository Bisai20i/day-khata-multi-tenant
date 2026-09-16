<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\VoucherType;
use App\Support\Money\Money;
use App\Support\SettlementNarration;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A payment is a plain cash/bank settlement voucher against a supplier - the
 * mirror image of Receipt. Posts `[debit supplier account (reduces the
 * payable), credit settlement account (cash/bank going out)]`. May optionally
 * allocate against one or more specific outstanding Purchases so
 * Purchase::outstandingAmount() (and therefore
 * SalesPurchaseReportController::agedPayables()) nets correctly per-invoice.
 *
 * Allocations may under-allocate (an accepted on-account remainder not tied to
 * any invoice) but never over-allocate a single purchase beyond its own
 * remaining outstanding balance, nor exceed the payment's own amount in total.
 * Both caps are exact now: the audit found `> outstanding + 0.01` tolerances
 * quietly accepting a one-paisa over-allocation (P0-4), and two rows naming the
 * same purchase each being checked on its own against the full outstanding
 * balance (P0-14). The rows are aggregated per purchase first, and the purchase
 * rows are locked in ascending id inside the transaction before their balances
 * are read.
 */
#[Fillable([
    'supplier_id', 'date', 'amount', 'payment_mode', 'bank_account_id',
    'reference_number', 'narration', 'status', 'journal_voucher_id',
    'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'reversal_journal_voucher_id',
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
            'cancelled_at' => 'datetime',
            'amount' => Decimal::class.':2',
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
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @param  array{supplier_id: int, date: string, amount: mixed, payment_mode: string, bank_account_id?: int|null, reference_number?: string|null, narration?: string|null, allocations?: array<int, array{purchase_id: int, amount: mixed}>}  $data
     */
    public static function post(array $data, User $actor): self
    {
        return DB::transaction(function () use ($data, $actor) {
            $supplier = Supplier::findOrFail($data['supplier_id']);
            $amount = Money::of($data['amount']);

            if (! $amount->isPositive()) {
                throw new InvalidArgumentException('The payment amount must be greater than zero.');
            }

            $settlementAccountId = static::settlementAccountId($data);
            $allocations = static::preparedAllocations($data['allocations'] ?? [], $supplier);

            $allocatedTotal = Money::sum(array_map(
                static fn (array $allocation): Money => $allocation['amount'],
                $allocations
            ));

            if ($allocatedTotal->isGreaterThan($amount)) {
                throw new InvalidArgumentException('Allocations cannot exceed the payment amount.');
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::Payment->value,
                    'date' => $data['date'],
                    'narration' => $data['narration'] ?? "Payment to {$supplier->name}",
                ],
                [
                    ['account_id' => $supplier->account_id, 'debit' => $amount->toString(), 'credit' => '0', 'narration' => 'Settlement'],
                    ['account_id' => $settlementAccountId, 'debit' => '0', 'credit' => $amount->toString(), 'narration' => 'Amount paid'],
                ],
                $actor,
            );

            $payment = static::create([
                'supplier_id' => $supplier->id,
                'date' => $data['date'],
                'amount' => $amount,
                'payment_mode' => $data['payment_mode'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => 'posted',
                'journal_voucher_id' => $voucher->id,
                'created_by' => $actor->id,
            ]);

            foreach ($allocations as $allocation) {
                $payment->allocations()->create([
                    'purchase_id' => $allocation['purchase_id'],
                    'amount' => $allocation['amount'],
                ]);
            }

            // Every line of this voucher carries the same compact narration
            // (item 10), so the supplier's ledger reads "PMT-7 - Cash
            // Settlement" instead of a bare "Settlement"/"Amount paid".
            $documentNumber = "PMT-{$voucher->voucher_number}";
            $voucher->lines()->update([
                'narration' => SettlementNarration::line($documentNumber, $data['payment_mode']),
            ]);

            return $payment;
        });
    }

    /**
     * Reverses this payment: posts a Reversal voucher mirroring the original
     * (voucher rows are never edited, the app-wide immutability rule) and
     * records who cancelled it, when and why. Allocation rows stay in place as
     * a historical record - Purchase::outstandingAmount() already ignores
     * allocations whose payment is cancelled, so the bills un-net themselves.
     */
    public function cancel(User $actor, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to cancel a payment.');
        }

        DB::transaction(function () use ($actor, $reason) {
            /** @var self $payment */
            $payment = static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($payment->status === 'cancelled') {
                throw new InvalidArgumentException('This payment has already been cancelled.');
            }

            $reversal = JournalVoucher::reverse(
                $payment->journalVoucher()->firstOrFail(),
                $actor,
                "Cancellation of payment #{$payment->id}: {$reason}",
            );

            $payment->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'reversal_journal_voucher_id' => $reversal->id,
            ]);

            $this->setRawAttributes($payment->getAttributes(), true);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function settlementAccountId(array $data): int
    {
        return match ($data['payment_mode']) {
            'cash' => Account::where('code', 'AS1')->firstOrFail()->id,
            'bank' => (int) ($data['bank_account_id'] ?: throw new InvalidArgumentException('A bank account is required for a bank payment.')),
            default => throw new InvalidArgumentException("Unknown payment mode: {$data['payment_mode']}"),
        };
    }

    /**
     * Collapses the payload to one row per purchase, locks those purchases in
     * ascending id and checks each aggregated amount against the balance read
     * under that lock. Exact comparisons only: allocating one paisa more than
     * is outstanding is an error, not a rounding artefact.
     *
     * @param  array<int, array{purchase_id: int, amount: mixed}>  $allocations
     * @return list<array{purchase_id: int, amount: Money}>
     */
    private static function preparedAllocations(array $allocations, Supplier $supplier): array
    {
        /** @var array<int, Money> $byPurchase */
        $byPurchase = [];

        foreach ($allocations as $allocation) {
            $purchaseId = (int) $allocation['purchase_id'];
            $amount = Money::of($allocation['amount']);

            if (! $amount->isPositive()) {
                throw new InvalidArgumentException('Each allocation amount must be greater than zero.');
            }

            $byPurchase[$purchaseId] = ($byPurchase[$purchaseId] ?? Money::zero())->plus($amount);
        }

        if ($byPurchase === []) {
            return [];
        }

        ksort($byPurchase);

        $purchases = Purchase::whereIn('id', array_keys($byPurchase))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $prepared = [];

        foreach ($byPurchase as $purchaseId => $amount) {
            $purchase = $purchases->get($purchaseId);

            if (! $purchase) {
                throw new InvalidArgumentException("Purchase #{$purchaseId} no longer exists.");
            }

            if ($purchase->supplier_id !== $supplier->id) {
                throw new InvalidArgumentException('This purchase does not belong to the selected supplier.');
            }

            if ($purchase->status !== 'posted') {
                throw new InvalidArgumentException('Cannot allocate a payment to a cancelled purchase.');
            }

            $outstanding = $purchase->outstandingAmount();

            if ($amount->isGreaterThan($outstanding)) {
                throw new InvalidArgumentException(
                    "Allocation of {$amount->toString()} exceeds purchase #{$purchase->id}'s "
                    ."outstanding balance of {$outstanding->toString()}."
                );
            }

            $prepared[] = ['purchase_id' => $purchase->id, 'amount' => $amount];
        }

        return $prepared;
    }
}
