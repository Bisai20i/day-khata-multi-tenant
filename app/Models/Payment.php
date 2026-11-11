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
 *
 * Branches (plan 12, sections 5.3 and 5.4, BR07): branch_id is where the
 * money went out. A payment may settle bills of other branches; the supplier
 * (payable) line is then split by the branch of each bill it settles, the
 * unallocated (on-account) remainder stays in the payment's branch, and the
 * cash or bank line sits in the payment's branch. Posted with autoBalance, so
 * Inter-Branch Clearing lines (hub = the payment's branch) balance every
 * branch on its own (I1). A payment whose bills are all in its own branch
 * (every payment of a single-branch company) posts the same two lines as
 * before.
 */
#[Fillable([
    'supplier_id', 'date', 'amount', 'payment_mode', 'bank_account_id',
    'reference_number', 'narration', 'status', 'journal_voucher_id', 'fiscal_year_id', 'payment_number',
    'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'reversal_journal_voucher_id', 'branch_id',
])]
class Payment extends Model
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
     * Branch: $data['branch_id'] when given, else the actor's posting branch
     * (DocumentBranch::forDocument()); it must be active and accessible. The
     * voucher lines follow the party-line rule in the class docblock.
     *
     * @param  array{supplier_id: int, date: string, amount: mixed, payment_mode: string, bank_account_id?: int|null, reference_number?: string|null, narration?: string|null, branch_id?: int|string|null, allocations?: array<int, array{purchase_id: int, amount: mixed}>}  $data
     *
     * Locks before any plain SELECT of the transaction (as
     * SalaryPaymentPoster::post()): on MySQL (REPEATABLE READ) the first
     * plain read fixes the snapshot, so a branch or account read taken
     * before the bill locks would make the outstanding-balance read miss a
     * payment committed while this one waited, and two payments could
     * together overpay one bill. The payload is aggregated before the
     * transaction opens (pure arithmetic, no query).
     *
     * @throws InvalidArgumentException
     */
    public static function post(array $data, User $actor): self
    {
        $byPurchase = static::allocationsByPurchase($data['allocations'] ?? []);

        return DB::transaction(function () use ($data, $actor, $byPurchase) {
            // Supplier, then the bills (ascending id), then (inside
            // JournalVoucher::post()) the fiscal year: the posting lock order
            // (Item::lockForStockOut()). Read without a lock, this payment held
            // the fiscal year and then waited on the supplier row - the
            // payments insert's foreign-key check takes a shared lock on it -
            // while a purchase for the same supplier held that row and waited
            // on the fiscal year (PUR-20).
            $supplier = Supplier::whereKey($data['supplier_id'])->lockForUpdate()->firstOrFail();
            $purchases = static::lockPurchases(array_keys($byPurchase));
            $amount = Money::of($data['amount']);

            if (! $amount->isPositive()) {
                throw new InvalidArgumentException('The payment amount must be greater than zero.');
            }

            $branchId = DocumentBranch::forDocument($actor, $data['branch_id'] ?? null)->branchId;
            $settlementAccountId = static::settlementAccountId($data);
            $allocations = static::preparedAllocations($byPurchase, $purchases, $supplier, CarbonImmutable::parse($data['date'])->toDateString(), $actor);

            $allocatedTotal = Money::sum(array_map(
                static fn (array $allocation): Money => $allocation['amount'],
                $allocations
            ));

            if ($allocatedTotal->isGreaterThan($amount)) {
                throw new InvalidArgumentException('Allocations cannot exceed the payment amount.');
            }

            $voucherLines = [];

            foreach (static::supplierDebitByBranch($amount, $allocations, $branchId) as $lineBranchId => $debit) {
                $voucherLines[] = ['account_id' => $supplier->account_id, 'debit' => $debit->toString(), 'credit' => '0', 'narration' => 'Settlement', 'branch_id' => $lineBranchId];
            }

            $voucherLines[] = ['account_id' => $settlementAccountId, 'debit' => '0', 'credit' => $amount->toString(), 'narration' => 'Amount paid', 'branch_id' => $branchId];

            try {
                $voucher = JournalVoucher::post(
                    [
                        'voucher_type' => VoucherType::Payment->value,
                        'date' => $data['date'],
                        'narration' => $data['narration'] ?? "Payment to {$supplier->name}",
                        'branch_id' => $branchId,
                    ],
                    $voucherLines,
                    $actor,
                    // Every line carries the same compact narration (item 10), so
                    // the supplier's ledger reads "PMT-7 - Cash Settlement".
                    lineNarration: fn (JournalVoucher $voucher): string => SettlementNarration::line(static::paymentNumberFor($voucher), $data['payment_mode']),
                    autoBalance: true,
                );
            } catch (BranchRuleViolation $exception) {
                // A missing Inter-Branch Clearing account is a setup problem
                // the user must see on the form, not a 500.
                throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
            }

            $payment = static::create([
                'branch_id' => $branchId,
                'supplier_id' => $supplier->id,
                'date' => $data['date'],
                'amount' => $amount,
                'payment_mode' => $data['payment_mode'],
                // Only a bank payment names a bank account; a stray id sent
                // with a cash payment would show a bank on a cash payment
                // (flags G-14).
                'bank_account_id' => $data['payment_mode'] === 'bank' ? ($data['bank_account_id'] ?? null) : null,
                'reference_number' => $data['reference_number'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => 'posted',
                'journal_voucher_id' => $voucher->id,
                'fiscal_year_id' => $voucher->fiscal_year_id,
                // Stored once, in the format payments have always shown in
                // (flags G-18).
                'payment_number' => static::paymentNumberFor($voucher),
                'created_by' => $actor->id,
            ]);

            foreach ($allocations as $allocation) {
                $payment->allocations()->create([
                    'purchase_id' => $allocation['purchase_id'],
                    'amount' => $allocation['amount'],
                ]);
            }

            return $payment;
        });
    }

    /**
     * The payment's number, from its own voucher's number (flags G-18).
     */
    private static function paymentNumberFor(JournalVoucher $voucher): string
    {
        return $voucher->printedNumber('PMT');
    }

    /**
     * Reverses this payment: posts a Reversal voucher mirroring the original
     * (voucher rows are never edited, the app-wide immutability rule) and
     * records who cancelled it, when and why. Allocation rows stay in place as
     * a historical record - Purchase::outstandingAmount() already ignores
     * allocations whose payment is cancelled, so the bills un-net themselves.
     *
     * @throws InvalidArgumentException
     * @throws AuthorizationException When the payment touched a branch the actor may not post into.
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

            // reverse() mirrors every line in its own branch, clearing lines
            // and their counterparties included, and checks the actor may
            // post into all of them. Looked up outside the access scope.
            $reversal = JournalVoucher::reverse(
                BranchContext::unrestricted(fn () => $payment->journalVoucher()->firstOrFail()),
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
            'bank' => (int) (($data['bank_account_id'] ?? null) ?: throw new InvalidArgumentException('A bank account is required for a bank payment.')),
            default => throw new InvalidArgumentException("Unknown payment mode: {$data['payment_mode']}"),
        };
    }

    /**
     * Collapses the payload to one row per purchase (pure arithmetic, run
     * before the transaction opens), ascending purchase id.
     *
     * @param  array<int, array{purchase_id: int|string, amount: mixed}>  $allocations
     * @return array<int, Money>
     */
    private static function allocationsByPurchase(array $allocations): array
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

        ksort($byPurchase);

        return $byPurchase;
    }

    /**
     * Locks the allocated bills in ascending id, right after the supplier
     * (see post()). Read outside the branch access scope (plan 12, section
     * 5.7): through a restricted actor's scope a bill of another branch
     * would look deleted and go unlocked. preparedAllocations() then checks
     * access.
     *
     * @param  list<int>  $purchaseIds
     * @return Collection<int, Purchase>
     */
    private static function lockPurchases(array $purchaseIds): Collection
    {
        if ($purchaseIds === []) {
            return new Collection;
        }

        return BranchContext::unrestricted(fn () => Purchase::whereIn('id', $purchaseIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id'));
    }

    /**
     * Checks each aggregated amount against the balance read under the bill
     * lock. Exact comparisons only: allocating one paisa more than is
     * outstanding is an error, not a rounding artefact.
     *
     * Access first (I6): a bill the actor may not use is reported exactly
     * like a missing one, before any other check, so a restricted user
     * learns nothing about a hidden bill (whether it exists, whose it is,
     * whether it is cancelled or how much it owes).
     *
     * @param  array<int, Money>  $byPurchase
     * @param  Collection<int, Purchase>  $purchases
     * @return list<array{purchase_id: int, amount: Money, branch_id: int}>
     */
    private static function preparedAllocations(array $byPurchase, Collection $purchases, Supplier $supplier, string $paymentDate, User $actor): array
    {
        $prepared = [];

        foreach ($byPurchase as $purchaseId => $amount) {
            $purchase = $purchases->get($purchaseId);

            if (! $purchase || (! BranchContext::accessIsLifted() && ! BranchContext::canAccessBranch((int) $purchase->branch_id, $actor))) {
                throw new InvalidArgumentException("Purchase #{$purchaseId} does not exist or is not available to you.");
            }

            if ($purchase->supplier_id !== $supplier->id) {
                throw new InvalidArgumentException('This purchase does not belong to the selected supplier.');
            }

            if ($purchase->status !== 'posted') {
                throw new InvalidArgumentException('Cannot allocate a payment to a cancelled purchase.');
            }

            // A payment cannot settle a bill received after it (flags G-14).
            if ($purchase->date->toDateString() > $paymentDate) {
                throw new InvalidArgumentException(
                    "Bill #{$purchase->id} is dated {$purchase->date->toDateString()}, after this payment ({$paymentDate})."
                );
            }

            $outstanding = $purchase->outstandingAmount();

            if ($amount->isGreaterThan($outstanding)) {
                throw new InvalidArgumentException(
                    "Allocation of {$amount->toString()} exceeds purchase #{$purchase->id}'s "
                    ."outstanding balance of {$outstanding->toString()}."
                );
            }

            $prepared[] = ['purchase_id' => $purchase->id, 'amount' => $amount, 'branch_id' => (int) $purchase->branch_id];
        }

        return $prepared;
    }

    /**
     * The supplier debit per branch under the party-line rule (plan 12,
     * section 5.3): each allocation in its bill's branch, the unallocated
     * remainder in the payment's branch. Ascending branch id, zero amounts
     * left out. With every bill in the payment's branch this is one entry
     * for the whole amount, the single supplier line payments always posted.
     *
     * @param  list<array{purchase_id: int, amount: Money, branch_id: int}>  $allocations  Already capped at $amount in total.
     * @return array<int, Money>
     */
    private static function supplierDebitByBranch(Money $amount, array $allocations, int $paymentBranchId): array
    {
        $byBranch = [];

        foreach ($allocations as $allocation) {
            $byBranch[$allocation['branch_id']] = ($byBranch[$allocation['branch_id']] ?? Money::zero())->plus($allocation['amount']);
        }

        $onAccount = $amount->minus(Money::sum(array_column($allocations, 'amount')));

        if ($onAccount->isPositive()) {
            $byBranch[$paymentBranchId] = ($byBranch[$paymentBranchId] ?? Money::zero())->plus($onAccount);
        }

        ksort($byBranch);

        return $byBranch;
    }
}
