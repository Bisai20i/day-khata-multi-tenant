<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Support\Billing\DocumentCalculator;
use App\Support\Billing\DocumentTotals;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A purchase posts only the money side to the ledger (via JournalVoucher::
 * post()) - it never posts an inventory-asset/COGS line. Legacy day_khata
 * runs periodic, not perpetual, inventory accounting (confirmed via an
 * explicit docblock in the legacy StockAdjustmentController), so quantity
 * tracking lives entirely in ItemStockMovement, decoupled from the ledger.
 *
 * Stock/service/capital purchases are NOT modeled as separate flows here
 * (unlike the legacy app) - every purchase line just debits
 * item.account_id (falling back to the seeded "Purchases Account", EXE8),
 * and only items with is_stockable=true get a stock movement. A "capital"
 * purchase is simply an item whose account_id points at a Fixed Asset
 * account; a "service" purchase is an item with is_stockable=false.
 *
 * Every rupee on a purchase now comes out of App\Support\Billing\
 * DocumentCalculator, the one calculator sales, purchases, quotations and the
 * browser preview share. This class no longer does arithmetic of its own: it
 * decides which accounts the calculated amounts land on, and it records what
 * each line cost per base unit so stock valuation has a truthful basis
 * (CONTRACTS C3, C10; audit P0-1, P0-4, P0-17).
 */
