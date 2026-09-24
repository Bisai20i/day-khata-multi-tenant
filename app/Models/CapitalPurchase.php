<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\DepreciationMethod;
use App\Enums\DepreciationPool;
use App\Enums\VoucherType;
use App\Support\Billing\BillingException;
use App\Support\Billing\DocumentCalculator;
use App\Support\Billing\DocumentTotals;
use App\Support\Money\Money;
use App\Support\SettlementNarration;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A lightweight, non-inventory ledger-posting purchase: the user picks one
 * or more existing ledger accounts directly (no items), marks each line
 * vatable or exempt, types an amount per line, and settles via cash/bank/
 * partial/credit. "capital" vs "service" is purely a narration/type label -
 * mechanically identical, with no depreciation tracking and no
 * auto-created asset account (unlike the Fixed Asset module, which this is
 * deliberately not part of).
 *
 * It still claims input VAT on ASA23, so it belongs in the Purchase VAT book
 * and carries what that book needs: the supplier's bill number and PAN, and a
 * computed taxable / non-taxable / rate breakdown instead of the free-typed
 * VAT amount the audit found (P0-20). The bill number is protected against
 * being entered twice for the same supplier while the purchase is live.
 *
 * supplier_id is only required for the credit and partial payment modes,
 * which route the transaction's liability through the supplier's ledger
 * account (mirroring Purchase::post()'s full-liability-then-settle shape).
 * A cash or bank payment needs no supplier at all - the settlement account
 * is credited directly for the full total, since there is no liability
 * left outstanding to book.
 */
#[Fillable([
    'supplier_id', 'supplier_pan', 'store_id', 'journal_voucher_id', 'type',
    'bill_number', 'bill_number_guard', 'date', 'narration',
    'payment_mode', 'bank_account_id', 'cash_amount', 'bank_amount',
    'taxable_amount', 'nontaxable_amount', 'vat_rate', 'vat_amount',
    'total', 'status', 'created_by',
    'cancelled_at', 'cancelled_by', 'cancel_reason', 'reversal_journal_voucher_id',
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
            'cancelled_at' => 'datetime',
            'cash_amount' => Decimal::class.':2',
            'bank_amount' => Decimal::class.':2',
            'taxable_amount' => Decimal::class.':2',
            'nontaxable_amount' => Decimal::class.':2',
            'vat_rate' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'total' => Decimal::class.':2',
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
     * @return HasMany<CapitalPurchaseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CapitalPurchaseLine::class);
    }

    /**
     * @return HasMany<CapitalPurchaseSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(CapitalPurchaseSettlement::class);
    }

    /**
     * What was still owed to the supplier the moment the bill was posted:
     * the whole total for a credit bill, the unpaid part for a partial one,
     * nothing for cash or bank.
     */
    public function initialUnpaidAmount(): Money
    {
        return match ($this->payment_mode) {
            'credit' => Money::of($this->total),
            'partial' => Money::of($this->total)
                ->minus(Money::of($this->cash_amount ?? 0))
                ->minus(Money::of($this->bank_amount ?? 0)),
            default => Money::zero(),
        };
    }

    /**
     * Sum of live (not cancelled) later settlements.
     */
    public function settledAmount(): Money
    {
        return Money::sum(
            $this->settlements()->where('status', 'posted')->pluck('amount')
                ->map(static fn ($amount): Money => Money::of($amount))
        );
    }

    /**
     * Unpaid balance still owed on this bill. Zero for a cancelled bill.
     * Callers that then write must hold a lock on this row (see
     * CapitalPurchaseSettlement::settle()).
     */
    public function outstandingAmount(): Money
    {
        if ($this->status === 'cancelled') {
            return Money::zero();
        }

        return Money::max(Money::zero(), $this->initialUnpaidAmount()->minus($this->settledAmount()));
    }

    /**
     * Accounts that may hold the payment (cash or bank): never a Profit and
     * Loss account, a party ledger, a supplier/customer control account or
     * Input VAT (audit CS-03).
     *
     * @throws InvalidArgumentException
     */
    public static function assertPaymentAccount(int $accountId): void
    {
        $account = Account::with(['group.accountHead', 'subgroup.accountGroup.accountHead'])->find($accountId);

        if ($account === null) {
            throw new InvalidArgumentException('The selected payment account does not exist.');
        }

        if ($account->isProfitAndLoss() || static::isReservedAccount($account)) {
            throw new InvalidArgumentException("\"{$account->name}\" cannot be used as a cash or bank payment account.");
        }
    }

    /**
     * Expense/asset line accounts must not be Input VAT, a party ledger or
     * the account the payment is drawn from (audit CS-03).
     *
     * @throws InvalidArgumentException
     */
    public static function assertLineAccount(int $accountId, ?int $paymentAccountId = null): void
    {
        $account = Account::with(['group', 'subgroup'])->find($accountId);

        if ($account === null) {
            throw new InvalidArgumentException('A line account does not exist.');
        }

        if (static::isReservedAccount($account) || $account->code === 'AS1' || ($paymentAccountId !== null && $paymentAccountId === $account->id)) {
            throw new InvalidArgumentException("\"{$account->name}\" cannot be used as an expense or asset account on a capital purchase.");
        }
    }

    private static function isReservedAccount(Account $account): bool
    {
        if ($account->code === 'ASA23') {
            return true;
        }

        if (in_array($account->subgroup?->name, ['Sundry Debtors', 'Sundry Creditors', 'Sales Agents'], true)) {
            return true;
        }

        return Supplier::where('account_id', $account->id)->exists()
            || Customer::where('account_id', $account->id)->exists();
    }

    /**
     * The value written into the unique `bill_number_guard` column while a
     * purchase is live, or null when there is nothing to protect.
     *
     * See the migration for why the duplicate rule is enforced by a nullable
     * unique column rather than a partial index: SQLite supports
     * `unique ... where status <> 'cancelled'` and MySQL does not, but both
     * ignore NULLs in an ordinary unique index, so clearing the guard on
     * cancellation gives the same rule portably.
     */
    public static function billNumberGuard(?int $supplierId, ?string $billNumber): ?string
    {
        $billNumber = $billNumber === null ? null : trim($billNumber);

        if ($supplierId === null || $billNumber === null || $billNumber === '') {
            return null;
        }

        return $supplierId.'|'.$billNumber;
    }

    /**
     * Builds and posts the capital purchase's JournalVoucher, then creates
     * the CapitalPurchase + CapitalPurchaseLine rows.
     *
     * Every figure comes from DocumentCalculator, which is why each line is
     * handed over as quantity 1 at a rate of the line amount: a capital
     * purchase carries no items and no units, so quantity and conversion
     * factor are both fixed at 1 and the line's gross is the amount itself.
     *
     * @param  array{supplier_id?: int|null, type: string, bill_number?: string|null, date: string, narration?: string|null, payment_mode: string, bank_account_id?: int|null, cash_amount?: mixed, bank_amount?: mixed, vat_rate?: mixed, expected_total?: mixed, store_id?: int}  $data
     * @param  array<int, array{account_id: int, amount: mixed, narration?: string|null, vatable?: bool}>  $lines
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

            $paymentAccountId = ! empty($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
            if ($paymentAccountId !== null) {
                static::assertPaymentAccount($paymentAccountId);
            }
            foreach ($lines as $line) {
                static::assertLineAccount((int) $line['account_id'], $paymentAccountId);
            }

            $billNumber = isset($data['bill_number']) ? trim((string) $data['bill_number']) : '';
            $billNumber = $billNumber === '' ? null : $billNumber;
            $guard = static::billNumberGuard($supplier?->id, $billNumber);

            // The unique index is what actually prevents the duplicate under a
            // race; this lookup exists to give the user a sentence instead of a
            // constraint violation. It is locked and read inside the same
            // transaction so it cannot be stale by the time the row is written.
            if ($guard !== null) {
                $existing = static::query()->where('bill_number_guard', $guard)->lockForUpdate()->first();

                if ($existing !== null) {
                    throw new InvalidArgumentException(
                        "Bill number {$billNumber} has already been entered for {$supplier->name} (capital purchase #{$existing->id})."
                    );
                }
            }

            $totals = static::calculateTotals($data, $lines);
            $total = $totals->total;

            $voucherLines = [];
            $preparedLines = [];

            foreach (array_values($lines) as $index => $line) {
                $lineTotal = $totals->lines[$index]->lineTotal;

                $preparedLines[] = [
                    'account_id' => $line['account_id'],
                    'narration' => $line['narration'] ?? null,
                    'amount' => $lineTotal->toString(),
                    'vatable' => $totals->lines[$index]->vatable,
                    // Capital purchase asset register (item 5): a "capital"
                    // line may optionally create its own FixedAsset row,
                    // reusing THIS purchase's own account and journal
                    // voucher rather than posting a second, separate one.
                    'create_asset' => $type === 'capital' && (bool) ($line['create_asset'] ?? false),
                    'asset_name' => $line['asset_name'] ?? null,
                    'depreciation_category' => $line['depreciation_category'] ?? null,
                    'depreciation_method' => $line['depreciation_method'] ?? null,
                    'depreciation_rate' => $line['depreciation_rate'] ?? null,
                    'salvage_value' => $line['salvage_value'] ?? null,
                ];

                $voucherLines[] = [
                    'account_id' => $line['account_id'],
                    'debit' => $lineTotal->toString(),
                    'credit' => '0.00',
                    'narration' => $line['narration'] ?? null,
                ];
            }

            if ($totals->vatAmount->isPositive()) {
                $asa23 = Account::where('code', 'ASA23')->firstOrFail();
                $voucherLines[] = [
                    'account_id' => $asa23->id,
                    'debit' => $totals->vatAmount->toString(),
                    'credit' => '0.00',
                    'narration' => 'Input VAT',
                ];
            }

            $paymentMode = $data['payment_mode'];
            $cashAmount = null;
            $bankAmount = null;

            if ($paymentMode === 'credit') {
                if (! $supplier) {
                    throw new InvalidArgumentException('A supplier is required for a credit payment.');
                }

                $voucherLines[] = ['account_id' => $supplier->account_id, 'debit' => '0.00', 'credit' => $total->toString()];
            } elseif ($paymentMode === 'partial') {
                if (! $supplier) {
                    throw new InvalidArgumentException('A supplier is required for a partial payment.');
                }

                $cashAmount = Money::of($data['cash_amount'] ?? 0);
                $bankAmount = Money::of($data['bank_amount'] ?? 0);

                if ($bankAmount->isPositive() && empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a partial payment with a bank portion.');
                }

                // Exact to the paisa. The old `abs(diff) > 0.01` guard accepted
                // a one-paisa mismatch and left it on the supplier's ledger
                // forever (audit P0-4).
                DocumentCalculator::assertExactSplit($total, $cashAmount, $bankAmount);

                $voucherLines[] = ['account_id' => $supplier->account_id, 'debit' => '0.00', 'credit' => $total->toString()];

                $settlementLines = [];
                if ($cashAmount->isPositive()) {
                    $cashAccount = Account::where('code', 'AS1')->firstOrFail();
                    $settlementLines[] = ['account_id' => $cashAccount->id, 'debit' => '0.00', 'credit' => $cashAmount->toString()];
                }
                if ($bankAmount->isPositive()) {
                    $settlementLines[] = ['account_id' => $data['bank_account_id'], 'debit' => '0.00', 'credit' => $bankAmount->toString()];
                }

                if ($settlementLines) {
                    $voucherLines = [
                        ...$voucherLines,
                        ...$settlementLines,
                        ['account_id' => $supplier->account_id, 'debit' => $total->toString(), 'credit' => '0.00'],
                    ];
                }
            } elseif ($paymentMode === 'cash') {
                $cashAccount = Account::where('code', 'AS1')->firstOrFail();
                $voucherLines[] = ['account_id' => $cashAccount->id, 'debit' => '0.00', 'credit' => $total->toString()];
            } elseif ($paymentMode === 'bank') {
                if (empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a bank payment.');
                }

                $voucherLines[] = ['account_id' => $data['bank_account_id'], 'debit' => '0.00', 'credit' => $total->toString()];
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
                // Snapshotted at posting: the Purchase VAT book must keep
                // printing the PAN the bill was claimed against even if the
                // supplier record is corrected later.
                'supplier_pan' => $data['supplier_pan'] ?? $supplier?->tpin,
                'store_id' => $storeId,
                'journal_voucher_id' => $voucher->id,
                'type' => $type,
                'bill_number' => $billNumber,
                'bill_number_guard' => $guard,
                'date' => $data['date'],
                'narration' => $data['narration'] ?? null,
                'payment_mode' => $paymentMode,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'cash_amount' => $paymentMode === 'partial' ? $cashAmount?->toString() : null,
                'bank_amount' => $paymentMode === 'partial' ? $bankAmount?->toString() : null,
                'taxable_amount' => $totals->taxableAmount->toString(),
                'nontaxable_amount' => $totals->nontaxableAmount->toString(),
                'vat_rate' => $totals->vatRate,
                'vat_amount' => $totals->vatAmount->toString(),
                'total' => $total->toString(),
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            foreach ($preparedLines as $line) {
                $capitalPurchaseLine = $capitalPurchase->lines()->create([
                    'account_id' => $line['account_id'],
                    'narration' => $line['narration'],
                    'amount' => $line['amount'],
                    'vatable' => $line['vatable'],
                ]);

                if ($line['create_asset']) {
                    $asset = static::createAssetForLine($line, $voucher, $data, $actor);
                    $capitalPurchaseLine->update(['fixed_asset_id' => $asset->id]);
                }
            }

            // Every line of this voucher carries the same compact narration
            // (item 10), so the supplier's ledger reads "CP-3 - Bank
            // Settlement" instead of a bare "Capital purchase from X"/
            // "Settlement".
            $documentNumber = 'CP-'.$voucher->voucher_number;
            $voucher->lines()->update([
                'narration' => SettlementNarration::line($documentNumber, $paymentMode),
            ]);

            return $capitalPurchase;
        });
    }

    /**
     * Creates a FixedAsset row for one "create asset" capital purchase line
     * (item 5). Deliberately does NOT call FixedAsset::post(): that method
     * creates its own ledger account and posts its own balanced voucher,
     * which would book this same cost a second time. Instead it reuses the
     * line's own account (which must already be filed under Fixed Assets -
     * exactly the account this capital purchase already debited for the
     * line's cost) and THIS capital purchase's own journal voucher, so the
     * asset's depreciation schedule starts from a cost that is already,
     * correctly, on the books exactly once.
     *
     * @param  array{account_id: int, amount: string, asset_name: ?string, depreciation_category: ?string, depreciation_method: ?string, depreciation_rate: mixed, salvage_value: mixed}  $line
     * @param  array{date: string}  $data
     */
    private static function createAssetForLine(array $line, JournalVoucher $voucher, array $data, User $actor): FixedAsset
    {
        $account = Account::with('group')->findOrFail($line['account_id']);

        if (($account->group?->name ?? null) !== 'Fixed Assets') {
            throw new InvalidArgumentException(
                "Cannot create a fixed asset from account \"{$account->name}\": it is not filed under Fixed Assets."
            );
        }

        if (empty($line['asset_name'])) {
            throw new InvalidArgumentException('An asset name is required to create a fixed asset from this line.');
        }

        $pool = DepreciationPool::from($line['depreciation_category']);
        $method = DepreciationMethod::from($line['depreciation_method']);
        $cost = Money::of($line['amount']);
        $salvageValue = Money::ofNullable($line['salvage_value']) ?? Money::zero();
        $rate = Money::of($line['depreciation_rate']);

        if ($salvageValue->isGreaterThan($cost)) {
            throw new InvalidArgumentException('The salvage value cannot be more than the asset cost.');
        }

        $asset = FixedAsset::create([
            'asset_code' => 'FA-PENDING-'.uniqid(),
            'asset_name' => $line['asset_name'],
            'account_id' => $account->id,
            'category' => $pool->value,
            'purchase_date' => $data['date'],
            'cost' => $cost->toString(),
            'salvage_value' => $salvageValue->toString(),
            'depreciation_method' => $method->value,
            'depreciation_rate' => $rate->toString(),
            'accumulated_depreciation' => '0.00',
            'status' => 'active',
            'journal_voucher_id' => $voucher->id,
            'created_by' => $actor->id,
        ]);

        $asset->update(['asset_code' => 'FA-'.str_pad((string) $asset->id, 5, '0', STR_PAD_LEFT)]);

        return $asset;
    }

    /**
     * Runs the document through DocumentCalculator.
     *
     * Exposed so the controller can validate a payload (and honour the
     * browser's `expected_total`) with the identical calculation the posting
     * path uses.
     *
     * @param  array{vat_rate?: mixed, expected_total?: mixed}  $data
     * @param  array<int, array{amount: mixed, vatable?: bool}>  $lines
     *
     * @throws BillingException
     */
    public static function calculateTotals(array $data, array $lines): DocumentTotals
    {
        return DocumentCalculator::calculate(
            array_map(static fn (array $line): array => [
                'quantity' => '1',
                'rate' => $line['amount'],
                'discount' => '0',
                'discount_type' => 'flat',
                'vatable' => (bool) ($line['vatable'] ?? false),
                'conversion_factor' => '1',
            ], array_values($lines)),
            [
                'vat_rate' => $data['vat_rate'] ?? CompanySetting::current()->default_vat_rate ?? '13.00',
                'discount' => '0',
                'discount_type' => 'flat',
                'expected_total' => $data['expected_total'] ?? null,
            ],
        );
    }

    /**
     * Cancels this capital purchase (contract C5).
     *
     * The row is re-read with lockForUpdate() and re-checked inside the
     * transaction, so two simultaneous cancels cannot both post a reversal
     * (audit P0-16). The mirroring itself is JournalVoucher::reverse()'s job:
     * it posts the reversal in the dedicated Reversal series, dated today, and
     * refuses outright once the document's fiscal year has been closed.
     *
     * The bill number guard is released at the same time, so the supplier's
     * bill can legitimately be re-entered once the wrong entry is cancelled.
     */
    public function cancel(User $actor, string $reason): void
    {
        DB::transaction(function () use ($actor, $reason): void {
            /** @var self $capitalPurchase */
            $capitalPurchase = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($capitalPurchase->status === 'cancelled') {
                throw new InvalidArgumentException('This capital purchase has already been cancelled.');
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw new InvalidArgumentException('A reason is required to cancel a capital purchase.');
            }

            if (mb_strlen($reason) > 500) {
                throw new InvalidArgumentException('The cancellation reason cannot be longer than 500 characters.');
            }

            if ($capitalPurchase->settlements()->where('status', 'posted')->exists()) {
                throw new InvalidArgumentException('This capital purchase has settlements against it. Cancel those settlements first.');
            }

            $assets = FixedAsset::query()
                ->whereIn('id', $capitalPurchase->lines()->whereNotNull('fixed_asset_id')->pluck('fixed_asset_id'))
                ->lockForUpdate()
                ->get();

            foreach ($assets as $asset) {
                if ($asset->status === 'disposed' || $asset->depreciations()->exists()) {
                    throw new InvalidArgumentException(
                        "Fixed asset {$asset->asset_code} from this purchase has been depreciated or disposed. Reverse those entries first."
                    );
                }
            }

            $reversal = JournalVoucher::reverse(
                $capitalPurchase->journalVoucher()->firstOrFail(),
                $actor,
                "Cancellation of capital purchase #{$capitalPurchase->id}: {$reason}",
            );

            foreach ($assets as $asset) {
                $asset->update(['status' => 'cancelled']);
            }

            $capitalPurchase->update([
                'status' => 'cancelled',
                'bill_number_guard' => null,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'reversal_journal_voucher_id' => $reversal->id,
            ]);

            $this->setRawAttributes($capitalPurchase->getAttributes(), true);
        });
    }
}
