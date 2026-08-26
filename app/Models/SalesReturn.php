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
 * cash/bank - this pass does NOT auto-generate a cash/bank refund voucher,
 * that's a separate manual action). Two deliberate simplifications, not
 * oversights: (1) the sale's header-level discount is not proportionally
 * backed out, only the returned line's own rate/discount; (2) any TDS
 * withheld on the original sale is not reversed. Both are real gaps if full
 * legacy parity is ever wanted here.
 *
 * Stock: unlike Sale::cancel() (which flags existing movements cancelled),
 * a partial return can't flag a whole original movement since only part of
 * its quantity came back - so this WRITES a new inverse StockMovementType::
 * SaleReturn movement (direction +1, goods physically return to stock) per
 * returned line.
 */
#[Fillable([
    'sale_id', 'journal_voucher_id', 'date', 'reason',
    'taxable_amount', 'nontaxable_amount', 'vat_amount', 'total', 'created_by',
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
     * @param  array{sale_id: int, date: string, reason?: string|null}  $data
     * @param  array<int, array{sale_line_id: int, quantity: float}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            $sale = Sale::findOrFail($data['sale_id']);

            if ($sale->status === 'cancelled') {
                throw new InvalidArgumentException('Cannot post a return against a cancelled sale.');
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

                $alreadyReturned = (float) SaleReturnLine::where('sale_line_id', $saleLine->id)->sum('quantity');
                $remaining = (float) $saleLine->quantity - $alreadyReturned;

                if ($quantity > $remaining) {
                    throw new InvalidArgumentException("Cannot return {$quantity} of line [{$saleLine->id}]; only {$remaining} remains returnable.");
                }

                $effectiveUnitPrice = (float) $saleLine->line_total / (float) $saleLine->quantity;
                $lineTotal = round($effectiveUnitPrice * $quantity, 2);

                if ($saleLine->vatable) {
                    $taxableAmount = round($taxableAmount + $lineTotal, 2);
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

            $voucherLines = [];

            $salesAccountId = Account::where('code', 'INI20')->firstOrFail()->id;
            $voucherLines[] = ['account_id' => $salesAccountId, 'debit' => $taxableAmount + $nontaxableAmount, 'credit' => 0, 'narration' => 'Sales return'];

            if ($vatAmount > 0) {
                $vatPayableId = Account::where('code', 'LIA20')->firstOrFail()->id;
                $voucherLines[] = ['account_id' => $vatPayableId, 'debit' => $vatAmount, 'credit' => 0, 'narration' => 'VAT reversed'];
            }

            $sale->loadMissing('customer');
            $voucherLines[] = ['account_id' => $sale->customer->account_id, 'debit' => 0, 'credit' => $total, 'narration' => 'Sales return credit note'];

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

            return $salesReturn;
        });
    }
}