#[Fillable([
    'supplier_id', 'store_id', 'journal_voucher_id', 'bill_number', 'bill_number_key', 'pan_number',
    'chalani_number', 'date', 'payment_mode', 'bank_account_id', 'discount', 'discount_type', 'taxable_amount',
    'nontaxable_amount', 'vat_rate', 'vat_amount', 'total', 'cash_amount',
    'bank_amount', 'tds_account_id', 'tds_amount', 'narration', 'status',
    'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'reversal_journal_voucher_id',
])]
class Purchase extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cancelled_at' => 'datetime',
            'discount' => Decimal::class.':2',
            'taxable_amount' => Decimal::class.':2',
            'nontaxable_amount' => Decimal::class.':2',
            'vat_rate' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'total' => Decimal::class.':2',
            'cash_amount' => Decimal::class.':2',
            'bank_amount' => Decimal::class.':2',
            'tds_amount' => Decimal::class.':2',
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
     * The mirrored voucher a cancellation posted, if this purchase was
     * cancelled - see cancel().
     *
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
     * @return BelongsTo<Account, $this>
     */
    public function tdsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'tds_account_id');
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
     * @return HasMany<PurchaseLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    /**
     * @return HasMany<PurchaseReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * What is still owed to the supplier on this bill, to the paisa.
     *
     * total
     *   - TDS withheld (never payable to the supplier, it goes to the IRD)
     *   - whatever was settled in cash/bank at posting time
     *   - every POSTED return, net of the TDS share that return gave back
     *   + any refund the supplier actually paid us against such a return
     *     (the cash came back, so the bill is owed in full again)
     *   - every allocation of a payment that has not been cancelled.
     *
     * Only `posted` returns count: a return sitting in any other state has no
     * money effect at all, which is precisely the bug the audit found lowering
     * a supplier's balance by a rejected request (P0-13).
     */
    public function outstandingAmount(): Money
    {
        $outstanding = Money::of($this->total)
            ->minus(Money::of($this->tds_amount ?? '0'))
            ->minus($this->settledAtPosting());

        foreach ($this->returns()->where('status', 'posted')->get() as $return) {
            $outstanding = $outstanding->minus($return->supplierCredit());

            if ($return->refund_journal_voucher_id !== null) {
                $outstanding = $outstanding->plus($return->supplierCredit());
            }
        }

        $allocated = Money::sum(
            $this->paymentAllocations()
                ->whereHas('payment', fn ($query) => $query->where('status', '!=', 'cancelled'))
                ->pluck('amount')
                ->map(fn (string $amount): Money => Money::of($amount))
        );

        return $outstanding->minus($allocated);
    }

    /**
     * The cash and bank actually handed over when the bill was entered.
     *
     * Bills posted before this class started writing both columns for every
     * payment mode left them null and settled the whole amount due, so those
     * rows are read back from `payment_mode` instead.
     */
    public function settledAtPosting(): Money
    {
        $cash = Money::ofNullable($this->cash_amount);
        $bank = Money::ofNullable($this->bank_amount);

        if ($cash !== null || $bank !== null) {
            return ($cash ?? Money::zero())->plus($bank ?? Money::zero());
        }

        if ($this->payment_mode === 'cash' || $this->payment_mode === 'bank') {
            return Money::of($this->total)->minus(Money::of($this->tds_amount ?? '0'));
        }

        return Money::zero();
    }

    /**
     * The rupees the header discount actually removed, for display on the PDF
     * (the `discount` column alone is meaningless without `discount_type`).
     *
     * Derived from the lines rather than recomputed from the percentage: every
     * line stores what it was worth before (`line_total`) and after
     * (`net_value`) its share of the header discount, so the difference is the
     * discount exactly as booked, with no second rounding.
     */
    public function discountAmount(): Money
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        $gross = Money::sum($lines->map(fn (PurchaseLine $line): Money => Money::of($line->line_total)));
        $net = Money::sum($lines->map(
            fn (PurchaseLine $line): Money => Money::of($line->net_value ?? $line->line_total)
        ));

        return $gross->minus($net);
    }

    /**
     * Builds and posts the purchase's JournalVoucher, then creates the
     * Purchase + PurchaseLine rows and (for stockable items) records a
     * stock movement per line.
     *
     * $data['fiscal_year_id']/['reason'] target a specific, non-current
     * fiscal year for a correction posting - see ClosedFiscalYearGuard's
     * docblock and the locked design decision in plans/invoicing-settings-
     * sale-purchase-ux.md. Omitted, this defaults to FiscalYear::current()
     * exactly as before.
     *
     * @param  array{supplier_id: int, bill_number?: string, pan_number?: string, chalani_number?: string|null, date: string, payment_mode: string, bank_account_id?: int, store_id?: int|null, discount?: mixed, discount_type?: string, vat_rate?: mixed, cash_amount?: mixed, bank_amount?: mixed, tds_account_id?: int, tds_amount?: mixed, expected_total?: string|null, narration?: string, fiscal_year_id?: int, reason?: string|null}  $data
     * @param  array<int, array{item_id: int, item_unit_id?: int|null, quantity: mixed, rate: mixed, discount?: mixed, discount_type?: string}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            $targetFiscalYear = isset($data['fiscal_year_id'])
                ? FiscalYear::findOrFail($data['fiscal_year_id'])
                : FiscalYear::current();
            $reason = $data['reason'] ?? null;
            $isCorrection = $targetFiscalYear->id !== FiscalYear::current()->id;

            ClosedFiscalYearGuard::ensurePostable($targetFiscalYear, $reason);

            if ($isCorrection && $actor->role?->slug !== 'admin') {
                throw new AuthorizationException('Only an admin may post into a reopened fiscal year.');
            }

            // The supplier row is held for the rest of the transaction so two
            // clerks entering the same supplier bill at the same moment
            // serialise here instead of racing past the duplicate check below.
            $supplier = Supplier::whereKey($data['supplier_id'])->lockForUpdate()->firstOrFail();
            $billNumber = static::normalisedBillNumber($data['bill_number'] ?? null);
            static::assertBillNumberUnused($supplier, $billNumber);

            $company = CompanySetting::current();
            $storeId = static::resolveStoreId($data['store_id'] ?? null, $company);

            /** @var list<array{item: Item, item_unit_id: int|null}> $context */
            $context = [];
            $calculatorLines = [];

            foreach ($lines as $line) {
                $item = Item::with('units')->findOrFail($line['item_id']);
                [$itemUnitId, $conversionFactor] = static::resolveItemUnit($item, $line['item_unit_id'] ?? null);

                $context[] = ['item' => $item, 'item_unit_id' => $itemUnitId];
                $calculatorLines[] = [
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'discount' => $line['discount'] ?? '0',
                    'discount_type' => $line['discount_type'] ?? 'flat',
                    'vatable' => $item->is_vatable,
                    'conversion_factor' => $conversionFactor,
                ];
            }

            $totals = DocumentCalculator::calculate($calculatorLines, [
                'vat_rate' => $data['vat_rate'] ?? $company->default_vat_rate ?? '13',
                'discount' => $data['discount'] ?? '0',
                'discount_type' => $data['discount_type'] ?? 'flat',
                'tds_amount' => $data['tds_amount'] ?? '0',
                'expected_total' => $data['expected_total'] ?? null,
            ]);

            $netValues = static::lineNetValues($totals);
            $vatShares = static::sharesOf($totals->vatAmount, array_map(
                static fn (int $index): Money => $totals->lines[$index]->vatable ? $netValues[$index] : Money::zero(),
                array_keys($netValues)
            ));
            $tdsShares = static::sharesOf($totals->tdsAmount, $netValues);

            $fallbackAccountId = Account::where('code', 'EXE8')->firstOrFail()->id;
            $accountIds = array_map(
                static fn (array $line): int => $line['item']->account_id ?? $fallbackAccountId,
                $context
            );

            $voucherLines = static::expenseVoucherLines($accountIds, $netValues);

            if ($totals->vatAmount->isPositive()) {
                $voucherLines[] = [
                    'account_id' => Account::where('code', 'ASA23')->firstOrFail()->id,
                    'debit' => $totals->vatAmount->toString(),
                    'credit' => '0',
                ];
            }

            $voucherLines[] = ['account_id' => $supplier->account_id, 'debit' => '0', 'credit' => $totals->total->toString()];

            if ($totals->tdsAmount->isPositive()) {
                if (empty($data['tds_account_id'])) {
                    throw new InvalidArgumentException('A TDS account is required when a TDS amount is withheld.');
                }

                $voucherLines[] = ['account_id' => $data['tds_account_id'], 'debit' => '0', 'credit' => $totals->tdsAmount->toString()];
                $voucherLines[] = ['account_id' => $supplier->account_id, 'debit' => $totals->tdsAmount->toString(), 'credit' => '0'];
            }

            [$cashAmount, $bankAmount] = static::settlementSplit($data, $totals->settlementDue);
            $voucherLines = [...$voucherLines, ...static::settlementVoucherLines($data, $supplier, $cashAmount, $bankAmount)];

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::Purchase->value,
                    'date' => $data['date'],
                    'narration' => $data['narration'] ?? "Purchase from {$supplier->name}",
                    'fiscal_year_id' => $targetFiscalYear->id,
                    'reason' => $reason,
                ],
                $voucherLines,
                $actor,
            );

            $purchase = static::create([
                'supplier_id' => $supplier->id,
                'store_id' => $storeId,
                'journal_voucher_id' => $voucher->id,
                'bill_number' => $billNumber,
                'bill_number_key' => $billNumber,
                'pan_number' => $data['pan_number'] ?? null,
                'chalani_number' => $data['chalani_number'] ?? null,
                'date' => $data['date'],
                'payment_mode' => $data['payment_mode'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'discount' => Money::of($data['discount'] ?? '0'),
                'discount_type' => $data['discount_type'] ?? 'flat',
                'taxable_amount' => $totals->taxableAmount,
                'nontaxable_amount' => $totals->nontaxableAmount,
                'vat_rate' => $totals->vatRate,
                'vat_amount' => $totals->vatAmount,
                'total' => $totals->total,
                'cash_amount' => $cashAmount,
                'bank_amount' => $bankAmount,
                'tds_account_id' => $data['tds_account_id'] ?? null,
                'tds_amount' => $totals->tdsAmount,
                'narration' => $data['narration'] ?? null,
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            foreach ($totals->lines as $index => $line) {
                $item = $context[$index]['item'];

                $purchaseLine = $purchase->lines()->create([
                    'item_id' => $item->id,
                    'item_unit_id' => $context[$index]['item_unit_id'],
                    'account_id' => $accountIds[$index],
                    'quantity' => $line->quantity,
                    'unit_conversion_factor' => $line->conversionFactor,
                    'rate' => $line->rate,
                    'discount' => $line->discountValue,
                    'discount_type' => $line->discountType,
                    'vatable' => $line->vatable,
                    'line_total' => $line->lineTotal,
                    'net_value' => $netValues[$index],
                    'vat_amount' => $vatShares[$index],
                    'tds_amount' => $tdsShares[$index],
                ]);

                if ($item->is_stockable) {
                    $item->recordStockMovement(
                        StockMovementType::Purchase,
                        $line->baseQuantity,
                        $data['date'],
                        $storeId,
                        $purchaseLine,
                        static::unitCostRate($netValues[$index], $line->baseQuantity),
                        $netValues[$index],
                    );
                }
            }

            if ($isCorrection) {
                ClosedFiscalYearGuard::logCorrection($targetFiscalYear, $reason, "Purchase #{$purchase->id} from {$supplier->name}");
            }

            return $purchase;
        });
    }

    /**
     * Cancels this purchase by posting a Reversal voucher that mirrors the
     * original (voucher rows are immutable everywhere in this app), flags the
     * stock this bill brought in as cancelled, and records who cancelled it,
     * when and why.
     *
     * Refused while any live return or payment allocation still points at the
     * bill, and refused when the stock it brought in has already been sold on:
     * cancelling then would drive the store negative and corrupt every
     * valuation after it. `allow_negative_stock` tenants opt out of that last
     * check, the same setting the rest of the app honours.
     */
    public function cancel(User $actor, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to cancel a purchase.');
        }

        DB::transaction(function () use ($actor, $reason) {
            /** @var self $purchase */
            $purchase = static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($purchase->status === 'cancelled') {
                throw new InvalidArgumentException('This purchase has already been cancelled.');
            }

            $lineIds = $purchase->lines()->orderBy('id')->pluck('id');

            if (PurchaseReturnLine::whereIn('purchase_line_id', $lineIds)
                ->whereHas('purchaseReturn', fn ($query) => $query->where('status', '!=', 'cancelled'))
                ->exists()) {
                throw new InvalidArgumentException('Cannot cancel a purchase that has partial returns against it.');
            }

            if ($purchase->paymentAllocations()
                ->whereHas('payment', fn ($query) => $query->where('status', '!=', 'cancelled'))
                ->exists()) {
                throw new InvalidArgumentException('Cannot cancel a purchase that has a payment made against it.');
            }

            $movements = ItemStockMovement::query()
                ->where('reference_type', (new PurchaseLine)->getMorphClass())
                ->whereIn('reference_id', $lineIds)
                ->where('cancelled', false)
                ->get();

            static::assertStockRemovable($movements);

            $reversal = JournalVoucher::reverse(
                $purchase->journalVoucher()->firstOrFail(),
                $actor,
                "Cancellation of purchase #{$purchase->id}: {$reason}",
            );

            ItemStockMovement::whereIn('id', $movements->pluck('id'))->update(['cancelled' => true]);

            $purchase->update([
                'status' => 'cancelled',
                'bill_number_key' => null,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'reversal_journal_voucher_id' => $reversal->id,
            ]);

            $this->setRawAttributes($purchase->getAttributes(), true);
        });
    }

    /**
     * Refuses to take stock back out of a store it is no longer in.
     *
     * The item rows are locked first (ascending id, the app-wide order that
     * keeps two concurrent stock-out postings from deadlocking), so the stock
     * read below cannot change underneath this transaction.
     *
     * @param  Collection<int, ItemStockMovement>  $movements
     */
    private static function assertStockRemovable(Collection $movements): void
    {
        if ($movements->isEmpty() || CompanySetting::current()->allow_negative_stock) {
            return;
        }

        $itemIds = $movements->pluck('item_id')->unique()->sort()->values()->all();
        $items = Item::lockForStockOut($itemIds)->keyBy('id');

        foreach ($movements->groupBy(['item_id', 'store_id']) as $itemId => $byStore) {
            /** @var Collection<int, Collection<int, ItemStockMovement>> $byStore */
            foreach ($byStore as $storeId => $group) {
                $removing = Quantity::sum($group->map(
                    fn (ItemStockMovement $movement): Quantity => Quantity::of($movement->quantity)
                ));
                $item = $items[$itemId];
                $available = $item->currentStock((int) $storeId);

                if ($available->isLessThan($removing)) {
                    throw new InvalidArgumentException(
                        "Cannot cancel this purchase: only {$available->formatQuantity()} of {$item->name} "
                        ."remain in stock, but cancelling would remove {$removing->formatQuantity()}."
                    );
                }
            }
        }
    }

    /**
     * At most one LIVE purchase may carry a given (supplier, bill number).
     * Checked here, inside the transaction and behind the supplier row lock, so
     * the user gets this sentence rather than a unique-constraint stack trace;
     * the index on purchases.bill_number_key is the backstop.
     */
    private static function assertBillNumberUnused(Supplier $supplier, ?string $billNumber): void
    {
        if ($billNumber === null) {
            return;
        }

        $existing = static::where('supplier_id', $supplier->id)
            ->where('bill_number_key', $billNumber)
            ->value('id');

        if ($existing !== null) {
            throw new InvalidArgumentException(
                "Bill number {$billNumber} has already been entered for {$supplier->name} (purchase #{$existing}). "
                .'Cancel that purchase first if this one replaces it.'
            );
        }
    }

    private static function normalisedBillNumber(mixed $billNumber): ?string
    {
        $billNumber = trim((string) ($billNumber ?? ''));

        return $billNumber === '' ? null : $billNumber;
    }

    /**
     * The store a purchase lands in when the form did not name one: the
     * tenant's configured default, else the oldest active store.
     */
    private static function resolveStoreId(mixed $storeId, CompanySetting $company): int
    {
        $storeId = $storeId !== null && $storeId !== ''
            ? (int) $storeId
            : ($company->default_store_id ?? Store::where('is_active', true)->orderBy('id')->value('id'));

        if (! $storeId) {
            throw new InvalidArgumentException('No active store is configured.');
        }

        return (int) $storeId;
    }

    /**
     * Each line's value after its own discount AND its share of the header
     * discount, excluding VAT - the figure CONTRACTS C10 wants on the stock
     * movement and CONTRACTS C6 cuts return shares from.
     *
     * The calculator has already split the header discount between the vatable
     * and exempt groups exactly; this splits each group's share over the lines
     * in it, again largest-remainder, so the line values always add back up to
     * the taxable and non-taxable amounts to the paisa.
     *
     * @return list<Money>
     */
    private static function lineNetValues(DocumentTotals $totals): array
    {
        $indexes = array_keys($totals->lines);

        $vatableWeights = array_map(
            static fn (int $index): Money => $totals->lines[$index]->vatable ? $totals->lines[$index]->lineTotal : Money::zero(),
            $indexes
        );
        $nonVatableWeights = array_map(
            static fn (int $index): Money => $totals->lines[$index]->vatable ? Money::zero() : $totals->lines[$index]->lineTotal,
            $indexes
        );

        $vatableShares = static::sharesOf($totals->headerDiscountVatable, $vatableWeights);
        $nonVatableShares = static::sharesOf($totals->headerDiscountNonVatable, $nonVatableWeights);

        return array_map(
            static fn (int $index): Money => $totals->lines[$index]->lineTotal
                ->minus($vatableShares[$index])
                ->minus($nonVatableShares[$index]),
            $indexes
        );
    }

    /**
     * Splits a document-level amount over the given weights with no paisa lost.
     * Nothing to split, or nothing to split it over, gives all zeroes rather
     * than an exception - a bill with no VAT or no TDS is perfectly normal.
     *
     * @param  list<Money>  $weights
     * @return list<Money>
     */
    private static function sharesOf(Money $amount, array $weights): array
    {
        if ($amount->isZero() || ! Money::sum($weights)->isPositive()) {
            return array_map(static fn (): Money => Money::zero(), $weights);
        }

        return $amount->allocate($weights);
    }

    /**
     * One debit per destination account, each the exact sum of its lines' net
     * values. Because those net values already carry the header discount, the
     * discount reaches the ledger split across the very accounts it discounted,
     * with no separate "discount received" account and no leftover paisa.
     *
     * @param  list<int>  $accountIds
     * @param  list<Money>  $netValues
     * @return list<array{account_id: int, debit: string, credit: string}>
     */
    private static function expenseVoucherLines(array $accountIds, array $netValues): array
    {
        /** @var array<int, Money> $totals */
        $totals = [];

        foreach ($accountIds as $index => $accountId) {
            $totals[$accountId] = ($totals[$accountId] ?? Money::zero())->plus($netValues[$index]);
        }

        $voucherLines = [];

        foreach ($totals as $accountId => $amount) {
            if ($amount->isZero()) {
                continue;
            }

            $voucherLines[] = ['account_id' => $accountId, 'debit' => $amount->toString(), 'credit' => '0'];
        }

        return $voucherLines;
    }

    /**
     * How much of the amount due was settled in cash and how much through the
     * bank. Both columns are always written, whatever the payment mode, so
     * outstandingAmount() never has to guess.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Money, 1: Money}
     */
    private static function settlementSplit(array $data, Money $settlementDue): array
    {
        return match ($data['payment_mode']) {
            'cash' => [$settlementDue, Money::zero()],
            'bank' => [Money::zero(), $settlementDue],
            'partial' => static::partialSplit($data, $settlementDue),
            'credit' => [Money::zero(), Money::zero()],
            default => throw new InvalidArgumentException("Unknown payment mode: {$data['payment_mode']}"),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Money, 1: Money}
     */
    private static function partialSplit(array $data, Money $settlementDue): array
    {
        $cash = Money::of($data['cash_amount'] ?? '0');
        $bank = Money::of($data['bank_amount'] ?? '0');

        DocumentCalculator::assertExactSplit($settlementDue, $cash, $bank);

        return [$cash, $bank];
    }

    /**
     * The money-out side of the voucher: what left the till or the bank, and
     * the matching debit that reduces what the supplier is owed.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{account_id: int, debit: string, credit: string}>
     */
    private static function settlementVoucherLines(array $data, Supplier $supplier, Money $cash, Money $bank): array
    {
        if ($bank->isPositive() && empty($data['bank_account_id'])) {
            throw new InvalidArgumentException('A bank account is required when part of the bill is paid from a bank.');
        }

        $lines = [];

        if ($cash->isPositive()) {
            $lines[] = [
                'account_id' => Account::where('code', 'AS1')->firstOrFail()->id,
                'debit' => '0',
                'credit' => $cash->toString(),
            ];
        }

        if ($bank->isPositive()) {
            $lines[] = ['account_id' => $data['bank_account_id'], 'debit' => '0', 'credit' => $bank->toString()];
        }

        if ($lines === []) {
            return [];
        }

        $lines[] = ['account_id' => $supplier->account_id, 'debit' => $cash->plus($bank)->toString(), 'credit' => '0'];

        return $lines;
    }

    /**
     * Net cost per BASE unit: what one piece really cost after every discount
     * and before VAT. Buying 2 Box of 12 for Rs 1,200 costs Rs 50 a piece, not
     * Rs 1,200 - the audit found the entered-unit gross rate being stored
     * against the base quantity, which values stock 24x too high (P0-17).
     *
     * A single HalfUp division to 4 decimals, never a divide-then-round.
     */
    private static function unitCostRate(Money $value, Quantity $baseQuantity): ?Quantity
    {
        if ($baseQuantity->isZero()) {
            return null;
        }

        return Quantity::of(
            $value->toBigDecimal()->dividedBy($baseQuantity->toBigDecimal(), 4, RoundingMode::HalfUp)
        );
    }

    /**
     * Resolves a line's optional item_unit_id against the item's already-
     * loaded `units` relation. Mirrors Sale::resolveItemUnit() exactly - see
     * that method's docblock for the full rationale, including why a null/
     * missing item_unit_id is a guaranteed behavior-preserving no-op.
     *
     * The factor comes back as a decimal string, never a float: it is about to
     * multiply a quantity, and a float would reintroduce exactly the drift the
     * money foundation exists to remove.
     *
     * @return array{0: int|null, 1: string}
     */
    private static function resolveItemUnit(Item $item, mixed $itemUnitId): array
    {
        if ($itemUnitId === null || $itemUnitId === '') {
            return [null, '1'];
        }

        $itemUnit = $item->units->firstWhere('id', (int) $itemUnitId);

        if (! $itemUnit) {
            throw new InvalidArgumentException("Unit [{$itemUnitId}] does not belong to item [{$item->id}].");
        }

        return [$itemUnit->id, (string) $itemUnit->conversion_factor];
    }
}
