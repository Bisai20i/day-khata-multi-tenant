<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Support\Billing\DocumentCalculator;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use App\Support\SettlementNarration;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A partial-line return of previously purchased items back to a supplier - a
 * "debit note". Unlike Purchase::cancel() (a full-invoice reversal) this
 * returns only some quantity from some lines, so it posts its own voucher and
 * writes NEW inverse stock movements; it cannot just flag the original
 * movements cancelled, since only part of their quantity went back.
 *
 * Every amount follows the CONTRACTS C6 rule, which exists because the audit
 * found returns re-deriving amounts as `line_total / quantity * returnQty`:
 * returning a 3-unit line worth 100.00 one unit at a time credited 99.99
 * (P0-3). Here each return credits `component x returnQty / lineQty` with a
 * single rounding, EXCEPT the return that takes the line's last remaining
 * quantity, which credits "the line's component minus everything already
 * credited for it". Three thirds therefore always add back up to the whole, and
 * a return that finishes the bill reverses its VAT exactly.
 *
 * The accounts credited are the ones the original purchase line DEBITED
 * (`purchase_lines.account_id`), never the item's current account: re-pointing
 * an item between the bill and the debit note would otherwise leave two
 * accounts permanently wrong.
 */
#[Fillable([
    'purchase_id', 'supplier_id', 'journal_voucher_id', 'fiscal_year_id', 'debit_note_number', 'is_unlinked',
    'date', 'store_id', 'reason', 'taxable_amount', 'nontaxable_amount', 'vat_rate', 'vat_amount', 'tds_amount',
    'total', 'status', 'refund_account_id', 'refund_journal_voucher_id', 'cash_amount', 'bank_amount',
    'bank_account_id', 'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'reversal_journal_voucher_id',
])]
class PurchaseReturn extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cancelled_at' => 'datetime',
            'is_unlinked' => 'boolean',
            'taxable_amount' => Decimal::class.':2',
            'nontaxable_amount' => Decimal::class.':2',
            'vat_rate' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'tds_amount' => Decimal::class.':2',
            'total' => Decimal::class.':2',
            'cash_amount' => Decimal::class.':2',
            'bank_amount' => Decimal::class.':2',
        ];
    }

    /**
     * @return BelongsTo<Purchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * The supplier named directly on an unlinked return (item 4) - a linked
     * return has no row here and reads its supplier off `purchase->supplier`
     * instead, via documentSupplier() below.
     *
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
     * The supplier to show on screen/print, whichever kind of return this
     * is: linked reads it off the original purchase, unlinked (or a linked
     * return with no purchase loaded) off its own supplier_id.
     */
    public function documentSupplier(): ?Supplier
    {
        return $this->purchase?->supplier ?? $this->supplier;
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function refundAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'refund_account_id');
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function refundJournalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class, 'refund_journal_voucher_id');
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
     * @return HasMany<PurchaseReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * How much this return took off what the supplier is owed: the whole debit
     * note minus the TDS share, because that share was withheld for the IRD and
     * never sat on the supplier's account in the first place.
     */
    public function supplierCredit(): Money
    {
        return Money::of($this->total)->minus(Money::of($this->tds_amount ?? '0'));
    }

    /**
     * The number printed on the debit note. Stored at posting; never re-derived
     * from the voucher at display time, so a later cancellation cannot silently
     * renumber or gap the series (CONTRACTS C7).
     */
    public function documentNumber(): string
    {
        return $this->debit_note_number ?? "PR-{$this->id}";
    }

    /**
     * @param  array{purchase_id: int, date: string, reason?: string|null, refund_account_id?: int|null, store_id?: int|null}  $data
     * @param  array<int, array{purchase_line_id: int, quantity: mixed}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            /** @var Purchase $purchase */
            $purchase = Purchase::whereKey($data['purchase_id'])->lockForUpdate()->firstOrFail();

            if ($purchase->status !== 'posted') {
                throw new InvalidArgumentException('Only a posted purchase can be returned against.');
            }

            $date = static::validatedDate($data['date'], $purchase, $actor);
            $storeId = static::resolveStoreId($data['store_id'] ?? null, $purchase);

            $requested = static::aggregatedQuantities($lines);

            /** @var Collection<int, PurchaseLine> $purchaseLines */
            $purchaseLines = PurchaseLine::whereIn('id', array_keys($requested))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $prepared = [];

            foreach ($requested as $purchaseLineId => $quantity) {
                $purchaseLine = $purchaseLines->get($purchaseLineId);

                if (! $purchaseLine || $purchaseLine->purchase_id !== $purchase->id) {
                    throw new InvalidArgumentException("Purchase line [{$purchaseLineId}] does not belong to this purchase.");
                }

                $prepared[] = static::prepareLine($purchaseLine, $quantity);
            }

            static::assertStockAvailable($prepared, $storeId);

            $taxable = Money::sum(array_map(
                static fn (array $line): Money => $line['purchaseLine']->vatable ? $line['net'] : Money::zero(),
                $prepared
            ));
            $nonTaxable = Money::sum(array_map(
                static fn (array $line): Money => $line['purchaseLine']->vatable ? Money::zero() : $line['net'],
                $prepared
            ));
            $vat = Money::sum(array_map(static fn (array $line): Money => $line['vat'], $prepared));
            $tds = Money::sum(array_map(static fn (array $line): Money => $line['tds'], $prepared));
            $total = $taxable->plus($nonTaxable)->plus($vat);

            if (! $total->isPositive()) {
                throw new InvalidArgumentException('A purchase return must credit more than zero.');
            }

            $supplierDebit = $total->minus($tds);
            $voucherLines = static::accountVoucherLines($prepared);

            if ($vat->isPositive()) {
                $voucherLines[] = [
                    'account_id' => Account::where('code', 'ASA23')->firstOrFail()->id,
                    'debit' => '0',
                    'credit' => $vat->toString(),
                    'narration' => 'VAT receivable reversed',
                ];
            }

            $voucherLines[] = [
                'account_id' => $purchase->supplier->account_id,
                'debit' => $supplierDebit->toString(),
                'credit' => '0',
                'narration' => 'Purchase return',
            ];

            if ($tds->isPositive()) {
                $voucherLines[] = [
                    'account_id' => $purchase->tds_account_id,
                    'debit' => $tds->toString(),
                    'credit' => '0',
                    'narration' => 'TDS reversed',
                ];
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::PurchaseReturn->value,
                    'date' => $date,
                    'narration' => $data['reason'] ?? "Return against purchase #{$purchase->id}",
                ],
                $voucherLines,
                $actor,
            );

            $purchaseReturn = static::create([
                'purchase_id' => $purchase->id,
                'journal_voucher_id' => $voucher->id,
                'fiscal_year_id' => $voucher->fiscal_year_id,
                'debit_note_number' => static::debitNoteNumber($voucher),
                'date' => $date,
                'store_id' => $storeId,
                'reason' => $data['reason'] ?? null,
                'taxable_amount' => $taxable,
                'nontaxable_amount' => $nonTaxable,
                'vat_amount' => $vat,
                'tds_amount' => $tds,
                'total' => $total,
                'status' => 'posted',
                'refund_account_id' => $data['refund_account_id'] ?? null,
                'created_by' => $actor->id,
            ]);

            foreach ($prepared as $line) {
                $purchaseLine = $line['purchaseLine'];

                $returnLine = $purchaseReturn->lines()->create([
                    'purchase_line_id' => $purchaseLine->id,
                    'quantity' => $line['quantity'],
                    'bonus_quantity' => $line['bonusQuantity'],
                    'rate' => Quantity::of($purchaseLine->rate),
                    'line_total' => $line['net'],
                    'net_value' => $line['net'],
                    'vat_amount' => $line['vat'],
                    'tds_amount' => $line['tds'],
                ]);

                if ($purchaseLine->item->is_stockable) {
                    $purchaseLine->item->recordStockMovement(
                        StockMovementType::PurchaseReturn,
                        $line['baseQuantity'],
                        $date,
                        $storeId,
                        $returnLine,
                        static::unitCostRate($line['net'], $line['baseQuantity']),
                        $line['net'],
                    );
                }
            }

            // Every line of this credit-note voucher carries the same
            // compact narration (item 10): "PR-9 - Credit" tells it apart
            // from a cash/bank refund at a glance in the supplier's ledger.
            // `null` mode (no cash movement here - see the refund voucher
            // below for that) is exactly what SettlementNarration::forMode()
            // is documented to accept.
            $voucher->lines()->update([
                'narration' => SettlementNarration::line($purchaseReturn->debit_note_number, null),
            ]);

            // Optional immediate cash/bank refund from the supplier, settling
            // exactly the amount this return moved off the supplier's own
            // account ($supplierDebit - NOT $total, since the TDS share never
            // touched the supplier's balance and has nothing to refund).
            if (! empty($data['refund_account_id']) && $supplierDebit->isPositive()) {
                $refundVoucher = JournalVoucher::post(
                    [
                        'voucher_type' => VoucherType::Journal->value,
                        'date' => $date,
                        'narration' => "Refund for purchase return #{$purchaseReturn->id}",
                    ],
                    [
                        ['account_id' => $data['refund_account_id'], 'debit' => $supplierDebit->toString(), 'credit' => '0', 'narration' => 'Refund received'],
                        ['account_id' => $purchase->supplier->account_id, 'debit' => '0', 'credit' => $supplierDebit->toString(), 'narration' => 'Refund received'],
                    ],
                    $actor,
                );

                $purchaseReturn->update(['refund_journal_voucher_id' => $refundVoucher->id]);

                $refundVoucher->lines()->update([
                    'narration' => SettlementNarration::line(
                        $purchaseReturn->debit_note_number,
                        static::settlementModeForAccount((int) $data['refund_account_id']),
                    ),
                ]);
            }

            return $purchaseReturn;
        });
    }

    /**
     * An "unlinked" purchase return (item 4): goods from opening stock, or
     * from a purchase made before this system went live, that have to go
     * back to a supplier with no Purchase row to point at. Unlike post()
     * (which credits exactly what the original bill's lines are worth), this
     * values each line at an entered rate, or - when none is given - the
     * item's own weighted average cost as of the return date
     * (App\Support\Inventory\StockCosting), falling back to its catalogue
     * purchase rate the same way StockCosting itself does. VAT is the
     * company's own rate applied to the vatable lines, since there is no
     * original bill to read a rate off. There is no TDS: nothing was ever
     * withheld against a purchase that never existed in this system.
     *
     * The refund settles immediately, split cash/bank at posting time
     * (`DocumentCalculator::assertExactSplit`), rather than as a later
     * optional add-on the way a linked return's refund is - an unlinked
     * return usually has no ongoing supplier ledger relationship to leave a
     * balance sitting on.
     *
     * @param  array{date: string, supplier_id?: int|null, vat_rate?: mixed, payment_mode: string, bank_account_id?: int|null, cash_amount?: mixed, bank_amount?: mixed, store_id?: int|null, reason?: string|null, expected_total?: string|null}  $data
     * @param  array<int, array{item_id: int, item_unit_id?: int|null, quantity: mixed, rate?: mixed}>  $lines
     */
    public static function postUnlinked(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            if ($lines === []) {
                throw new InvalidArgumentException('An unlinked purchase return needs at least one line.');
            }

            $date = CarbonImmutable::parse($data['date'])->startOfDay()->toDateString();
            ClosedFiscalYearGuard::assertDateInOpenYear($date, $actor);

            $storeId = static::resolveUnlinkedStoreId($data['store_id'] ?? null);
            $company = CompanySetting::current();
            $vatRate = $data['vat_rate'] ?? $company->default_vat_rate ?? '13';

            $items = Item::with('units')
                ->whereIn('id', array_map(static fn (array $line): int => (int) $line['item_id'], $lines))
                ->get()
                ->keyBy('id');

            $prepared = [];

            foreach ($lines as $line) {
                $item = $items->get((int) $line['item_id']);

                if (! $item) {
                    throw new InvalidArgumentException("Unknown item [{$line['item_id']}].");
                }

                if (! $item->is_stockable) {
                    throw new InvalidArgumentException("\"{$item->name}\" is not a stockable item.");
                }

                [$itemUnitId, $factor] = static::resolveUnlinkedItemUnit($item, $line['item_unit_id'] ?? null);

                $quantity = Quantity::of($line['quantity']);

                if (! $quantity->isPositive()) {
                    throw new InvalidArgumentException('Return quantity must be greater than zero.');
                }

                $baseQuantity = $quantity->multipliedBy($factor);
                $enteredRate = isset($line['rate']) && $line['rate'] !== null && $line['rate'] !== ''
                    ? Quantity::of($line['rate'])
                    : null;

                if ($enteredRate !== null) {
                    $net = Money::round($quantity->toBigDecimal()->multipliedBy($enteredRate->toBigDecimal()));
                } else {
                    $averageCost = StockCosting::averageCost($item, $date)
                        ?? Quantity::ofNullable($item->purchase_rate)?->toBigDecimal()
                        ?? BigDecimal::zero();
                    $net = Money::round($baseQuantity->toBigDecimal()->multipliedBy($averageCost));
                }

                $prepared[] = [
                    'item' => $item,
                    'item_unit_id' => $itemUnitId,
                    'conversionFactor' => $factor,
                    'quantity' => $quantity,
                    'baseQuantity' => $baseQuantity,
                    'rate' => $enteredRate ?? Quantity::zero(),
                    'vatable' => $item->is_vatable,
                    'net' => $net,
                ];
            }

            static::assertUnlinkedStockAvailable($prepared, $storeId);

            $taxable = Money::sum(array_map(fn (array $l): Money => $l['vatable'] ? $l['net'] : Money::zero(), $prepared));
            $nonTaxable = Money::sum(array_map(fn (array $l): Money => $l['vatable'] ? Money::zero() : $l['net'], $prepared));
            $vat = $taxable->percent($vatRate);
            $total = $taxable->plus($nonTaxable)->plus($vat);

            if (! $total->isPositive()) {
                throw new InvalidArgumentException('An unlinked purchase return must credit more than zero.');
            }

            if (! empty($data['expected_total']) && ! Money::of($data['expected_total'])->isEqualTo($total)) {
                throw new InvalidArgumentException('The return total changed. Please review it before saving.');
            }

            $vatShares = $vat->isPositive()
                ? $vat->allocate(array_map(fn (array $l): Money => $l['vatable'] ? $l['net'] : Money::zero(), $prepared))
                : array_fill(0, count($prepared), Money::zero());

            $voucherLines = static::unlinkedAccountVoucherLines($prepared);

            if ($vat->isPositive()) {
                $voucherLines[] = [
                    'account_id' => Account::where('code', 'ASA23')->firstOrFail()->id,
                    'debit' => '0', 'credit' => $vat->toString(), 'narration' => 'VAT receivable reversed',
                ];
            }

            [$cash, $bank] = static::unlinkedSettlementSplit($data, $total);

            if ($cash->isPositive()) {
                $voucherLines[] = ['account_id' => Account::where('code', 'AS1')->firstOrFail()->id, 'debit' => $cash->toString(), 'credit' => '0', 'narration' => 'Refund received'];
            }

            if ($bank->isPositive()) {
                $voucherLines[] = ['account_id' => $data['bank_account_id'], 'debit' => $bank->toString(), 'credit' => '0', 'narration' => 'Refund received'];
            }

            $supplier = ! empty($data['supplier_id']) ? Supplier::find($data['supplier_id']) : null;

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::PurchaseReturn->value,
                    'date' => $date,
                    'narration' => $data['reason'] ?? ($supplier ? "Unlinked purchase return - {$supplier->name}" : 'Unlinked purchase return (opening stock)'),
                ],
                $voucherLines,
                $actor,
            );

            $purchaseReturn = static::create([
                'purchase_id' => null,
                'supplier_id' => $supplier?->id,
                'journal_voucher_id' => $voucher->id,
                'fiscal_year_id' => $voucher->fiscal_year_id,
                'debit_note_number' => static::debitNoteNumber($voucher),
                'is_unlinked' => true,
                'date' => $date,
                'store_id' => $storeId,
                'reason' => $data['reason'] ?? null,
                'vat_rate' => $vatRate,
                'taxable_amount' => $taxable,
                'nontaxable_amount' => $nonTaxable,
                'vat_amount' => $vat,
                'tds_amount' => '0',
                'total' => $total,
                'status' => 'posted',
                'cash_amount' => $cash,
                'bank_amount' => $bank,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'created_by' => $actor->id,
            ]);

            foreach ($prepared as $index => $line) {
                $returnLine = $purchaseReturn->lines()->create([
                    'purchase_line_id' => null,
                    'item_id' => $line['item']->id,
                    'item_unit_id' => $line['item_unit_id'],
                    'unit_conversion_factor' => $line['conversionFactor'],
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'line_total' => $line['net'],
                    'net_value' => $line['net'],
                    'vat_amount' => $vatShares[$index],
                    'tds_amount' => '0',
                ]);

                $line['item']->recordStockMovement(
                    StockMovementType::PurchaseReturn,
                    $line['baseQuantity'],
                    $date,
                    $storeId,
                    $returnLine,
                    null,
                    $line['net'],
                );
            }

            // Every line of this voucher carries the same compact narration
            // (item 10): an unlinked return always settles immediately, so
            // its mode is the payment_mode it was actually refunded in.
            $voucher->lines()->update([
                'narration' => SettlementNarration::line($purchaseReturn->debit_note_number, $data['payment_mode'] ?? 'cash'),
            ]);

            return $purchaseReturn;
        });
    }

    /**
     * Cancels this debit note: posts a Reversal voucher mirroring it (and a
     * second one mirroring the refund, if the supplier had refunded us), puts
     * the returned stock back by flagging this return's movements cancelled,
     * and records who cancelled it, when and why (CONTRACTS C4, C5).
     */
    public function cancel(User $actor, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to cancel a purchase return.');
        }

        DB::transaction(function () use ($actor, $reason) {
            /** @var self $return */
            $return = static::whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($return->status === 'cancelled') {
                throw new InvalidArgumentException('This purchase return has already been cancelled.');
            }

            $reversal = JournalVoucher::reverse(
                $return->journalVoucher()->firstOrFail(),
                $actor,
                "Cancellation of purchase return #{$return->id}: {$reason}",
            );

            if ($return->refund_journal_voucher_id) {
                JournalVoucher::reverse(
                    $return->refundJournalVoucher()->firstOrFail(),
                    $actor,
                    "Cancellation of refund for purchase return #{$return->id}: {$reason}",
                );
            }

            ItemStockMovement::query()
                ->where('reference_type', (new PurchaseReturnLine)->getMorphClass())
                ->whereIn('reference_id', $return->lines()->pluck('id'))
                ->update(['cancelled' => true]);

            $return->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'reversal_journal_voucher_id' => $reversal->id,
            ]);

            $this->setRawAttributes($return->getAttributes(), true);
        });
    }

    /**
     * Cuts this return's share out of one purchase line.
     *
     * `$alreadyReserved` (every non-cancelled return) caps the TOTAL
     * quantity - paid plus bonus (item 3) - since that is what is physically
     * on the shelf; `$alreadyCredited` (posted returns only, PAID quantity
     * only) is what the money is measured against, so the two can never
     * disagree about what is left.
     *
     * Bonus / free quantity (item 3): a purchase line may have received more
     * physical units than it billed (`bonus_quantity`). This return's own
     * quantity is split automatically - never typed by the clerk - into the
     * portion that still comes out of the line's PAID allotment (credited
     * money, same as always) and whatever is left over, which can only be
     * bonus stock and is credited at zero. Paid units are always consumed
     * first, so a return that empties the line always ends up crediting
     * exactly the line's remaining value, never a paisa more or less.
     *
     * @return array{purchaseLine: PurchaseLine, quantity: Quantity, bonusQuantity: Quantity, baseQuantity: Quantity, net: Money, vat: Money, tds: Money}
     */
    private static function prepareLine(PurchaseLine $purchaseLine, Quantity $quantity): array
    {
        if (! $quantity->isPositive()) {
            throw new InvalidArgumentException('Return quantity must be greater than zero.');
        }

        $paidQuantity = Quantity::of($purchaseLine->quantity);
        $bonusReceived = Quantity::of($purchaseLine->bonus_quantity ?? '0');
        $totalReceived = $paidQuantity->plus($bonusReceived);

        $reserved = static::quantityReturned($purchaseLine, postedOnly: false);

        if ($quantity->plus($reserved)->isGreaterThan($totalReceived)) {
            $remaining = $totalReceived->minus($reserved);

            throw new InvalidArgumentException(
                "Cannot return {$quantity->formatQuantity()} of item #{$purchaseLine->item_id} - "
                ."only {$remaining->formatQuantity()} remain returnable."
            );
        }

        $paidAlreadyCredited = static::paidQuantityCredited($purchaseLine);
        $paidRemaining = Quantity::max($paidQuantity->minus($paidAlreadyCredited), Quantity::zero());
        $thisPaidPortion = Quantity::min($quantity, $paidRemaining);
        $thisBonusPortion = $quantity->minus($thisPaidPortion);

        $isFinalReturn = $paidAlreadyCredited->plus($thisPaidPortion)->isEqualTo($paidQuantity);

        return [
            'purchaseLine' => $purchaseLine,
            'quantity' => $quantity,
            'bonusQuantity' => $thisBonusPortion,
            'baseQuantity' => $quantity->multipliedBy(Quantity::of($purchaseLine->unit_conversion_factor ?? '1')),
            'net' => static::share($purchaseLine, 'net_value', 'line_total', $thisPaidPortion, $paidQuantity, $isFinalReturn),
            'vat' => static::share($purchaseLine, 'vat_amount', null, $thisPaidPortion, $paidQuantity, $isFinalReturn),
            'tds' => static::share($purchaseLine, 'tds_amount', null, $thisPaidPortion, $paidQuantity, $isFinalReturn),
        ];
    }

    /**
     * One component's share: the exact remainder when this return takes the
     * line's last quantity, a single-rounding proportional slice otherwise.
     */
    private static function share(
        PurchaseLine $purchaseLine,
        string $column,
        ?string $fallbackColumn,
        Quantity $quantity,
        Quantity $lineQuantity,
        bool $isFinalReturn,
    ): Money {
        $component = Money::of($purchaseLine->{$column} ?? ($fallbackColumn ? $purchaseLine->{$fallbackColumn} : '0') ?? '0');

        if ($isFinalReturn) {
            return $component->minus(static::amountCredited($purchaseLine, $column));
        }

        return $component->multipliedByFraction($quantity, $lineQuantity);
    }

    /**
     * How much of this purchase line has already gone back.
     *
     * `$postedOnly = false` counts every return that is not cancelled: a return
     * awaiting approval has reserved that quantity and must still block an
     * over-return. `$postedOnly = true` is the population the money is measured
     * against, because only a posted return has moved any (audit P0-13).
     */
    private static function quantityReturned(PurchaseLine $purchaseLine, bool $postedOnly): Quantity
    {
        $values = static::earlierReturnLines($purchaseLine, $postedOnly)->pluck('quantity');

        return Quantity::sum($values->map(fn (string $value): Quantity => Quantity::of($value)));
    }

    /**
     * How much of this purchase line's PAID (non-bonus) quantity has already
     * been credited by a posted return - the base every further return's
     * paid/bonus split is measured against (item 3). Reads each earlier
     * return line's own persisted `bonus_quantity` rather than recomputing
     * it, so the split made at that return's own posting time is never
     * replayed differently later.
     */
    private static function paidQuantityCredited(PurchaseLine $purchaseLine): Quantity
    {
        $rows = static::earlierReturnLines($purchaseLine, postedOnly: true)->get(['quantity', 'bonus_quantity']);

        return Quantity::sum($rows->map(
            fn (PurchaseReturnLine $row): Quantity => Quantity::of($row->quantity)->minus(Quantity::of($row->bonus_quantity ?? '0'))
        ));
    }

    /**
     * What posted returns have already credited of one money column, the figure
     * the final return subtracts from the line's own component so no paisa is
     * invented or lost.
     */
    private static function amountCredited(PurchaseLine $purchaseLine, string $column): Money
    {
        $values = static::earlierReturnLines($purchaseLine, postedOnly: true)
            ->pluck($column)
            ->filter(fn (?string $value): bool => $value !== null);

        return Money::sum($values->map(fn (string $value): Money => Money::of($value)));
    }

    /**
     * @return Builder<PurchaseReturnLine>
     */
    private static function earlierReturnLines(PurchaseLine $purchaseLine, bool $postedOnly): Builder
    {
        return PurchaseReturnLine::where('purchase_line_id', $purchaseLine->id)
            ->whereHas(
                'purchaseReturn',
                fn ($query) => $postedOnly
                    ? $query->where('status', 'posted')
                    : $query->where('status', '!=', 'cancelled')
            );
    }

    /**
     * Collapses a request payload down to one row per purchase line, adding the
     * quantities up first. Sending the same line twice used to sail past the
     * remaining-quantity cap because each row was checked on its own (audit
     * P0-14); the controller's `distinct` rule rejects that outright, and this
     * makes the model safe even if it is called from somewhere else.
     *
     * Keys come back in ascending purchase-line id, the app-wide lock order.
     *
     * @param  array<int, array{purchase_line_id: int, quantity: mixed}>  $lines
     * @return array<int, Quantity>
     */
    private static function aggregatedQuantities(array $lines): array
    {
        /** @var array<int, Quantity> $quantities */
        $quantities = [];

        foreach ($lines as $line) {
            $id = (int) $line['purchase_line_id'];
            $quantities[$id] = ($quantities[$id] ?? Quantity::zero())->plus(Quantity::of($line['quantity']));
        }

        if ($quantities === []) {
            throw new InvalidArgumentException('A purchase return needs at least one line.');
        }

        ksort($quantities);

        return $quantities;
    }

    /**
     * One credit per account the original bill debited, each the exact sum of
     * the net values returned against it.
     *
     * @param  list<array{purchaseLine: PurchaseLine, net: Money}>  $prepared
     * @return list<array{account_id: int, debit: string, credit: string, narration: string}>
     */
    private static function accountVoucherLines(array $prepared): array
    {
        $fallbackAccountId = Account::where('code', 'EXE8')->firstOrFail()->id;

        /** @var array<int, Money> $totals */
        $totals = [];

        foreach ($prepared as $line) {
            $accountId = $line['purchaseLine']->account_id
                ?? $line['purchaseLine']->item->account_id
                ?? $fallbackAccountId;

            $totals[$accountId] = ($totals[$accountId] ?? Money::zero())->plus($line['net']);
        }

        $voucherLines = [];

        foreach ($totals as $accountId => $amount) {
            if ($amount->isZero()) {
                continue;
            }

            $voucherLines[] = [
                'account_id' => $accountId,
                'debit' => '0',
                'credit' => $amount->toString(),
                'narration' => 'Purchase return',
            ];
        }

        return $voucherLines;
    }

    /**
     * A debit note cannot predate the bill it returns, and it has to fall
     * inside the fiscal year that is currently open - back-dating one into a
     * filed VAT period is exactly what CONTRACTS C4's guard exists to stop.
     */
    private static function validatedDate(string $date, Purchase $purchase, User $actor): string
    {
        $returnDate = CarbonImmutable::parse($date)->startOfDay();

        if ($returnDate->lessThan($purchase->date->copy()->startOfDay())) {
            $billDate = $purchase->date->format('Y-m-d');

            throw new InvalidArgumentException("A purchase return cannot be dated before the purchase itself ({$billDate}).");
        }

        ClosedFiscalYearGuard::assertDateInOpenYear($returnDate->toDateString(), $actor);

        return $returnDate->toDateString();
    }

    /**
     * Returns leave from the store the goods were received into unless the form
     * says otherwise, so a multi-store tenant cannot silently take stock out of
     * a warehouse that never held it.
     */
    private static function resolveStoreId(mixed $storeId, Purchase $purchase): int
    {
        $storeId = $storeId !== null && $storeId !== ''
            ? (int) $storeId
            : ($purchase->store_id ?? CompanySetting::current()->default_store_id
                ?? Store::where('is_active', true)->orderBy('id')->value('id'));

        if (! $storeId) {
            throw new InvalidArgumentException('No active store is configured.');
        }

        return (int) $storeId;
    }

    /**
     * Refuses to send back stock the store no longer holds. The item rows are
     * locked in ascending id first, so the reads below cannot move underneath
     * this transaction (CONTRACTS C10).
     *
     * @param  list<array{purchaseLine: PurchaseLine, baseQuantity: Quantity}>  $prepared
     */
    private static function assertStockAvailable(array $prepared, int $storeId): void
    {
        if (CompanySetting::current()->allow_negative_stock) {
            return;
        }

        /** @var array<int, Quantity> $required */
        $required = [];

        foreach ($prepared as $line) {
            if (! $line['purchaseLine']->item->is_stockable) {
                continue;
            }

            $itemId = $line['purchaseLine']->item_id;
            $required[$itemId] = ($required[$itemId] ?? Quantity::zero())->plus($line['baseQuantity']);
        }

        if ($required === []) {
            return;
        }

        ksort($required);
        $items = Item::lockForStockOut(array_keys($required))->keyBy('id');

        foreach ($required as $itemId => $quantity) {
            $item = $items[$itemId];
            $available = $item->currentStock($storeId);

            if ($available->isLessThan($quantity)) {
                throw new InvalidArgumentException(
                    "Cannot return {$quantity->formatQuantity()} of {$item->name}: only "
                    ."{$available->formatQuantity()} remain in this store."
                );
            }
        }
    }

    /**
     * The settlement mode SettlementNarration reads off a refund's own
     * account, since a purchase return's refund_account_id can point at any
     * asset account rather than a fixed `payment_mode` field the way a
     * Purchase or Payment has one.
     */
    private static function settlementModeForAccount(int $accountId): string
    {
        return Account::find($accountId)?->code === 'AS1' ? 'cash' : 'bank';
    }

    /**
     * `{prefix}-{voucher number}` - the format the debit note has always
     * printed, now frozen onto the row at posting time.
     */
    private static function debitNoteNumber(JournalVoucher $voucher): string
    {
        $prefix = CompanySetting::current()->purchase_return_prefix ?: 'PR';

        return "{$prefix}-{$voucher->voucher_number}";
    }

    /**
     * Net credited cost per BASE unit, so the costing service can take this
     * return back out of the weighted average at the price it went in at
     * (CONTRACTS C10). One HalfUp division to 4 decimals, never divide-then-round.
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
     * The store an unlinked return leaves from when the form does not name
     * one: the tenant's configured default, else the oldest active store -
     * there is no parent purchase to fall back to (see resolveStoreId()'s
     * linked-return equivalent).
     */
    private static function resolveUnlinkedStoreId(mixed $storeId): int
    {
        $storeId = $storeId !== null && $storeId !== ''
            ? (int) $storeId
            : (CompanySetting::current()->default_store_id ?? Store::where('is_active', true)->orderBy('id')->value('id'));

        if (! $storeId) {
            throw new InvalidArgumentException('No active store is configured.');
        }

        return (int) $storeId;
    }

    /**
     * Resolves an unlinked line's optional item_unit_id against the item's
     * own units - mirrors Purchase::resolveItemUnit() exactly, since there is
     * no purchase line here to resolve it against instead.
     *
     * @return array{0: int|null, 1: Quantity}
     */
    private static function resolveUnlinkedItemUnit(Item $item, mixed $itemUnitId): array
    {
        if ($itemUnitId === null || $itemUnitId === '') {
            return [null, Quantity::of('1')];
        }

        $itemUnit = $item->units->firstWhere('id', (int) $itemUnitId);

        if (! $itemUnit) {
            throw new InvalidArgumentException("Unit [{$itemUnitId}] does not belong to item [{$item->id}].");
        }

        return [$itemUnit->id, Quantity::of($itemUnit->conversion_factor)];
    }

    /**
     * Refuses to send back more of an item than an unlinked return's store
     * actually holds - mirrors assertStockAvailable()'s linked-return
     * equivalent, adapted to work off items directly rather than purchase
     * lines.
     *
     * @param  list<array{item: Item, baseQuantity: Quantity}>  $prepared
     */
    private static function assertUnlinkedStockAvailable(array $prepared, int $storeId): void
    {
        if (CompanySetting::current()->allow_negative_stock) {
            return;
        }

        /** @var array<int, Quantity> $required */
        $required = [];

        foreach ($prepared as $line) {
            $itemId = $line['item']->id;
            $required[$itemId] = ($required[$itemId] ?? Quantity::zero())->plus($line['baseQuantity']);
        }

        if ($required === []) {
            return;
        }

        ksort($required);
        $items = Item::lockForStockOut(array_keys($required))->keyBy('id');

        foreach ($required as $itemId => $quantity) {
            $item = $items[$itemId];
            $available = $item->currentStock($storeId);

            if ($available->isLessThan($quantity)) {
                throw new InvalidArgumentException(
                    "Cannot return {$quantity->formatQuantity()} of {$item->name}: only "
                    ."{$available->formatQuantity()} remain in this store."
                );
            }
        }
    }

    /**
     * One credit per item's own posting account (falling back to EXE8, the
     * same as a linked return) - mirrors accountVoucherLines() exactly,
     * adapted to work off items directly.
     *
     * @param  list<array{item: Item, net: Money}>  $prepared
     * @return list<array{account_id: int, debit: string, credit: string, narration: string}>
     */
    private static function unlinkedAccountVoucherLines(array $prepared): array
    {
        $fallbackAccountId = Account::where('code', 'EXE8')->firstOrFail()->id;

        /** @var array<int, Money> $totals */
        $totals = [];

        foreach ($prepared as $line) {
            $accountId = $line['item']->account_id ?? $fallbackAccountId;
            $totals[$accountId] = ($totals[$accountId] ?? Money::zero())->plus($line['net']);
        }

        $voucherLines = [];

        foreach ($totals as $accountId => $amount) {
            if ($amount->isZero()) {
                continue;
            }

            $voucherLines[] = ['account_id' => $accountId, 'debit' => '0', 'credit' => $amount->toString(), 'narration' => 'Purchase return (unlinked)'];
        }

        return $voucherLines;
    }

    /**
     * How the immediate refund splits between cash and bank - exact to the
     * paisa (`DocumentCalculator::assertExactSplit`), never a tolerance.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Money, 1: Money}
     */
    private static function unlinkedSettlementSplit(array $data, Money $total): array
    {
        return match ($data['payment_mode'] ?? 'cash') {
            'cash' => [$total, Money::zero()],
            'bank' => static::unlinkedBankOnlySplit($data, $total),
            'partial' => static::unlinkedPartialSplit($data, $total),
            default => throw new InvalidArgumentException("Unknown payment mode: {$data['payment_mode']}"),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Money, 1: Money}
     */
    private static function unlinkedBankOnlySplit(array $data, Money $total): array
    {
        if (empty($data['bank_account_id'])) {
            throw new InvalidArgumentException('A bank account is required when the refund is paid to a bank.');
        }

        return [Money::zero(), $total];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Money, 1: Money}
     */
    private static function unlinkedPartialSplit(array $data, Money $total): array
    {
        $cash = Money::of($data['cash_amount'] ?? '0');
        $bank = Money::of($data['bank_amount'] ?? '0');

        DocumentCalculator::assertExactSplit($total, $cash, $bank);

        if ($bank->isPositive() && empty($data['bank_account_id'])) {
            throw new InvalidArgumentException('A bank account is required when part of the refund is paid to a bank.');
        }

        return [$cash, $bank];
    }
}
