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
 */
#[Fillable([
    'sale_id', 'journal_voucher_id', 'date', 'reason',
    'taxable_amount', 'nontaxable_amount', 'vat_amount', 'total', 'status',
    'refund_account_id', 'refund_journal_voucher_id', 'created_by',
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
     * @param  array{sale_id: int, date: string, reason?: string|null, refund_account_id?: int|null}  $data
     * @param  array<int, array{sale_line_id: int, quantity: float}>  $lines
     *
     * Header-discount reversal: Sale::post() applies the header `discount`
     * only against the vatable subtotal before crediting Sales Revenue
     * (`taxable_amount = vatableSubtotal - discount`). Reconstructing
     * `vatableSubtotalBeforeDiscount = sale.taxable_amount + sale.discount`
     * gives back that pre-discount vatable subtotal, so each returned
     * vatable line's share of the discount is
     * `discount * (lineTotal / vatableSubtotalBeforeDiscount)` - subtracted
     * from that line's contribution to this return's own taxable_amount, so
     * the amount debited back out of Sales Revenue matches what was
     * actually credited there net of discount (not the gross line total).
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

            $vatableSubtotalBeforeDiscount = round((float) $sale->taxable_amount + (float) $sale->discount, 2);

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
                    ->whereHas('salesReturn', fn ($query) => $query->where('status', '!=', 'cancelled'))
                    ->sum('quantity');
                $remaining = (float) $saleLine->quantity - $alreadyReturned;

                if ($quantity > $remaining) {
                    throw new InvalidArgumentException("Cannot return {$quantity} of line [{$saleLine->id}]; only {$remaining} remains returnable.");
                }

                $effectiveUnitPrice = (float) $saleLine->line_total / (float) $saleLine->quantity;
                $lineTotal = round($effectiveUnitPrice * $quantity, 2);

                if ($saleLine->vatable) {
                    $proportionalDiscount = 0.0;

                    if ((float) $sale->discount > 0 && $vatableSubtotalBeforeDiscount > 0) {
                        $proportionalDiscount = round((float) $sale->discount * ($lineTotal / $vatableSubtotalBeforeDiscount), 2);
                    }

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

            $vatAmount = round($taxableAmount * (float) $sale->vat_rate / 100, 2);
            $total = round($taxableAmount + $nontaxableAmount + $vatAmount, 2);

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
     */
    public function cancel(User $actor, string $reason): void
    {
        if ($this->status === 'cancelled') {
            throw new InvalidArgumentException('This sales return has already been cancelled.');
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
