<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\VoucherType;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchRuleViolation;
use App\Support\Branches\DocumentBranch;
use App\Support\Money\Money;
use App\Support\SettlementNarration;
use Illuminate\Auth\Access\AuthorizationException;
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
 *
 * Branches (plan 12, section 5.3 party-line rule, BR07): branch_id is where
 * the money went out (default: the actor's posting branch). The supplier
 * line is tagged with the bill's branch and the cash or bank line with the
 * settlement's; when they differ the voucher is posted with autoBalance and
 * Inter-Branch Clearing lines (hub = the settlement's branch) balance each
 * branch (I1). Settling a bill of one's own branch posts the same two lines
 * as before.
 */
#[Fillable([
    'capital_purchase_id', 'supplier_id', 'date', 'amount', 'payment_mode',
    'bank_account_id', 'reference_number', 'narration', 'status',
    'journal_voucher_id', 'created_by',
    'cancelled_at', 'cancelled_by', 'cancel_reason', 'reversal_journal_voucher_id', 'branch_id',
])]
class CapitalPurchaseSettlement extends Model
{
    use BelongsToBranch;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'cancelled_at' => 'datetime',
            'amount' => Decimal::class.':2',
            'branch_id' => 'integer',
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
     * Branch: $data['branch_id'] when given, else the actor's posting branch
     * (DocumentBranch::forDocument()); it must be active and accessible, and
     * so must the bill's branch (I6).
     *
     * @param  array{date: string, amount: mixed, payment_mode: string, bank_account_id?: int|null, reference_number?: string|null, narration?: string|null, branch_id?: int|string|null}  $data
     *
     * @throws InvalidArgumentException
     */
    public static function settle(CapitalPurchase $capitalPurchase, array $data, User $actor): self
    {
        return DB::transaction(function () use ($capitalPurchase, $data, $actor) {
            // Supplier, then the bill, then (inside JournalVoucher::post())
            // the fiscal year: the posting lock order (Item::lockForStockOut()).
            // The settlements insert's foreign-key check would otherwise take
            // the supplier row only after the fiscal year, against a purchase
            // that holds it and waits on the fiscal year (flags PUR-20). A
            // capital purchase's supplier_id is written once at posting, so
            // reading it before the bill's own lock is safe.
            $supplier = $capitalPurchase->supplier_id
                ? Supplier::whereKey($capitalPurchase->supplier_id)->lockForUpdate()->first()
                : null;

            // Re-read outside the branch access scope, like every lock that
            // decides a balance (plan 12, section 5.7); access to the bill's
            // branch is checked explicitly below.
            /** @var CapitalPurchase $locked */
            $locked = BranchContext::unrestricted(fn () => CapitalPurchase::query()->whereKey($capitalPurchase->getKey())->lockForUpdate()->firstOrFail());

            if ($locked->status !== 'posted') {
                throw new InvalidArgumentException('Only a posted capital purchase can be settled.');
            }

            if (! $supplier) {
                throw new InvalidArgumentException('This capital purchase has no supplier, so there is nothing to settle.');
            }

            $billBranchId = (int) $locked->branch_id;

            if (! BranchContext::accessIsLifted() && ! BranchContext::canAccessBranch($billBranchId, $actor)) {
                throw new InvalidArgumentException('This capital purchase is in a branch that is inactive or that you do not have access to.');
            }

            $branchId = DocumentBranch::forDocument($actor, $data['branch_id'] ?? null)->branchId;

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

            try {
                $voucher = JournalVoucher::post(
                    [
                        'voucher_type' => VoucherType::Payment->value,
                        'date' => $data['date'],
                        'narration' => $data['narration'] ?? "Settlement of capital purchase #{$locked->id} to {$supplier->name}",
                        'branch_id' => $branchId,
                    ],
                    [
                        ['account_id' => $supplier->account_id, 'debit' => $amount->toString(), 'credit' => '0.00', 'branch_id' => $billBranchId],
                        ['account_id' => $settlementAccountId, 'debit' => '0.00', 'credit' => $amount->toString(), 'branch_id' => $branchId],
                    ],
                    $actor,
                    lineNarration: fn (JournalVoucher $voucher): string => SettlementNarration::line($voucher->printedNumber('CPS'), $mode),
                    autoBalance: true,
                );
            } catch (BranchRuleViolation $exception) {
                // A missing Inter-Branch Clearing account is a setup problem
                // the user must see on the form, not a 500.
                throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
            }

            return static::create([
                'branch_id' => $branchId,
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

    /**
     * Reverses the settlement voucher in its branches (reverse() mirrors
     * every line's branch and counterparty) and frees the amount on the bill.
     *
     * @throws InvalidArgumentException
     * @throws AuthorizationException When the settlement touched a branch the actor may not post into.
     */
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
            BranchContext::unrestricted(fn () => CapitalPurchase::query()->whereKey($this->capital_purchase_id)->lockForUpdate()->firstOrFail());

            /** @var self $settlement */
            $settlement = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($settlement->status === 'cancelled') {
                throw new InvalidArgumentException('This settlement has already been cancelled.');
            }

            $reversal = JournalVoucher::reverse(
                BranchContext::unrestricted(fn () => $settlement->journalVoucher()->firstOrFail()),
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
