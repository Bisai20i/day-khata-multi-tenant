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
 */
#[Fillable([
    'supplier_id', 'journal_voucher_id', 'bill_number', 'pan_number', 'date',
    'payment_mode', 'bank_account_id', 'discount', 'taxable_amount',
    'nontaxable_amount', 'vat_rate', 'vat_amount', 'total', 'cash_amount',
    'bank_amount', 'tds_account_id', 'tds_amount', 'narration', 'status',
    'created_by',
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
            'discount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'nontaxable_amount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'cash_amount' => 'decimal:2',
            'bank_amount' => 'decimal:2',
            'tds_amount' => 'decimal:2',
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
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
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
     * Builds and posts the purchase's JournalVoucher, then creates the
     * Purchase + PurchaseLine rows and (for stockable items) records a
     * stock movement per line.
     *
     * @param  array{supplier_id: int, bill_number?: string, pan_number?: string, date: string, payment_mode: string, bank_account_id?: int, discount?: float, vat_rate?: float, cash_amount?: float, bank_amount?: float, tds_account_id?: int, tds_amount?: float, narration?: string}  $data
     * @param  array<int, array{item_id: int, quantity: float, rate: float, discount?: float}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            $supplier = Supplier::findOrFail($data['supplier_id']);
            $vatRate = (float) ($data['vat_rate'] ?? 13.00);
            $headerDiscount = round((float) ($data['discount'] ?? 0), 2);

            $lineModels = [];
            $vatableSubtotal = 0.0;
            $nonVatableSubtotal = 0.0;

            foreach ($lines as $line) {
                $item = Item::findOrFail($line['item_id']);
                $quantity = (float) $line['quantity'];
                $rate = (float) $line['rate'];
                $discount = round((float) ($line['discount'] ?? 0), 2);
                $lineTotal = round($quantity * $rate - $discount, 2);

                $lineModels[] = [
                    'item' => $item,
                    'quantity' => $quantity,
                    'rate' => $rate,
                    'discount' => $discount,
                    'vatable' => $item->is_vatable,
                    'line_total' => $lineTotal,
                ];

                if ($item->is_vatable) {
                    $vatableSubtotal = round($vatableSubtotal + $lineTotal, 2);
                } else {
                    $nonVatableSubtotal = round($nonVatableSubtotal + $lineTotal, 2);
                }
            }

            $taxableAmount = round($vatableSubtotal - $headerDiscount, 2);
            $nontaxableAmount = $nonVatableSubtotal;

            if ($headerDiscount > 0 && $vatableSubtotal <= 0) {
                throw new InvalidArgumentException('A discount cannot be applied when there are no vatable purchase lines.');
            }

            $vatAmount = round($taxableAmount * $vatRate / 100, 2);
            $total = round($taxableAmount + $nontaxableAmount + $vatAmount, 2);

            $exe8 = Account::where('code', 'EXE8')->firstOrFail();

            $vatableAccountTotals = [];
            $nonVatableAccountTotals = [];

            foreach ($lineModels as $line) {
                $accountId = $line['item']->account_id ?? $exe8->id;

                if ($line['vatable']) {
                    $vatableAccountTotals[$accountId] = round(($vatableAccountTotals[$accountId] ?? 0) + $line['line_total'], 2);
                } else {
                    $nonVatableAccountTotals[$accountId] = round(($nonVatableAccountTotals[$accountId] ?? 0) + $line['line_total'], 2);
                }
            }

            // The header discount reduces the vatable purchase accounts'
            // debit total by exactly $headerDiscount (so total debits stay
            // equal to total credits - the discount is "posted at net",
            // not as a separate discount-received account, since none is
            // seeded). Applied proportionally across destination accounts,
            // with the last account absorbing the rounding remainder so the
            // reduced total is exact rather than drifting by a cent.
            if ($headerDiscount > 0) {
                $accountIds = array_keys($vatableAccountTotals);
                $lastAccountId = end($accountIds);
                $remaining = round($vatableSubtotal - $headerDiscount, 2);

                foreach ($accountIds as $accountId) {
                    if ($accountId === $lastAccountId) {
                        $vatableAccountTotals[$accountId] = $remaining;
                        break;
                    }

                    $share = round($vatableAccountTotals[$accountId] - ($vatableAccountTotals[$accountId] / $vatableSubtotal) * $headerDiscount, 2);
                    $vatableAccountTotals[$accountId] = $share;
                    $remaining = round($remaining - $share, 2);
                }
            }

            $itemAccountTotals = $vatableAccountTotals;
            foreach ($nonVatableAccountTotals as $accountId => $amount) {
                $itemAccountTotals[$accountId] = round(($itemAccountTotals[$accountId] ?? 0) + $amount, 2);
            }

            $voucherLines = [];
            foreach ($itemAccountTotals as $accountId => $amount) {
                if (round($amount, 2) === 0.0) {
                    continue;
                }
                $voucherLines[] = ['account_id' => $accountId, 'debit' => $amount, 'credit' => 0];
            }

            if ($vatAmount > 0) {
                $asa23 = Account::where('code', 'ASA23')->firstOrFail();
                $voucherLines[] = ['account_id' => $asa23->id, 'debit' => $vatAmount, 'credit' => 0];
            }

            $voucherLines[] = ['account_id' => $supplier->account_id, 'debit' => 0, 'credit' => $total];

            $tdsAmount = round((float) ($data['tds_amount'] ?? 0), 2);

            if ($tdsAmount > 0) {
                if (empty($data['tds_account_id'])) {
                    throw new InvalidArgumentException('A TDS account is required when a TDS amount is withheld.');
                }

                $voucherLines[] = ['account_id' => $data['tds_account_id'], 'debit' => 0, 'credit' => $tdsAmount];
                $voucherLines[] = ['account_id' => $supplier->account_id, 'debit' => $tdsAmount, 'credit' => 0];
            }

            $paymentMode = $data['payment_mode'];
            $settlementDue = round($total - $tdsAmount, 2);
            $settlementLines = [];

            if ($paymentMode === 'cash') {
                $cashAccount = Account::where('code', 'AS1')->firstOrFail();
                if ($settlementDue > 0) {
                    $settlementLines[] = ['account_id' => $cashAccount->id, 'debit' => 0, 'credit' => $settlementDue];
                }
            } elseif ($paymentMode === 'bank') {
                if (empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a bank payment.');
                }
                if ($settlementDue > 0) {
                    $settlementLines[] = ['account_id' => $data['bank_account_id'], 'debit' => 0, 'credit' => $settlementDue];
                }
            } elseif ($paymentMode === 'partial') {
                $cashAccount = Account::where('code', 'AS1')->firstOrFail();
                $cashAmount = round((float) ($data['cash_amount'] ?? 0), 2);
                $bankAmount = round((float) ($data['bank_amount'] ?? 0), 2);

                if ($bankAmount > 0 && empty($data['bank_account_id'])) {
                    throw new InvalidArgumentException('A bank account is required for a partial payment with a bank portion.');
                }

                if (abs(($cashAmount + $bankAmount) - $settlementDue) > 0.01) {
                    throw new InvalidArgumentException('Cash and bank amounts must add up to the amount due.');
                }

                if ($cashAmount > 0) {
                    $settlementLines[] = ['account_id' => $cashAccount->id, 'debit' => 0, 'credit' => $cashAmount];
                }
                if ($bankAmount > 0) {
                    $settlementLines[] = ['account_id' => $data['bank_account_id'], 'debit' => 0, 'credit' => $bankAmount];
                }
            } elseif ($paymentMode !== 'credit') {
                throw new InvalidArgumentException("Unknown payment mode: {$paymentMode}");
            }

            if ($settlementLines) {
                $settledTotal = round((float) array_sum(array_column($settlementLines, 'credit')), 2);
                $voucherLines = [...$voucherLines, ...$settlementLines, ['account_id' => $supplier->account_id, 'debit' => $settledTotal, 'credit' => 0]];
            }

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::Purchase->value,
                    'date' => $data['date'],
                    'narration' => $data['narration'] ?? "Purchase from {$supplier->name}",
                ],
                $voucherLines,
                $actor,
            );

            $purchase = static::create([
                'supplier_id' => $supplier->id,
                'journal_voucher_id' => $voucher->id,
                'bill_number' => $data['bill_number'] ?? null,
                'pan_number' => $data['pan_number'] ?? null,
                'date' => $data['date'],
                'payment_mode' => $paymentMode,
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'discount' => $headerDiscount,
                'taxable_amount' => $taxableAmount,
                'nontaxable_amount' => $nontaxableAmount,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total' => $total,
                'cash_amount' => $data['cash_amount'] ?? null,
                'bank_amount' => $data['bank_amount'] ?? null,
                'tds_account_id' => $data['tds_account_id'] ?? null,
                'tds_amount' => $tdsAmount,
                'narration' => $data['narration'] ?? null,
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            foreach ($lineModels as $line) {
                $purchaseLine = $purchase->lines()->create([
                    'item_id' => $line['item']->id,
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'discount' => $line['discount'],
                    'vatable' => $line['vatable'],
                    'line_total' => $line['line_total'],
                ]);

                if ($line['item']->is_stockable) {
                    $line['item']->recordStockMovement(
                        StockMovementType::Purchase,
                        $line['quantity'],
                        $data['date'],
                        $purchaseLine,
                        $line['rate'],
                    );
                }
            }

            return $purchase;
        });
    }

    /**
     * Cancels this purchase: posts a NEW voucher that exactly mirrors the
     * original voucher's lines (every debit becomes a credit and vice
     * versa - JournalVoucher rows are immutable everywhere in this app, so
     * this is a real reversal, not a flag flip). Marks every stock
     * movement this purchase generated as cancelled, without writing new
     * inverse movements (Item::currentStock() already excludes cancelled
     * rows). Full-invoice only - no partial-line returns in this pass.
     */
    public function cancel(User $actor, string $reason): void
    {
        if ($this->status === 'cancelled') {
            throw new InvalidArgumentException('This purchase has already been cancelled.');
        }

        if (PurchaseReturnLine::whereIn('purchase_line_id', $this->lines()->pluck('id'))->exists()) {
            throw new InvalidArgumentException('Cannot cancel a purchase that has partial returns against it.');
        }

        DB::transaction(function () use ($actor, $reason) {
            $original = $this->journalVoucher()->with('lines')->firstOrFail();

            $mirroredLines = $original->lines->map(fn (JournalVoucherLine $line) => [
                'account_id' => $line->account_id,
                'debit' => (float) $line->credit,
                'credit' => (float) $line->debit,
                'narration' => $line->narration,
            ])->all();

            JournalVoucher::post(
                [
                    'voucher_type' => VoucherType::PurchaseReturn->value,
                    'date' => now()->toDateString(),
                    'narration' => "Cancellation of purchase #{$this->id}: {$reason}",
                ],
                $mirroredLines,
                $actor,
            );

            $lineIds = $this->lines()->pluck('id');

            ItemStockMovement::query()
                ->where('reference_type', PurchaseLine::class)
                ->whereIn('reference_id', $lineIds)
                ->update(['cancelled' => true]);

            $this->update(['status' => 'cancelled']);
        });
    }
}
