<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\VoucherType;
use App\Support\Money\Money;
use App\Support\SettlementNarration;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One payment made later against a credit or partial capital/service
 * purchase (audit CS-01). Posts Dr supplier account, Cr cash (AS1) or bank
 * through JournalVoucher::post(), capped at the bill's outstanding balance
 * computed under a row lock on the purchase. Cancelling reverses the voucher
 * and frees the amount again.
 */
#[Fillable([
    'capital_purchase_id', 'supplier_id', 'date', 'amount', 'payment_mode',
    'bank_account_id', 'reference_number', 'narration', 'status',
    'journal_voucher_id', 'created_by',
    'cancelled_at', 'cancelled_by', 'cancel_reason', 'reversal_journal_voucher_id',
])]
class CapitalPurchaseSettlement extends Model
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
     * @return BelongsTo<CapitalPurchase, $this>
     */
    public function capitalPurchase(): BelongsTo
    {
        return $this->belongsTo(CapitalPurchase::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
     * @return BelongsTo<Account, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /**
     * @param  array{date: string, amount: mixed, payment_mode: string, bank_account_id?: int|null, reference_number?: string|null, narration?: string|null}  $data
     */
    public static function settle(CapitalPurchase $capitalPurchase, array $data, User $actor): self
    {
        return DB::transaction(function () use ($capitalPurchase, $data, $actor) {
            /** @var CapitalPurchase $locked */
            $locked = CapitalPurchase::query()->whereKey($capitalPurchase->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'posted') {
                throw new InvalidArgumentException('Only a posted capital purchase can be settled.');
            }

            $supplier = $locked->supplier_id ? Supplier::findOrFail($locked->supplier_id) : null;
            if (! $supplier) {
                throw new InvalidArgumentException('This capital purchase has no supplier, so there is nothing to settle.');
            }

            $amount = Money::of($data['amount'] ?? 0);
            if (! $amount->isPositive()) {
                throw new InvalidArgumentException('The settlement amount must be greater than zero.');
            }

            $outstanding = $locked->outstandingAmount();
            if ($amount->isGreaterThan($outstanding)) {
                throw new InvalidArgumentException("The settlement cannot exceed the outstanding balance of {$outstanding->toString()}.");
            }

            $mode = $data['payment_mode'] ?? null;
            if ($mode === 'cash') {
                $settlementAccountId = Account::where('code', 'AS1')->firstOrFail()->id;
            } elseif ($mode === 'bank') {
                if (empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a bank settlement.');
                }
                CapitalPurchase::assertPaymentAccount((int) $data['bank_account_id']);
                $settlementAccountId = (int) $data['bank_account_id'];
            } else {
                throw new InvalidArgumentException('Settlement mode must be cash or bank.');
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::Payment->value,
                    'date' => $data['date'],
                    'narration' => $data['narration'] ?? "Settlement of capital purchase #{$locked->id} to {$supplier->name}",
                ],
                [
                    ['account_id' => $supplier->account_id, 'debit' => $amount->toString(), 'credit' => '0.00'],
                    ['account_id' => $settlementAccountId, 'debit' => '0.00', 'credit' => $amount->toString()],
                ],
                $actor,
            );

            $voucher->lines()->update([
                'narration' => SettlementNarration::line("CPS-{$voucher->voucher_number}", $mode),
            ]);

            return static::create([
                'capital_purchase_id' => $locked->id,
                'supplier_id' => $supplier->id,
                'date' => $data['date'],
                'amount' => $amount->toString(),
                'payment_mode' => $mode,
                'bank_account_id' => $mode === 'bank' ? $settlementAccountId : null,
                'reference_number' => $data['reference_number'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => 'posted',
                'journal_voucher_id' => $voucher->id,
                'created_by' => $actor->id,
            ]);
        });
    }

    public function cancel(User $actor, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to cancel a settlement.');
        }

        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('The cancellation reason cannot be longer than 500 characters.');
        }

        DB::transaction(function () use ($actor, $reason): void {
            // Lock the parent purchase first (same order as settle()) so a
            // cancel and a new settlement cannot interleave.
            CapitalPurchase::query()->whereKey($this->capital_purchase_id)->lockForUpdate()->firstOrFail();

            /** @var self $settlement */
            $settlement = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($settlement->status === 'cancelled') {
                throw new InvalidArgumentException('This settlement has already been cancelled.');
            }

            $reversal = JournalVoucher::reverse(
                $settlement->journalVoucher()->firstOrFail(),
                $actor,
                "Cancellation of capital purchase settlement #{$settlement->id}: {$reason}",
            );

            $settlement->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'reversal_journal_voucher_id' => $reversal->id,
            ]);

            $this->setRawAttributes($settlement->getAttributes(), true);
        });
    }
}
