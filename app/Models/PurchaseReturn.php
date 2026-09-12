<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
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
    'purchase_id', 'journal_voucher_id', 'fiscal_year_id', 'debit_note_number', 'date', 'store_id',
    'reason', 'taxable_amount', 'nontaxable_amount', 'vat_amount', 'tds_amount', 'total', 'status',
    'refund_account_id', 'refund_journal_voucher_id', 'created_by',
    'cancelled_at', 'cancelled_by', 'cancel_reason', 'reversal_journal_voucher_id',
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
            'taxable_amount' => Decimal::class.':2',
            'nontaxable_amount' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'tds_amount' => Decimal::class.':2',
            'total' => Decimal::class.':2',
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
            }

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
     * `$alreadyReserved` (every non-cancelled return) caps the quantity;
     * `$alreadyCredited` (posted returns only) is what the money is measured
     * against, so the two can never disagree about what is left.
     *
     * @return array{purchaseLine: PurchaseLine, quantity: Quantity, baseQuantity: Quantity, net: Money, vat: Money, tds: Money}
     */
    private static function prepareLine(PurchaseLine $purchaseLine, Quantity $quantity): array
    {
        if (! $quantity->isPositive()) {
            throw new InvalidArgumentException('Return quantity must be greater than zero.');
        }

        $lineQuantity = Quantity::of($purchaseLine->quantity);
        $reserved = static::quantityReturned($purchaseLine, postedOnly: false);
        $creditedQuantity = static::quantityReturned($purchaseLine, postedOnly: true);

        if ($quantity->plus($reserved)->isGreaterThan($lineQuantity)) {
            $remaining = $lineQuantity->minus($reserved);

            throw new InvalidArgumentException(
                "Cannot return {$quantity->formatQuantity()} of item #{$purchaseLine->item_id} - "
                ."only {$remaining->formatQuantity()} remain returnable."
            );
        }

        $isFinalReturn = $quantity->plus($creditedQuantity)->isEqualTo($lineQuantity);

        return [
            'purchaseLine' => $purchaseLine,
            'quantity' => $quantity,
            'baseQuantity' => $quantity->multipliedBy(Quantity::of($purchaseLine->unit_conversion_factor ?? '1')),
            'net' => static::share($purchaseLine, 'net_value', 'line_total', $quantity, $lineQuantity, $isFinalReturn),
            'vat' => static::share($purchaseLine, 'vat_amount', null, $quantity, $lineQuantity, $isFinalReturn),
            'tds' => static::share($purchaseLine, 'tds_amount', null, $quantity, $lineQuantity, $isFinalReturn),
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
}
