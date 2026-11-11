<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\VoucherType;
use App\Models\Concerns\BelongsToBranch;
use App\Support\Branches\BranchContext;
use App\Support\Branches\BranchRuleViolation;
use App\Support\Branches\DocumentBranch;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Money\Money;
use App\Support\SettlementNarration;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
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
 *
 * Branches (plan 12, sections 5.3 and 5.4, BR07): branch_id is where the
 * money came in. An allocation may settle an invoice of another branch; the
 * voucher then follows the party-line rule: the cash or bank line sits in the
 * receipt's branch, the customer line is split by the branch of each invoice
 * it settles, and the unallocated (on-account) remainder stays in the
 * receipt's branch, because it settles no document yet. The voucher is posted
 * with autoBalance, so Inter-Branch Clearing lines (hub = the receipt's
 * branch) make every branch balance on its own (I1). A receipt whose
 * allocations all sit in its own branch (every receipt of a single-branch
 * company) posts exactly the two lines it always did.
 */
#[Fillable([
    'customer_id', 'date', 'amount', 'payment_mode', 'bank_account_id',
    'reference_number', 'narration', 'status', 'journal_voucher_id',
    'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    'reversal_journal_voucher_id', 'branch_id',
])]
class Receipt extends Model
{
    use BelongsToBranch;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'amount' => Decimal::class.':2',
            'cancelled_at' => 'datetime',
            'branch_id' => 'integer',
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
     * Branch: $data['branch_id'] when given, else the actor's posting branch
     * (DocumentBranch::forDocument()); it must be active and accessible. Each
     * allocated invoice may be in any branch the actor may use; the voucher
     * lines follow the party-line rule in the class docblock.
     *
     * @param  array{customer_id: int, date: string, amount: string|float, payment_mode: string, bank_account_id?: int|null, reference_number?: string|null, narration?: string|null, branch_id?: int|string|null, allocations?: array<int, array{sale_id: int, amount: string|float}>}  $data
     *
     * Lock order: the allocated sales (ascending id), then (inside
     * JournalVoucher::post()) the fiscal year, with the sale locks taken
     * before the first plain SELECT of the transaction. On MySQL (REPEATABLE
     * READ) the first plain read fixes the snapshot: a customer, branch or
     * account read taken before the locks would make the outstanding-balance
     * read miss a receipt committed while this one waited, and two receipts
     * could together over-settle one invoice. The payload is aggregated
     * before the transaction opens (pure arithmetic, no query). Same
     * discipline as SalaryPaymentPoster::post().
     *
     * @throws InvalidArgumentException
     */
    public static function post(array $data, User $actor): self
    {
        $bySale = static::allocationsBySale($data['allocations'] ?? []);

        return DB::transaction(function () use ($data, $actor, $bySale) {
            $sales = static::lockSales(array_keys($bySale));
            $customer = Customer::findOrFail($data['customer_id']);
            $amount = Money::of($data['amount']);

            if (! $amount->isPositive()) {
                throw new InvalidArgumentException('Receipt amount must be greater than zero.');
            }

            $branchId = DocumentBranch::forDocument($actor, $data['branch_id'] ?? null)->branchId;

            ClosedFiscalYearGuard::assertDateInOpenYear($data['date'], $actor);

            $paymentMode = $data['payment_mode'];

            if ($paymentMode === 'cash') {
                $settlementAccountId = Account::where('code', 'AS1')->firstOrFail()->id;
            } elseif ($paymentMode === 'bank') {
                if (empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a bank receipt.');
                }
                $settlementAccountId = (int) $data['bank_account_id'];

                // Only a cash/bank account may be debited as the "bank", and
                // never the customer's own ledger account (SAL-02).
                if ($settlementAccountId === (int) $customer->account_id
                    || ! SalesReturn::refundAccountQuery()->whereKey($settlementAccountId)->exists()) {
                    throw new InvalidArgumentException('A receipt can only be banked into a cash or bank account.');
                }
            } else {
                throw new InvalidArgumentException("Unknown payment mode: {$paymentMode}");
            }

            $preparedAllocations = static::prepareAllocations($bySale, $sales, $customer, CarbonImmutable::parse($data['date'])->toDateString(), $actor);
            $allocatedTotal = Money::sum(array_column($preparedAllocations, 'amount'));

            if ($allocatedTotal->isGreaterThan($amount)) {
                throw new InvalidArgumentException('Allocations cannot exceed the receipt amount.');
            }

            $voucherLines = [
                ['account_id' => $settlementAccountId, 'debit' => $amount->toString(), 'credit' => '0', 'narration' => 'Amount received', 'branch_id' => $branchId],
            ];

            foreach (static::customerCreditByBranch($amount, $preparedAllocations, $branchId) as $lineBranchId => $credit) {
                $voucherLines[] = ['account_id' => $customer->account_id, 'debit' => '0', 'credit' => $credit->toString(), 'narration' => 'Settlement', 'branch_id' => $lineBranchId];
            }

            try {
                $voucher = JournalVoucher::post(
                    [
                        'voucher_type' => VoucherType::Receipt->value,
                        'date' => $data['date'],
                        'narration' => $data['narration'] ?? "Receipt from {$customer->name}",
                        'branch_id' => $branchId,
                    ],
                    $voucherLines,
                    $actor,
                    // Every line of this voucher carries the same compact
                    // narration (audit section 3 "Sales", "ledger narrations"), so
                    // the customer's ledger reads "{voucher number} - Cash
                    // Settlement" and tells a receipt apart from a sale at a
                    // glance. Resolved inside post(), once the number exists.
                    lineNarration: fn (JournalVoucher $voucher): string => SettlementNarration::line($voucher->seriesNumber(), $paymentMode),
                    autoBalance: true,
                );
            } catch (BranchRuleViolation $exception) {
                // A missing Inter-Branch Clearing account is a setup problem
                // the user must see on the form, not a 500.
                throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
            }

            $receipt = static::create([
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'date' => $data['date'],
                'amount' => $amount,
                'payment_mode' => $paymentMode,
                // Only a bank receipt names a bank account (flags G-14).
                'bank_account_id' => $data['payment_mode'] === 'bank' ? ($data['bank_account_id'] ?? null) : null,
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
     * Sums the payload per sale (pure arithmetic, run before the transaction
     * opens), ascending sale id, so repeating an invoice cannot slip past
     * its cap.
     *
     * @param  array<int, array{sale_id: int|string, amount: string|float}>  $allocations
     * @return array<int, Money>
     */
    private static function allocationsBySale(array $allocations): array
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

        ksort($bySale);

        return $bySale;
    }

    /**
     * Locks the allocated sales in ascending id order; the first query of
     * post()'s transaction (see the lock order there). Read outside the
     * branch access scope (plan 12, section 5.7): through a restricted
     * actor's scope an invoice of another branch would look deleted, and
     * its row would go unlocked. prepareAllocations() then checks access.
     *
     * @param  list<int>  $saleIds
     * @return Collection<int, Sale>
     */
    private static function lockSales(array $saleIds): Collection
    {
        if ($saleIds === []) {
            return new Collection;
        }

        return BranchContext::unrestricted(fn () => Sale::whereIn('id', $saleIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id'));
    }

    /**
     * Checks each aggregated amount against the locked invoice's outstanding
     * balance, read inside the same transaction.
     *
     * Access first (I6): a sale the actor may not use is reported exactly
     * like a missing one, before any other check, so a restricted user
     * learns nothing about a hidden invoice (whether it exists, whose it is,
     * whether it is cancelled or how much it owes).
     *
     * @param  array<int, Money>  $bySale
     * @param  Collection<int, Sale>  $sales
     * @return list<array{sale_id: int, amount: Money, branch_id: int}>
     */
    private static function prepareAllocations(array $bySale, Collection $sales, Customer $customer, string $receiptDate, User $actor): array
    {
        $prepared = [];

        foreach ($bySale as $saleId => $amount) {
            $sale = $sales->get($saleId);

            if (! $sale || (! BranchContext::accessIsLifted() && ! BranchContext::canAccessBranch((int) $sale->branch_id, $actor))) {
                throw new InvalidArgumentException("Sale [{$saleId}] does not exist or is not available to you.");
            }

            if ($sale->customer_id !== $customer->id) {
                throw new InvalidArgumentException('This sale does not belong to the selected customer.');
            }

            if ($sale->status === 'cancelled') {
                throw new InvalidArgumentException('Cannot allocate a receipt to a cancelled sale.');
            }

            // Money cannot settle an invoice that did not exist yet (flags
            // G-14): a receipt dated before the sale would age the customer's
            // balance wrongly and put the settlement in an earlier period.
            if ($sale->date->toDateString() > $receiptDate) {
                throw new InvalidArgumentException(
                    "Invoice {$sale->invoice_number} is dated {$sale->date->toDateString()}, after this receipt ({$receiptDate})."
                );
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

            $prepared[] = ['sale_id' => $sale->id, 'amount' => $amount, 'branch_id' => (int) $sale->branch_id];
        }

        return $prepared;
    }

    /**
     * The customer credit per branch under the party-line rule (plan 12,
     * section 5.3): each allocation in its invoice's branch, the unallocated
     * remainder in the receipt's branch. Ascending branch id, zero amounts
     * left out (a ledger line must move something). With every allocation in
     * the receipt's branch this is one entry for the whole amount, the single
     * customer line receipts have always posted.
     *
     * @param  list<array{sale_id: int, amount: Money, branch_id: int}>  $allocations  Already capped at $amount in total.
     * @return array<int, Money>
     */
    private static function customerCreditByBranch(Money $amount, array $allocations, int $receiptBranchId): array
    {
        $byBranch = [];

        foreach ($allocations as $allocation) {
            $byBranch[$allocation['branch_id']] = ($byBranch[$allocation['branch_id']] ?? Money::zero())->plus($allocation['amount']);
        }

        $onAccount = $amount->minus(Money::sum(array_column($allocations, 'amount')));

        if ($onAccount->isPositive()) {
            $byBranch[$receiptBranchId] = ($byBranch[$receiptBranchId] ?? Money::zero())->plus($onAccount);
        }

        ksort($byBranch);

        return $byBranch;
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
     *
     * @throws InvalidArgumentException
     * @throws AuthorizationException When the receipt touched a branch the actor may not post into.
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

            // reverse() mirrors every line in its own branch, clearing lines
            // and their counterparties included, so each branch the receipt
            // touched nets back to zero; it checks the actor may post into
            // all of them. The voucher is looked up outside the access scope.
            $reversal = JournalVoucher::reverse(
                BranchContext::unrestricted(fn () => $receipt->journalVoucher()->firstOrFail()),
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
