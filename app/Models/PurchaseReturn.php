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
 * A partial-line return of previously purchased items back to a supplier -
 * a "debit note". Unlike Purchase::cancel() (a full-invoice reversal), this
 * returns only some quantity from some lines, so it posts its own voucher
 * and writes NEW inverse stock movements (it can't just flag the original
 * movements cancelled, since only part of their quantity went back).
 *
 * Header-level discount and TDS ARE now proportionally reversed (see
 * post()'s inline docblocks for the exact formulas) - this used to be a
 * documented gap, closed 2026-08-29. Still deliberately out of scope: an
 * over-return can't happen because alreadyReturned excludes cancelled
 * returns and re-checks against the original line's quantity; but neither
 * return type here reverses anything beyond this purchase's own discount/
 * TDS - a multi-purchase credit-balance scenario isn't modeled.
 */
#[Fillable([
    'purchase_id', 'journal_voucher_id', 'date', 'reason', 'taxable_amount',
    'nontaxable_amount', 'vat_amount', 'total', 'status', 'refund_account_id',
    'refund_journal_voucher_id', 'created_by',
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
            'taxable_amount' => 'decimal:2',
            'nontaxable_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
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
     * @return HasMany<PurchaseReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    /**
     * @param  array{purchase_id: int, date: string, reason?: string|null, refund_account_id?: int|null}  $data
     * @param  array<int, array{purchase_line_id: int, quantity: float}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            $purchase = Purchase::findOrFail($data['purchase_id']);

            if ($purchase->status === 'cancelled') {
                throw new InvalidArgumentException('Cannot return items against a cancelled purchase.');
            }

            $exe8 = Account::where('code', 'EXE8')->firstOrFail();

            // Purchase::post() applies the header discount as a uniform
            // percentage of the vatable subtotal (headerDiscount /
            // vatableSubtotal), reducing every vatable item account's debit
            // by that same ratio - see Purchase::post()'s own docblock.
            // vatableSubtotal itself isn't stored on the Purchase row, but
            // it's recoverable as taxable_amount + discount (taxable_amount
            // IS the post-discount vatable subtotal). Reversing a vatable
            // line's return at the same ratio keeps this return consistent
            // with what the original purchase actually booked per account.
            $vatableSubtotalOriginal = round((float) $purchase->taxable_amount + (float) $purchase->discount, 2);
            $discountRatio = ((float) $purchase->discount > 0 && $vatableSubtotalOriginal > 0)
                ? (float) $purchase->discount / $vatableSubtotalOriginal
                : 0.0;

            $preparedLines = [];
            $vatableAccountTotals = [];
            $nonVatableAccountTotals = [];

            foreach ($lines as $line) {
                $purchaseLine = PurchaseLine::findOrFail($line['purchase_line_id']);

                if ($purchaseLine->purchase_id !== $purchase->id) {
                    throw new InvalidArgumentException("Purchase line [{$purchaseLine->id}] does not belong to this purchase.");
                }

                $quantity = (float) $line['quantity'];

                if ($quantity <= 0) {
                    throw new InvalidArgumentException('Return quantity must be greater than zero.');
                }

                $alreadyReturned = (float) PurchaseReturnLine::where('purchase_line_id', $purchaseLine->id)
                    ->whereHas('purchaseReturn', fn ($q) => $q->where('status', '!=', 'cancelled'))
                    ->sum('quantity');
                $remaining = (float) $purchaseLine->quantity - $alreadyReturned;

                if ($quantity > $remaining + 0.0001) {
                    throw new InvalidArgumentException("Cannot return {$quantity} units of item #{$purchaseLine->item_id} - only {$remaining} remain returnable.");
                }

                $effectiveUnitPrice = (float) $purchaseLine->line_total / (float) $purchaseLine->quantity;
                $lineTotal = round($effectiveUnitPrice * $quantity, 2);

                $accountId = $purchaseLine->item->account_id ?? $exe8->id;

                if ($purchaseLine->vatable) {
                    $discountShare = round($lineTotal * $discountRatio, 2);
                    $effectiveLineTotal = round($lineTotal - $discountShare, 2);
                    $vatableAccountTotals[$accountId] = round(($vatableAccountTotals[$accountId] ?? 0) + $effectiveLineTotal, 2);
                } else {
                    $nonVatableAccountTotals[$accountId] = round(($nonVatableAccountTotals[$accountId] ?? 0) + $lineTotal, 2);
                }

                $preparedLines[] = [
                    'purchaseLine' => $purchaseLine,
                    'quantity' => $quantity,
                    'rate' => $effectiveUnitPrice,
                    'line_total' => $lineTotal,
                ];
            }

            $taxableAmount = round((float) array_sum($vatableAccountTotals), 2);
            $nontaxableAmount = round((float) array_sum($nonVatableAccountTotals), 2);
            $vatAmount = round($taxableAmount * (float) $purchase->vat_rate / 100, 2);
            $total = round($taxableAmount + $nontaxableAmount + $vatAmount, 2);

            $itemAccountTotals = $vatableAccountTotals;
            foreach ($nonVatableAccountTotals as $accountId => $amount) {
                $itemAccountTotals[$accountId] = round(($itemAccountTotals[$accountId] ?? 0) + $amount, 2);
            }

            $voucherLines = [];
            foreach ($itemAccountTotals as $accountId => $amount) {
                if (round($amount, 2) === 0.0) {
                    continue;
                }
                $voucherLines[] = ['account_id' => $accountId, 'debit' => 0, 'credit' => $amount, 'narration' => 'Purchase return'];
            }

            if ($vatAmount > 0) {
                $asa23 = Account::where('code', 'ASA23')->firstOrFail();
                $voucherLines[] = ['account_id' => $asa23->id, 'debit' => 0, 'credit' => $vatAmount, 'narration' => 'VAT receivable reversed'];
            }

            // TDS withheld at purchase time debited the supplier (reducing
            // what we owed them) and credited a TDS liability account - the
            // supplier never actually received that share of the invoice
            // total, it was withheld for the tax authority instead. So the
            // returned portion's TDS share must come back out of the TDS
            // liability directly, NOT be folded into the supplier's own
            // debit-note credit-back below; splitting $total this way keeps
            // the voucher balanced without a separate supplier-side line.
            $tdsShare = 0.0;
            if ((float) $purchase->tds_amount > 0 && (float) $purchase->total > 0) {
                $tdsShare = round((float) $purchase->tds_amount * ($total / (float) $purchase->total), 2);
            }

            $supplierDebit = round($total - $tdsShare, 2);
            $voucherLines[] = ['account_id' => $purchase->supplier->account_id, 'debit' => $supplierDebit, 'credit' => 0, 'narration' => 'Purchase return'];

            if ($tdsShare > 0) {
                $voucherLines[] = ['account_id' => $purchase->tds_account_id, 'debit' => $tdsShare, 'credit' => 0, 'narration' => 'TDS reversed'];
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::PurchaseReturn->value,
                    'date' => $data['date'],
                    'narration' => $data['reason'] ?? "Return against purchase #{$purchase->id}",
                ],
                $voucherLines,
                $actor,
            );

            $purchaseReturn = static::create([
                'purchase_id' => $purchase->id,
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
                $purchaseReturnLine = $purchaseReturn->lines()->create([
                    'purchase_line_id' => $line['purchaseLine']->id,
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'line_total' => $line['line_total'],
                ]);

                if ($line['purchaseLine']->item->is_stockable) {
                    $line['purchaseLine']->item->recordStockMovement(
                        StockMovementType::PurchaseReturn,
                        $line['quantity'],
                        $data['date'],
                        $purchaseReturnLine,
                    );
                }
            }

            // Optional immediate cash/bank refund from the supplier,
            // settling exactly the amount this return moved off the
            // supplier's own account ($supplierDebit - NOT $total, since
            // the TDS share above never touched the supplier's balance in
            // the first place and has nothing to refund).
            if (! empty($data['refund_account_id']) && $supplierDebit > 0) {
                $refundVoucher = JournalVoucher::post(
                    [
                        'voucher_type' => VoucherType::Journal->value,
                        'date' => $data['date'],
                        'narration' => "Refund for purchase return #{$purchaseReturn->id}",
                    ],
                    [
                        ['account_id' => $data['refund_account_id'], 'debit' => $supplierDebit, 'credit' => 0, 'narration' => 'Refund received'],
                        ['account_id' => $purchase->supplier->account_id, 'debit' => 0, 'credit' => $supplierDebit, 'narration' => 'Refund received'],
                    ],
                    $actor,
                );

                $purchaseReturn->update(['refund_journal_voucher_id' => $refundVoucher->id]);
            }

            return $purchaseReturn;
        });
    }

    /**
     * Reverses this return: posts a brand-new voucher mirroring the
     * original return voucher (debit/credit swapped, reusing
     * VoucherType::Purchase - by the same logic Purchase::cancel() reuses
     * VoucherType::PurchaseReturn for ITS reversal, since undoing a debit
     * note looks structurally like a fresh purchase), and - if this return
     * had an immediate refund posted - a second voucher mirroring that too.
     * Flags every stock movement this return generated as cancelled rather
     * than writing inverse movement rows. Never edits the original vouchers
     * (immutability rule, matches every other voucher in this app).
     */
    public function cancel(User $actor, string $reason): void
    {
        if ($this->status === 'cancelled') {
            throw new InvalidArgumentException('This purchase return has already been cancelled.');
        }

        DB::transaction(function () use ($actor, $reason) {
            $original = $this->journalVoucher()->with('lines')->firstOrFail();
            $this->reverseVoucher(
                $original,
                VoucherType::Purchase,
                "Cancellation of purchase return #{$this->id}: {$reason}",
                $actor,
            );

            if ($this->refund_journal_voucher_id) {
                $refundVoucher = $this->refundJournalVoucher()->with('lines')->firstOrFail();
                $this->reverseVoucher(
                    $refundVoucher,
                    VoucherType::Journal,
                    "Cancellation of refund for purchase return #{$this->id}: {$reason}",
                    $actor,
                );
            }

            ItemStockMovement::query()
                ->where('reference_type', (new PurchaseReturnLine)->getMorphClass())
                ->whereIn('reference_id', $this->lines()->pluck('id'))
                ->update(['cancelled' => true]);

            $this->update(['status' => 'cancelled']);
        });
    }

    private function reverseVoucher(JournalVoucher $voucher, VoucherType $voucherType, string $narration, User $actor): void
    {
        $mirroredLines = $voucher->lines->map(fn (JournalVoucherLine $line) => [
            'account_id' => $line->account_id,
            'debit' => (float) $line->credit,
            'credit' => (float) $line->debit,
            'narration' => $line->narration,
        ])->all();

        JournalVoucher::post(
            [
                'voucher_type' => $voucherType->value,
                'date' => now()->toDateString(),
                'narration' => $narration,
            ],
            $mirroredLines,
            $actor,
        );
    }
}
