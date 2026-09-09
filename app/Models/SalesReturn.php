<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A partial-line credit note against a prior Sale - the customer returned
 * some (not necessarily all) quantity from one or more of the original
 * sale's lines. Reverses only the revenue/VAT side proportional to what was
 * returned and credits the customer's account (a credit note reduces what
 * they owe, or creates a credit balance if the sale was already settled in
 * cash/bank), optionally followed by an immediate cash/bank refund voucher
 * if `refund_account_id` is supplied (see post()'s docblock).
 *
 * Stock: unlike Sale::cancel() (which flags existing movements cancelled),
 * a partial return can't flag a whole original movement since only part of
 * its quantity came back - so this WRITES a new inverse StockMovementType::
 * SaleReturn movement (direction +1, goods physically return to stock) per
 * returned line.
 *
 * Immutable once posted, EXCEPT via cancel() (see its own docblock) - same
 * append-only correction philosophy as every other voucher-backed document
 * in this app.
 *
 * `status` lifecycle: 'posted' (direct one-step post(), or a 'pending'
 * request() that was later approve()'d) -> 'cancelled' (reversed, see
 * cancel()). A `request()`'d return instead starts at 'pending' -> either
 * 'posted' (approve()) or 'rejected' (reject(), a dead end - a rejected
 * request never posts anything and is not itself cancellable). Kept as a
 * plain string rather than a backed enum (unlike Quotation's QuotationStatus)
 * because existing tests already assert this column as a raw string
 * ('cancelled') - changing the cast would break them for no behavioural gain.
 */
#[Fillable([
    'sale_id', 'journal_voucher_id', 'date', 'store_id', 'reason',
    'taxable_amount', 'nontaxable_amount', 'vat_amount', 'total', 'status',
    'refund_account_id', 'refund_journal_voucher_id', 'rejection_reason', 'created_by',
])]
class SalesReturn extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'taxable_amount' => 'decimal:2',
            'nontaxable_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<SaleReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @param  array{sale_id: int, date: string, reason?: string|null, refund_account_id?: int|null, store_id?: int|null}  $data
     * @param  array<int, array{sale_line_id: int, quantity: float}>  $lines
     *
     * Header-discount reversal: Sale::post() applies the header discount as
     * a uniform fraction of the vatable subtotal before crediting Sales
     * Revenue, so it reconstructs to a per-rupee ratio without needing the
     * original (unstored) vatable subtotal:
     * - `discount_type === 'flat'`: `discount` is already a Rs amount, and
     *   `vatableSubtotalBeforeDiscount = sale.taxable_amount + sale.discount`
     *   gives back the pre-discount vatable subtotal, so the ratio is
     *   `discount / vatableSubtotalBeforeDiscount`.
     * - `discount_type === 'percentage'`: `discount` already IS the raw
     *   percentage (e.g. `20` for 20%), so the ratio is simply
     *   `discount / 100` directly - Sale::post() computes
     *   `headerDiscountAmount = vatableSubtotal * discount / 100`, which
     *   removes exactly that fraction from every vatable rupee uniformly.
     * That ratio is applied to each returned vatable line's `lineTotal` and
     * subtracted from this return's own taxable_amount, so the amount
     * debited back out of Sales Revenue matches what was actually credited
     * there net of discount (not the gross line total).
     *
     * TDS reversal: at sale time TDS is booked as
     * `[debit tds_account, credit customer]` for `tds_amount`, netting the
     * customer's receivable down to `total - tds_amount`. A partial return
     * reverses a proportional share, `tdsShare = tds_amount * (return.total
     * / sale.total)`, via `[credit tds_account for tdsShare]` and by
     * crediting the customer only `total - tdsShare` (instead of the full
     * `total`) - the two credits still sum to the return's own `total`, so
     * the voucher stays balanced regardless of tdsShare's value.
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            $sale = Sale::findOrFail($data['sale_id']);

            if ($sale->status === 'cancelled') {
                throw new InvalidArgumentException('Cannot post a return against a cancelled sale.');
            }

            $storeId = self::resolveStoreId($data);

            [$preparedLines, $taxableAmount, $nontaxableAmount] = self::prepareLines($sale, $lines);
            [$vatAmount, $total] = self::computeTotals($sale, $taxableAmount, $nontaxableAmount);

            $tdsShare = 0.0;

            if ((float) $sale->tds_amount > 0 && (float) $sale->total > 0) {
                $tdsShare = round((float) $sale->tds_amount * ($total / (float) $sale->total), 2);
            }

            $voucherLines = [];

            $salesAccountId = Account::where('code', 'INI20')->firstOrFail()->id;
            $voucherLines[] = ['account_id' => $salesAccountId, 'debit' => $taxableAmount + $nontaxableAmount, 'credit' => 0, 'narration' => 'Sales return'];

            if ($vatAmount > 0) {
                $vatPayableId = Account::where('code', 'LIA20')->firstOrFail()->id;
                $voucherLines[] = ['account_id' => $vatPayableId, 'debit' => $vatAmount, 'credit' => 0, 'narration' => 'VAT reversed'];
            }

            $sale->loadMissing('customer');
            $customerCredit = round($total - $tdsShare, 2);
            $voucherLines[] = ['account_id' => $sale->customer->account_id, 'debit' => 0, 'credit' => $customerCredit, 'narration' => 'Sales return credit note'];

            if ($tdsShare > 0) {
                $voucherLines[] = ['account_id' => $sale->tds_account_id, 'debit' => 0, 'credit' => $tdsShare, 'narration' => 'TDS reversed'];
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::SaleReturn->value,
                    'date' => $data['date'],
                    'narration' => "Return against sale #{$sale->id}".(($data['reason'] ?? null) ? ": {$data['reason']}" : ''),
                ],
                $voucherLines,
                $actor,
            );

            $salesReturn = static::create([
                'sale_id' => $sale->id,
                'journal_voucher_id' => $voucher->id,
                'date' => $data['date'],
                'store_id' => $storeId,
                'reason' => $data['reason'] ?? null,
                'taxable_amount' => $taxableAmount,
                'nontaxable_amount' => $nontaxableAmount,
                'vat_amount' => $vatAmount,
                'total' => $total,
                'status' => 'posted',
                'refund_account_id' => $data['refund_account_id'] ?? null,
                'created_by' => $actor->id,
            ]);

            foreach ($preparedLines as $line) {
                $saleReturnLine = $salesReturn->lines()->create([
                    'sale_line_id' => $line['saleLine']->id,
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'line_total' => $line['line_total'],
                ]);

                $item = $line['saleLine']->item;

                if ($item->is_stockable) {
                    $item->recordStockMovement(
                        StockMovementType::SaleReturn,
                        $line['quantity'],
                        $data['date'],
                        $storeId,
                        $saleReturnLine,
                    );
                }
            }

            if (! empty($data['refund_account_id'])) {
                $refundVoucher = JournalVoucher::post(
                    [
                        'voucher_type' => VoucherType::Journal->value,
                        'date' => $data['date'],
                        'narration' => "Refund for sales return #{$salesReturn->id}",
                    ],
                    [
                        ['account_id' => $sale->customer->account_id, 'debit' => $customerCredit, 'credit' => 0, 'narration' => 'Refund settlement'],
                        ['account_id' => $data['refund_account_id'], 'debit' => 0, 'credit' => $customerCredit, 'narration' => 'Refund settlement'],
                    ],
                    $actor,
                );

                $salesReturn->update(['refund_journal_voucher_id' => $refundVoucher->id]);
            }

            return $salesReturn;
        });
    }

    /**
     * Requests a partial-line return WITHOUT posting anything yet - no
     * journal voucher, no stock movement, `journal_voucher_id` stays null.
     * Mirrors legacy day_khata's `sales_return_requests` intake queue
     * (`listreturnoutstockrequest`/`approveReturnRequest`/
     * `updateCancelResoanForSalesReturnRequest`) at the workflow level - a
     * request just sits pending until a second person approve()s or
     * reject()s it - but keeps this app's own real line-level detail (item +
     * sale_line_id + quantity via SaleReturnLine) rather than legacy's
     * single flattened item_id/quantity/"other_details" text fields, since
     * duplicating a second, thinner table would just re-import legacy's own
     * gap. Same validation and amount computation as post() (see
     * prepareLines()/computeTotals()) - the only difference is post() also
     * builds and posts the voucher/stock movements immediately, which this
     * defers to approve().
     *
     * The still-returnable-quantity check in prepareLines() already treats a
     * 'pending' request as consuming quantity (so two simultaneous requests
     * can't both claim more than a line has), while excluding 'rejected' -
     * see that method's own docblock.
     *
     * @param  array{sale_id: int, date: string, reason?: string|null, refund_account_id?: int|null, store_id?: int|null}  $data
     * @param  array<int, array{sale_line_id: int, quantity: float}>  $lines
     */
    public static function request(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            $sale = Sale::findOrFail($data['sale_id']);

            if ($sale->status === 'cancelled') {
                throw new InvalidArgumentException('Cannot request a return against a cancelled sale.');
            }

            $storeId = self::resolveStoreId($data);

            [$preparedLines, $taxableAmount, $nontaxableAmount] = self::prepareLines($sale, $lines);
            [$vatAmount, $total] = self::computeTotals($sale, $taxableAmount, $nontaxableAmount);

            $salesReturn = static::create([
                'sale_id' => $sale->id,
                'journal_voucher_id' => null,
                'date' => $data['date'],
                'store_id' => $storeId,
                'reason' => $data['reason'] ?? null,
                'taxable_amount' => $taxableAmount,
                'nontaxable_amount' => $nontaxableAmount,
                'vat_amount' => $vatAmount,
                'total' => $total,
                'status' => 'pending',
                'refund_account_id' => $data['refund_account_id'] ?? null,
                'created_by' => $actor->id,
            ]);

            foreach ($preparedLines as $line) {
                $salesReturn->lines()->create([
                    'sale_line_id' => $line['saleLine']->id,
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'line_total' => $line['line_total'],
                ]);
            }

            return $salesReturn;
        });
    }

    /**
     * Approves a 'pending' request(): NOW it posts, exactly what post()
     * itself does in one step for a direct return - a journal voucher, one
     * ItemStockMovement per stockable line, and the optional refund voucher
     * - just using this row's own already-computed/validated amounts and
     * already-persisted lines instead of recomputing from raw input. Posts
     * against the return's own requested `date` (not "today"), same as a
     * direct post() would have.
     *
     * Legacy's own `approveReturnRequest` is a bare `is_request_accepted=1`
     * flag flip with no posting logic at all - a real gap this app closes
     * rather than ports forward (see the class docblock's status-lifecycle
     * note and the file's own migration-decision writeup).
     */
    public function approve(User $actor): self
    {
        if ($this->status !== 'pending') {
            throw new InvalidArgumentException('Only a pending return request can be approved.');
        }

        return DB::transaction(function () use ($actor) {
            $sale = Sale::findOrFail($this->sale_id);

            if ($sale->status === 'cancelled') {
                throw new InvalidArgumentException('Cannot approve a return against a cancelled sale.');
            }

            $sale->loadMissing('customer');

            $taxableAmount = (float) $this->taxable_amount;
            $nontaxableAmount = (float) $this->nontaxable_amount;
            $vatAmount = (float) $this->vat_amount;
            $total = (float) $this->total;
            $date = $this->date->format('Y-m-d');

            $tdsShare = 0.0;

            if ((float) $sale->tds_amount > 0 && (float) $sale->total > 0) {
                $tdsShare = round((float) $sale->tds_amount * ($total / (float) $sale->total), 2);
            }

            $voucherLines = [];

            $salesAccountId = Account::where('code', 'INI20')->firstOrFail()->id;
            $voucherLines[] = ['account_id' => $salesAccountId, 'debit' => $taxableAmount + $nontaxableAmount, 'credit' => 0, 'narration' => 'Sales return'];

            if ($vatAmount > 0) {
                $vatPayableId = Account::where('code', 'LIA20')->firstOrFail()->id;
                $voucherLines[] = ['account_id' => $vatPayableId, 'debit' => $vatAmount, 'credit' => 0, 'narration' => 'VAT reversed'];
            }

            $customerCredit = round($total - $tdsShare, 2);
            $voucherLines[] = ['account_id' => $sale->customer->account_id, 'debit' => 0, 'credit' => $customerCredit, 'narration' => 'Sales return credit note'];

            if ($tdsShare > 0) {
                $voucherLines[] = ['account_id' => $sale->tds_account_id, 'debit' => 0, 'credit' => $tdsShare, 'narration' => 'TDS reversed'];
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::SaleReturn->value,
                    'date' => $date,
                    'narration' => "Return against sale #{$sale->id}".($this->reason ? ": {$this->reason}" : ''),
                ],
                $voucherLines,
                $actor,
            );

            $this->update([
                'journal_voucher_id' => $voucher->id,
                'status' => 'posted',
            ]);

            foreach ($this->lines()->with('saleLine.item')->get() as $line) {
                $item = $line->saleLine->item;

                if ($item->is_stockable) {
                    $item->recordStockMovement(
                        StockMovementType::SaleReturn,
                        (float) $line->quantity,
                        $date,
                        $this->store_id,
                        $line,
                    );
                }
            }

            if ($this->refund_account_id) {
                $refundVoucher = JournalVoucher::post(
                    [
                        'voucher_type' => VoucherType::Journal->value,
                        'date' => $date,
                        'narration' => "Refund for sales return #{$this->id}",
                    ],
                    [
                        ['account_id' => $sale->customer->account_id, 'debit' => $customerCredit, 'credit' => 0, 'narration' => 'Refund settlement'],
                        ['account_id' => $this->refund_account_id, 'debit' => 0, 'credit' => $customerCredit, 'narration' => 'Refund settlement'],
                    ],
                    $actor,
                );

                $this->update(['refund_journal_voucher_id' => $refundVoucher->id]);
            }

            return $this->fresh();
        });
    }

    /**
     * Rejects a 'pending' request with a mandatory reason. Nothing was ever
     * posted for a pending request, so there is nothing to reverse - this
     * just records why and marks it a dead end (never itself cancellable,
     * never postable again). Mirrors legacy's
     * `updateCancelResoanForSalesReturnRequest` (`is_request_accepted=2` +
     * `reson_for_not_accept`), modernized to a named status + column.
     */
    public function reject(string $reason): self
    {
        if ($this->status !== 'pending') {
            throw new InvalidArgumentException('Only a pending return request can be rejected.');
        }

        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        return $this;
    }

    /**
     * @param  array{store_id?: int|null}  $data
     */
    private static function resolveStoreId(array $data): int
    {
        $storeId = isset($data['store_id']) ? (int) $data['store_id'] : Store::where('is_active', true)->orderBy('id')->value('id');

        if (! $storeId) {
            throw new InvalidArgumentException('No active store is configured.');
        }

        return $storeId;
    }

    /**
     * Validates and prices each returned line against its original sale
     * line - shared by post() (which posts immediately) and request()
     * (which persists the same computed lines/amounts but defers posting to
     * approve()). See post()'s own docblock for the header-discount
     * reconstruction this applies per vatable line.
     *
     * The still-returnable-quantity check excludes both 'cancelled' AND
     * 'rejected' prior returns (neither one has any real effect to protect
     * against double-counting), but deliberately still counts a 'pending'
     * request against the remaining quantity - two simultaneous pending
     * requests against the same line must not be able to jointly approve to
     * more than that line's own quantity.
     *
     * @param  array<int, array{sale_line_id: int, quantity: float}>  $lines
     * @return array{0: array<int, array{saleLine: SaleLine, quantity: float, rate: mixed, line_total: float}>, 1: float, 2: float}
     */
    private static function prepareLines(Sale $sale, array $lines): array
    {
        if ($sale->discount_type === 'percentage') {
            $discountRatio = (float) $sale->discount / 100;
        } else {
            $vatableSubtotalBeforeDiscount = round((float) $sale->taxable_amount + (float) $sale->discount, 2);
            $discountRatio = ((float) $sale->discount > 0 && $vatableSubtotalBeforeDiscount > 0)
                ? (float) $sale->discount / $vatableSubtotalBeforeDiscount
                : 0.0;
        }

        $preparedLines = [];
        $taxableAmount = 0.0;
        $nontaxableAmount = 0.0;

        foreach ($lines as $line) {
            $saleLine = SaleLine::findOrFail($line['sale_line_id']);

            if ($saleLine->sale_id !== $sale->id) {
                throw new InvalidArgumentException("Line [{$saleLine->id}] does not belong to this sale.");
            }

            $quantity = (float) $line['quantity'];

            if ($quantity <= 0) {
                throw new InvalidArgumentException('Return quantity must be greater than zero.');
            }

            $alreadyReturned = (float) SaleReturnLine::where('sale_line_id', $saleLine->id)
                ->whereHas('salesReturn', fn ($query) => $query->whereNotIn('status', ['cancelled', 'rejected']))
                ->sum('quantity');
            $remaining = (float) $saleLine->quantity - $alreadyReturned;

            if ($quantity > $remaining) {
                throw new InvalidArgumentException("Cannot return {$quantity} of line [{$saleLine->id}]; only {$remaining} remains returnable.");
            }

            $effectiveUnitPrice = (float) $saleLine->line_total / (float) $saleLine->quantity;
            $lineTotal = round($effectiveUnitPrice * $quantity, 2);

            if ($saleLine->vatable) {
                $proportionalDiscount = $discountRatio > 0 ? round($lineTotal * $discountRatio, 2) : 0.0;

                $taxableAmount = round($taxableAmount + ($lineTotal - $proportionalDiscount), 2);
            } else {
                $nontaxableAmount = round($nontaxableAmount + $lineTotal, 2);
            }

            $preparedLines[] = [
                'saleLine' => $saleLine,
                'quantity' => $quantity,
                'rate' => $saleLine->rate,
                'line_total' => $lineTotal,
            ];
        }

        return [$preparedLines, $taxableAmount, $nontaxableAmount];
    }

    /**
     * @return array{0: float, 1: float} [vatAmount, total]
     */
    private static function computeTotals(Sale $sale, float $taxableAmount, float $nontaxableAmount): array
    {
        $vatAmount = round($taxableAmount * (float) $sale->vat_rate / 100, 2);
        $total = round($taxableAmount + $nontaxableAmount + $vatAmount, 2);

        return [$vatAmount, $total];
    }

    /**
     * Reverses this return - and its refund settlement voucher, if one was
     * posted - by mirroring every line of both vouchers (debit/credit
     * swapped) into one new voucher, exactly Sale::cancel()'s append-only
     * approach (never edits the original vouchers). Reusing both vouchers'
     * lines together (rather than just the return's own) matters when a
     * refund was paid out: reversing only the return would leave the
     * customer's account looking like they owe money again even though the
     * refund cash already left - reversing both nets the customer's ledger
     * back to exactly where it was right after the original sale.
     *
     * Reuses VoucherType::Sale for the reversal (a fresh charge back to the
     * customer) - the mirror image of why Sale::cancel() reuses
     * VoucherType::SaleReturn for ITS reversal - rather than adding a new
     * enum case.
     *
     * Only a 'posted' return has anything posted to reverse - a 'pending'
     * request or a 'rejected' one both guard here too (with their own clear
     * message) rather than falling through to journalVoucher()->firstOrFail()
     * and surfacing a confusing ModelNotFoundException instead.
     */
    public function cancel(User $actor, string $reason): void
    {
        if ($this->status !== 'posted') {
            throw new InvalidArgumentException(match ($this->status) {
                'cancelled' => 'This sales return has already been cancelled.',
                'pending' => 'A pending return request cannot be cancelled - approve or reject it instead.',
                'rejected' => 'A rejected return request cannot be cancelled - nothing was ever posted for it.',
                default => 'Only a posted sales return can be cancelled.',
            });
        }

        DB::transaction(function () use ($actor, $reason) {
            $vouchers = collect([$this->journalVoucher()->with('lines')->firstOrFail()]);

            if ($this->refund_journal_voucher_id) {
                $vouchers->push(JournalVoucher::with('lines')->findOrFail($this->refund_journal_voucher_id));
            }

            $mirroredLines = $vouchers
                ->flatMap(fn (JournalVoucher $voucher) => $voucher->lines)
                ->map(fn (JournalVoucherLine $line) => [
                    'account_id' => $line->account_id,
                    'debit' => (float) $line->credit,
                    'credit' => (float) $line->debit,
                    'narration' => $line->narration,
                ])
                ->all();

            JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::Sale->value,
                    'date' => now()->toDateString(),
                    'narration' => "Cancellation of sales return #{$this->id}: {$reason}",
                ],
                $mirroredLines,
                $actor,
            );

            ItemStockMovement::query()
                ->where('reference_type', (new SaleReturnLine)->getMorphClass())
                ->whereIn('reference_id', $this->lines()->pluck('id'))
                ->update(['cancelled' => true]);

            $this->update(['status' => 'cancelled']);
        });
    }
}
