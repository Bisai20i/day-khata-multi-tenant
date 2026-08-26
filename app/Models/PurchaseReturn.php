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
 * Deliberate simplifications, not oversights (see mem.md for the full
 * write-up):
 * - Does not proportionally back out the original purchase's HEADER-level
 *   discount - only the returned line's own per-unit (already
 *   discount-adjusted) rate is used.
 * - Does not reverse any TDS withheld on the original purchase.
 * - Does not auto-generate a cash/bank refund voucher - this always credits
 *   the supplier's own ledger account (a debit note), never cash/bank
 *   directly. If the original purchase was already fully settled, this
 *   simply leaves the supplier's account with a credit balance (they now
 *   owe money back), to be settled by a separate transaction if needed.
 */
#[Fillable([
    'purchase_id', 'journal_voucher_id', 'date', 'reason', 'taxable_amount',
    'nontaxable_amount', 'vat_amount', 'total', 'created_by',
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
     * @param  array{purchase_id: int, date: string, reason?: string|null}  $data
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

                $alreadyReturned = (float) PurchaseReturnLine::where('purchase_line_id', $purchaseLine->id)->sum('quantity');
                $remaining = (float) $purchaseLine->quantity - $alreadyReturned;

                if ($quantity > $remaining + 0.0001) {
                    throw new InvalidArgumentException("Cannot return {$quantity} units of item #{$purchaseLine->item_id} - only {$remaining} remain returnable.");
                }

                $effectiveUnitPrice = (float) $purchaseLine->line_total / (float) $purchaseLine->quantity;
                $lineTotal = round($effectiveUnitPrice * $quantity, 2);

                $accountId = $purchaseLine->item->account_id ?? $exe8->id;

                if ($purchaseLine->vatable) {
                    $vatableAccountTotals[$accountId] = round(($vatableAccountTotals[$accountId] ?? 0) + $lineTotal, 2);
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

            $voucherLines[] = ['account_id' => $purchase->supplier->account_id, 'debit' => $total, 'credit' => 0, 'narration' => 'Purchase return'];

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

            return $purchaseReturn;
        });
    }
}
