<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\VoucherType;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Money\Money;
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
 * deliberate limitations).
 *
 * Every amount here is an exact `Money` and every comparison is exact. The
 * old code allowed an allocation to exceed an invoice's outstanding balance
 * by up to 0.01 and a Re 0.01 receipt to be rejected as "not greater than
 * zero" (audit P0-4); a paisa either belongs on the invoice or it does not.
 */
#[Fillable([
    'customer_id', 'date', 'amount', 'payment_mode', 'bank_account_id',
    'reference_number', 'narration', 'status', 'journal_voucher_id',
    'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    'reversal_journal_voucher_id',
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
            'amount' => Decimal::class.':2',
            'cancelled_at' => 'datetime',
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
     * @return HasMany<ReceiptAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class);
    }

    /**
     * Resolves the settlement account (cash `AS1` or `bank_account_id`,
     * same branching as FixedAsset::post()), validates every allocation
     * against Sale::outstandingAmount(), posts one balanced
     * `[debit settlement, credit customer]` JournalVoucher, then creates the
     * Receipt + ReceiptAllocation rows.
     *
     * Allocation rules, all of them exact (CONTRACTS C5/C7, audit P0-4 and
     * P0-14):
     * - rows are summed per sale before any cap is checked, so repeating the
     *   same invoice twice in one payload cannot slip past its outstanding
     *   balance,
     * - the sale rows are locked in ascending id order inside the
     *   transaction and their outstanding balances re-read there, so two
     *   simultaneous receipts cannot both settle the same invoice,
     * - an invoice with nothing outstanding (a cash sale, or one already
     *   settled) cannot be allocated against at all.
     *
     * @param  array{customer_id: int, date: string, amount: string|float, payment_mode: string, bank_account_id?: int|null, reference_number?: string|null, narration?: string|null, allocations?: array<int, array{sale_id: int, amount: string|float}>}  $data
     */
    public static function post(array $data, User $actor): self
    {
        return DB::transaction(function () use ($data, $actor) {
            $customer = Customer::findOrFail($data['customer_id']);
            $amount = Money::of($data['amount']);

            if (! $amount->isPositive()) {
                throw new InvalidArgumentException('Receipt amount must be greater than zero.');
            }

            ClosedFiscalYearGuard::assertDateInOpenYear($data['date'], $actor);

            $paymentMode = $data['payment_mode'];

            if ($paymentMode === 'cash') {
                $settlementAccountId = Account::where('code', 'AS1')->firstOrFail()->id;
            } elseif ($paymentMode === 'bank') {
                if (empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a bank receipt.');
                }
                $settlementAccountId = (int) $data['bank_account_id'];
            } else {
                throw new InvalidArgumentException("Unknown payment mode: {$paymentMode}");
            }

            $preparedAllocations = static::prepareAllocations($data['allocations'] ?? [], $customer);
            $allocatedTotal = Money::sum(array_column($preparedAllocations, 'amount'));

            if ($allocatedTotal->isGreaterThan($amount)) {
                throw new InvalidArgumentException('Allocations cannot exceed the receipt amount.');
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::Receipt->value,
                    'date' => $data['date'],
                    'narration' => $data['narration'] ?? "Receipt from {$customer->name}",
                ],
                [
                    ['account_id' => $settlementAccountId, 'debit' => $amount->toString(), 'credit' => '0', 'narration' => 'Amount received'],
                    ['account_id' => $customer->account_id, 'debit' => '0', 'credit' => $amount->toString(), 'narration' => 'Settlement'],
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
                $receipt->allocations()->create([
                    'sale_id' => $allocation['sale_id'],
                    'amount' => $allocation['amount'],
                ]);
            }

            return $receipt;
        });
    }

    /**
     * Sums the payload per sale, locks those sale rows in ascending id
     * order, and checks each aggregated amount against that invoice's
     * outstanding balance read inside the same transaction.
     *
     * @param  array<int, array{sale_id: int, amount: string|float}>  $allocations
     * @return list<array{sale_id: int, amount: Money}>
     */
    private static function prepareAllocations(array $allocations, Customer $customer): array
    {
        $bySale = [];

        foreach ($allocations as $allocation) {
            $saleId = (int) $allocation['sale_id'];
            $amount = Money::of($allocation['amount']);

            if (! $amount->isPositive()) {
                throw new InvalidArgumentException('Allocation amount must be greater than zero.');
            }

            $bySale[$saleId] = isset($bySale[$saleId]) ? $bySale[$saleId]->plus($amount) : $amount;
        }

        if ($bySale === []) {
            return [];
        }

        ksort($bySale);

        $sales = Sale::whereIn('id', array_keys($bySale))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $prepared = [];

        foreach ($bySale as $saleId => $amount) {
            $sale = $sales->get($saleId);

            if (! $sale) {
                throw new InvalidArgumentException("Sale [{$saleId}] no longer exists.");
            }

            if ($sale->customer_id !== $customer->id) {
                throw new InvalidArgumentException('This sale does not belong to the selected customer.');
            }

            if ($sale->status === 'cancelled') {
                throw new InvalidArgumentException('Cannot allocate a receipt to a cancelled sale.');
            }

            $outstanding = Money::of($sale->outstandingAmount());

            if (! $outstanding->isPositive()) {
                throw new InvalidArgumentException("Sale #{$sale->id} has nothing outstanding to allocate against.");
            }

            if ($amount->isGreaterThan($outstanding)) {
                throw new InvalidArgumentException(
                    "Allocation of {$amount->toString()} exceeds sale #{$sale->id}'s outstanding balance of {$outstanding->toString()}."
                );
            }

            $prepared[] = ['sale_id' => $sale->id, 'amount' => $amount];
        }

        return $prepared;
    }

    /**
     * Reverses this receipt's voucher through JournalVoucher::reverse(),
     * which posts the mirrored lines under VoucherType::Reversal - its own
     * gapless series, so a cancellation never consumes a receipt number
     * (audit P0-15). The original voucher is never edited. Allocation rows
     * are left as a historical record; Sale::outstandingAmount() already
     * excludes allocations whose receipt is cancelled, so the netting
     * un-does itself automatically.
     *
     * The status is re-read under a row lock inside the transaction, so two
     * clicks (or two users) cannot both post a reversal (audit P0-16).
     */
    public function cancel(User $actor, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to cancel a receipt.');
        }

        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('The reason may not be longer than 500 characters.');
        }

        DB::transaction(function () use ($actor, $reason) {
            /** @var self $receipt */
            $receipt = static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($receipt->status === 'cancelled') {
                throw new InvalidArgumentException('This receipt has already been cancelled.');
            }

            $reversal = JournalVoucher::reverse(
                $receipt->journalVoucher()->firstOrFail(),
                $actor,
                "Cancellation of receipt #{$receipt->id}: {$reason}",
            );

            $receipt->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'reversal_journal_voucher_id' => $reversal->id,
            ]);

            $this->setRawAttributes($receipt->getAttributes(), true);
        });
    }
}
