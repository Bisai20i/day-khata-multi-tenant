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
 * A sale posts only the money side to the ledger (customer/cash-bank,
 * sales revenue, VAT, optional TDS) via JournalVoucher::post() - it never
 * posts an inventory-asset/COGS line. Legacy day_khata runs periodic, not
 * perpetual, inventory accounting (confirmed via an explicit docblock in
 * the legacy StockAdjustmentController); stock quantity is tracked
 * separately through Item::recordStockMovement(), fully decoupled from
 * the ledger. See day-khata-multi-tenant mem.md for the full research
 * this was built from.
 */
#[Fillable([
    'customer_id', 'agent_id', 'commission_amount', 'store_id', 'journal_voucher_id', 'invoice_type', 'date', 'payment_mode',
    'bank_account_id', 'discount', 'taxable_amount', 'nontaxable_amount',
    'vat_rate', 'vat_amount', 'total', 'cash_amount', 'bank_amount',
    'tds_account_id', 'tds_amount', 'narration', 'status', 'created_by',
])]
class Sale extends Model
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
            'commission_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
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
     * @return HasMany<SaleLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    /**
     * @return HasMany<SalesReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    /**
     * @return HasMany<ReceiptAllocation, $this>
     */
    public function receiptAllocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class);
    }

    /**
     * total minus every non-cancelled return against this sale minus every
     * non-cancelled Receipt allocated against it - the single source of
     * truth both Receipt::post()'s over-allocation guard and
     * SalesPurchaseReportController::agedReceivables() use, so they can
     * never drift apart. (Previously agedReceivables() summed ALL returns
     * including cancelled ones - a real pre-existing bug fixed here while
     * extracting this formula, matching SalesReturn's own "already
     * returned" guard, which already excludes cancelled returns.)
     */
    public function outstandingAmount(): float
    {
        return round(
            (float) $this->total
            - $this->returns()->where('status', '!=', 'cancelled')->sum('total')
            - $this->receiptAllocations()->whereHas('receipt', fn ($q) => $q->where('status', '!=', 'cancelled'))->sum('amount'),
            2
        );
    }

    /**
     * Computes the sale's totals, posts one balanced JournalVoucher for
     * the money side, creates the Sale + SaleLine rows, and records a
     * stock movement per stockable line.
     *
     * @param  array{customer_id: int, invoice_type: string, date: string, payment_mode: string, bank_account_id?: int|null, discount?: float, vat_rate?: float, cash_amount?: float|null, bank_amount?: float|null, tds_account_id?: int|null, tds_amount?: float, agent_id?: int|null, commission_amount?: float, narration?: string|null}  $data
     * @param  array<int, array{item_id: int, quantity: float, rate: float, discount?: float}>  $lines
     */
    public static function post(array $data, array $lines, User $actor): self
    {
        return DB::transaction(function () use ($data, $lines, $actor) {
            $customer = Customer::findOrFail($data['customer_id']);
            $vatRate = (float) ($data['vat_rate'] ?? 13.00);
            $headerDiscount = (float) ($data['discount'] ?? 0);

            $agentId = $data['agent_id'] ?? null;
            $agent = $agentId ? Agent::findOrFail($agentId) : null;
            $commissionAmount = round((float) ($data['commission_amount'] ?? 0), 2);

            if ($commissionAmount < 0) {
                throw new InvalidArgumentException('Commission amount cannot be negative.');
            }

            $storeId = isset($data['store_id']) ? (int) $data['store_id'] : Store::where('is_active', true)->orderBy('id')->value('id');
            if (! $storeId) {
                throw new InvalidArgumentException('No active store is configured.');
            }

            $items = Item::whereIn('id', collect($lines)->pluck('item_id'))->get()->keyBy('id');

            $preparedLines = [];
            $vatableSubtotal = 0.0;
            $nonVatableSubtotal = 0.0;

            foreach ($lines as $line) {
                if (! $items->has($line['item_id'])) {
                    throw new InvalidArgumentException("Unknown item [{$line['item_id']}].");
                }

                $item = $items[$line['item_id']];
                $quantity = (float) $line['quantity'];
                $rate = (float) $line['rate'];
                $lineDiscount = (float) ($line['discount'] ?? 0);
                $lineTotal = round($quantity * $rate - $lineDiscount, 2);

                if ($item->is_vatable) {
                    $vatableSubtotal += $lineTotal;
                } else {
                    $nonVatableSubtotal += $lineTotal;
                }

                $preparedLines[] = [
                    'item' => $item,
                    'quantity' => $quantity,
                    'rate' => $rate,
                    'discount' => $lineDiscount,
                    'vatable' => $item->is_vatable,
                    'line_total' => $lineTotal,
                ];
            }

            $taxableAmount = round($vatableSubtotal - $headerDiscount, 2);
            $nontaxableAmount = round($nonVatableSubtotal, 2);
            $vatAmount = round($taxableAmount * $vatRate / 100, 2);
            $total = round($taxableAmount + $nontaxableAmount + $vatAmount, 2);

            // Soft sanity guard only (not a hard business rule, per design) -
            // catches an obvious data-entry mistake (e.g. an extra digit)
            // without blocking a legitimate high-commission scenario.
            if ($commissionAmount > 0 && $total > 0 && $commissionAmount > $total * 5) {
                throw new InvalidArgumentException('Commission amount is implausibly large relative to the sale total.');
            }

            $tdsAmount = round((float) ($data['tds_amount'] ?? 0), 2);
            $tdsAccountId = $data['tds_account_id'] ?? null;

            if ($tdsAmount > 0 && ! $tdsAccountId) {
                throw new InvalidArgumentException('A TDS account is required when a TDS amount is set.');
            }

            $paymentMode = $data['payment_mode'];
            $cashAmount = isset($data['cash_amount']) ? round((float) $data['cash_amount'], 2) : null;
            $bankAmount = isset($data['bank_amount']) ? round((float) $data['bank_amount'], 2) : null;
            $bankAccountId = $data['bank_account_id'] ?? null;

            if (in_array($paymentMode, ['bank', 'partial'], true) && ! $bankAccountId) {
                throw new InvalidArgumentException('A bank account is required for bank or partial payment.');
            }

            $settlementDue = round($total - $tdsAmount, 2);

            if ($paymentMode === 'partial' && abs((($cashAmount ?? 0) + ($bankAmount ?? 0)) - $settlementDue) > 0.01) {
                throw new InvalidArgumentException('Cash and bank amounts must add up to the settlement due.');
            }

            $voucherLines = [];
            $voucherLines[] = ['account_id' => $customer->account_id, 'debit' => $total, 'credit' => 0, 'narration' => 'Sale total'];

            $salesAccountId = Account::where('code', 'INI20')->firstOrFail()->id;
            $voucherLines[] = ['account_id' => $salesAccountId, 'debit' => 0, 'credit' => $taxableAmount + $nontaxableAmount, 'narration' => 'Sales revenue'];

            if ($vatAmount > 0) {
                $vatPayableId = Account::where('code', 'LIA20')->firstOrFail()->id;
                $voucherLines[] = ['account_id' => $vatPayableId, 'debit' => 0, 'credit' => $vatAmount, 'narration' => 'VAT payable'];
            }

            if ($tdsAmount > 0) {
                $voucherLines[] = ['account_id' => $tdsAccountId, 'debit' => $tdsAmount, 'credit' => 0, 'narration' => 'TDS withheld'];
                $voucherLines[] = ['account_id' => $customer->account_id, 'debit' => 0, 'credit' => $tdsAmount, 'narration' => 'TDS withheld'];
            }

            // Commission is a real expense the business owes the agent, not
            // a customer-side adjustment like TDS above - posted as two
            // extra, fully independent lines rather than netted against the
            // customer's settlement amount.
            if ($agentId && $commissionAmount > 0) {
                $commissionExpenseId = Account::where('code', 'EXE22')->firstOrFail()->id;
                $voucherLines[] = ['account_id' => $commissionExpenseId, 'debit' => $commissionAmount, 'credit' => 0, 'narration' => 'Sales commission'];
                $voucherLines[] = ['account_id' => $agent->account_id, 'debit' => 0, 'credit' => $commissionAmount, 'narration' => 'Commission payable to agent'];
            }

            if ($paymentMode !== 'credit') {
                $cashAccountId = Account::where('code', 'AS1')->firstOrFail()->id;

                if ($paymentMode === 'cash') {
                    $voucherLines[] = ['account_id' => $cashAccountId, 'debit' => $settlementDue, 'credit' => 0, 'narration' => 'Cash received'];
                } elseif ($paymentMode === 'bank') {
                    $voucherLines[] = ['account_id' => $bankAccountId, 'debit' => $settlementDue, 'credit' => 0, 'narration' => 'Bank receipt'];
                } else {
                    if ($cashAmount > 0) {
                        $voucherLines[] = ['account_id' => $cashAccountId, 'debit' => $cashAmount, 'credit' => 0, 'narration' => 'Cash received'];
                    }
                    if ($bankAmount > 0) {
                        $voucherLines[] = ['account_id' => $bankAccountId, 'debit' => $bankAmount, 'credit' => 0, 'narration' => 'Bank receipt'];
                    }
                }

                $voucherLines[] = ['account_id' => $customer->account_id, 'debit' => 0, 'credit' => $settlementDue, 'narration' => 'Settlement'];
            }

            $voucherType = ($data['invoice_type'] ?? 'full') === 'abbreviated' ? VoucherType::SaleAbbreviated : VoucherType::Sale;

            $voucher = JournalVoucher::post(
                [
                    'voucher_type' => $voucherType->value,
                    'date' => $data['date'],
                    'narration' => $data['narration'] ?? "Sale to {$customer->name}",
                ],
                $voucherLines,
                $actor,
            );

            $sale = static::create([
                'customer_id' => $customer->id,
                'agent_id' => $agentId,
                'commission_amount' => $commissionAmount,
                'store_id' => $storeId,
                'journal_voucher_id' => $voucher->id,
                'invoice_type' => $data['invoice_type'] ?? 'full',
                'date' => $data['date'],
                'payment_mode' => $paymentMode,
                'bank_account_id' => $bankAccountId,
                'discount' => $headerDiscount,
                'taxable_amount' => $taxableAmount,
                'nontaxable_amount' => $nontaxableAmount,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total' => $total,
                'cash_amount' => $paymentMode === 'partial' ? $cashAmount : null,
                'bank_amount' => $paymentMode === 'partial' ? $bankAmount : null,
                'tds_account_id' => $tdsAccountId,
                'tds_amount' => $tdsAmount,
                'narration' => $data['narration'] ?? null,
                'status' => 'posted',
                'created_by' => $actor->id,
            ]);

            foreach ($preparedLines as $line) {
                $saleLine = $sale->lines()->create([
                    'item_id' => $line['item']->id,
                    'quantity' => $line['quantity'],
                    'rate' => $line['rate'],
                    'discount' => $line['discount'],
                    'vatable' => $line['vatable'],
                    'line_total' => $line['line_total'],
                ]);

                if ($line['item']->is_stockable) {
                    $line['item']->recordStockMovement(
                        StockMovementType::Sale,
                        $line['quantity'],
                        $data['date'],
                        $storeId,
                        $saleLine,
                    );
                }
            }

            return $sale;
        });
    }

    /**
     * Full-invoice cancellation only (no partial-line returns in this
     * pass). Posts a brand-new SaleReturn voucher mirroring every line of
     * the original voucher (debit/credit swapped) - the original voucher
     * is never edited, matching the immutability rule every voucher in
     * this app follows. Flags every stock movement this sale generated as
     * cancelled rather than writing inverse movement rows.
     */
    public function cancel(User $actor, string $reason): void
    {
        if ($this->status === 'cancelled') {
            throw new InvalidArgumentException('This sale has already been cancelled.');
        }

        if (SaleReturnLine::whereIn('sale_line_id', $this->lines()->pluck('id'))
            ->whereHas('salesReturn', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->exists()) {
            throw new InvalidArgumentException('Cannot cancel a sale that has partial returns against it.');
        }

        if ($this->receiptAllocations()->whereHas('receipt', fn ($query) => $query->where('status', '!=', 'cancelled'))->exists()) {
            throw new InvalidArgumentException('Cannot cancel a sale that has a payment received against it.');
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
                    'voucher_type' => VoucherType::SaleReturn->value,
                    'date' => now()->toDateString(),
                    'narration' => "Cancellation of sale #{$this->id}: {$reason}",
                ],
                $mirroredLines,
                $actor,
            );

            ItemStockMovement::query()
                ->where('reference_type', (new SaleLine)->getMorphClass())
                ->whereIn('reference_id', $this->lines()->pluck('id'))
                ->update(['cancelled' => true]);

            $this->update(['status' => 'cancelled']);
        });
    }
}
