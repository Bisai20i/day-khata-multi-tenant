<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Enums\QuotationStatus;
use App\Support\Billing\BillingException;
use App\Support\Billing\DocumentCalculator;
use App\Support\Billing\DocumentTotals;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A quotation never posts to the ledger or stock - it is a plain pre-sale
 * document a customer can accept or reject. The only thing that gives it
 * real accounting effect is convertToSale(), which hands off entirely to
 * Sale::post() (the exact same entry point SaleController::store() uses)
 * rather than re-implementing any sale logic here.
 *
 * Its totals are calculated by DocumentCalculator and stored on the row. That
 * is the whole point of the stored columns: the audit found the same quotation
 * adding up to three different amounts on three different screens, none of
 * them equal to the sale it became (P0-9), because the create preview, the
 * list and the PDF each did their own arithmetic. Now one calculator writes
 * the totals once and every screen renders what is stored, and conversion
 * refuses to post a sale that does not match the quote to the paisa.
 *
 * Legacy day_khata also has a near-identical "Order" module (order_records/
 * orders tables) with no distinguishing behaviour from Quotation beyond the
 * name - both were consolidated into this one concept rather than ported
 * as two separate flows.
 */
#[Fillable([
    'customer_id', 'date', 'discount', 'vat_rate', 'reference_number',
    'narration', 'status', 'sale_id', 'created_by',
    'taxable_amount', 'nontaxable_amount', 'vat_amount', 'total',
])]
class Quotation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'discount' => Decimal::class.':2',
            'vat_rate' => Decimal::class.':2',
            'taxable_amount' => Decimal::class.':2',
            'nontaxable_amount' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'total' => Decimal::class.':2',
            'status' => QuotationStatus::class,
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
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<QuotationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }

    /**
     * The one calculation a quotation ever goes through.
     *
     * `vatable` is read from the item, never from the request, so the quote
     * splits its subtotal into taxable and exempt exactly the way the sale it
     * converts into will. Quotation lines carry no alternate unit, so the
     * conversion factor is always 1 and the money side is unaffected by it.
     *
     * @param  array<int, array{item_id: int, quantity: mixed, rate: mixed, discount?: mixed}>  $lines
     * @param  array{discount?: mixed, vat_rate?: mixed, expected_total?: mixed}  $header
     *
     * @throws BillingException
     */
    public static function calculateTotals(array $lines, array $header): DocumentTotals
    {
        $items = Item::query()
            ->whereIn('id', collect($lines)->pluck('item_id')->all())
            ->get(['id', 'is_vatable'])
            ->keyBy('id');

        $calculatorLines = [];

        foreach ($lines as $line) {
            $item = $items->get($line['item_id']);

            if ($item === null) {
                throw new InvalidArgumentException("Unknown item [{$line['item_id']}].");
            }

            $calculatorLines[] = [
                'quantity' => $line['quantity'],
                'rate' => $line['rate'],
                'discount' => $line['discount'] ?? '0',
                'discount_type' => 'flat',
                'vatable' => (bool) $item->is_vatable,
                'conversion_factor' => '1',
            ];
        }

        return DocumentCalculator::calculate($calculatorLines, [
            'vat_rate' => $header['vat_rate'] ?? null,
            'discount' => $header['discount'] ?? '0',
            'discount_type' => 'flat',
            'expected_total' => $header['expected_total'] ?? null,
        ]);
    }

    /**
     * Recalculates this quotation from its own stored lines.
     *
     * Used by the PDF for the per-line amounts; the header figures it prints
     * come from the stored columns, so a printed quote can never quietly
     * disagree with the one the list showed.
     *
     * @throws BillingException
     */
    public function totals(): DocumentTotals
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->orderBy('id')->get();

        return static::calculateTotals(
            $lines->map(fn (QuotationLine $line): array => [
                'item_id' => $line->item_id,
                'quantity' => $line->quantity,
                'rate' => $line->rate,
                'discount' => $line->discount,
            ])->all(),
            ['discount' => $this->discount, 'vat_rate' => $this->vat_rate],
        );
    }

    /**
     * The calculated columns for a create or update, ready to merge into the
     * row's own attributes.
     *
     * @return array{taxable_amount: string, nontaxable_amount: string, vat_amount: string, total: string}
     */
    public static function storedTotals(DocumentTotals $totals): array
    {
        return [
            'taxable_amount' => $totals->taxableAmount->toString(),
            'nontaxable_amount' => $totals->nontaxableAmount->toString(),
            'vat_amount' => $totals->vatAmount->toString(),
            'total' => $totals->total->toString(),
        ];
    }

    /**
     * Converts this quotation into a real, posted Sale.
     *
     * A quotation never captures a genuine payment method (matches legacy's
     * own reasoning - the field is agreed with the customer only once the
     * goods actually change hands), so the resulting sale always settles as
     * credit with a "full" tax invoice.
     *
     * The row is re-read with lockForUpdate() and re-checked inside the
     * transaction: the status used to be checked before the transaction
     * opened, so two clicks on the Convert button (or two users) could both
     * pass the check and post two real sales for one quote (audit P0-16).
     *
     * The posted sale's total is then compared to the stored quote, exactly,
     * and the whole transaction is rolled back if they differ by so much as a
     * paisa. That is the guarantee the customer is owed: the bill is the quote
     * they accepted. The only way they can disagree is if an item's vatable
     * flag or the quotation's own rows changed after the quote was issued, in
     * which case the quote has to be re-made rather than silently rebilled.
     */
    public function convertToSale(User $actor): Sale
    {
        return DB::transaction(function () use ($actor) {
            /** @var self $quotation */
            $quotation = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($quotation->status !== QuotationStatus::Draft) {
                throw new InvalidArgumentException('Only a draft quotation can be converted to a sale.');
            }

            /** @var Collection<int, QuotationLine> $lines */
            $lines = $quotation->lines()->orderBy('id')->get();

            if ($lines->isEmpty()) {
                throw new InvalidArgumentException('Cannot convert a quotation with no line items.');
            }

            $sale = Sale::post(
                [
                    'customer_id' => $quotation->customer_id,
                    'invoice_type' => 'full',
                    'date' => now()->toDateString(),
                    'payment_mode' => 'credit',
                    'discount' => $quotation->discount,
                    'discount_type' => 'flat',
                    'vat_rate' => $quotation->vat_rate,
                    'narration' => $quotation->narration ?? "Converted from quotation #{$quotation->id}",
                ],
                $lines->map(fn (QuotationLine $line): array => [
                    'item_id' => $line->item_id,
                    'quantity' => $line->quantity,
                    'rate' => $line->rate,
                    'discount' => $line->discount,
                    'discount_type' => 'flat',
                ])->all(),
                $actor,
            );

            $quoted = Money::of($quotation->total);
            $billed = Money::of($sale->total);

            if (! $billed->isEqualTo($quoted)) {
                throw new InvalidArgumentException(
                    "This quotation totals {$quoted->format()} but the sale it would create totals {$billed->format()}. ".
                    'Re-check the quotation before converting it.'
                );
            }

            $quotation->update([
                'status' => QuotationStatus::Converted,
                'sale_id' => $sale->id,
            ]);

            $this->setRawAttributes($quotation->getAttributes(), true);

            return $sale;
        });
    }

    /**
     * Cancels a draft quotation the customer no longer wants. No ledger
     * reversal is needed since a draft quotation never posted anything, but
     * the row is still locked and re-checked inside the transaction so a
     * cancel racing a convert cannot both win (audit P0-16).
     */
    public function cancel(): void
    {
        DB::transaction(function (): void {
            /** @var self $quotation */
            $quotation = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            if ($quotation->status !== QuotationStatus::Draft) {
                throw new InvalidArgumentException('Only a draft quotation can be cancelled.');
            }

            $quotation->update(['status' => QuotationStatus::Cancelled]);

            $this->setRawAttributes($quotation->getAttributes(), true);
        });
    }
}
