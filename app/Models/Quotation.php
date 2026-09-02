<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * Legacy day_khata also has a near-identical "Order" module (order_records/
 * orders tables) with no distinguishing behaviour from Quotation beyond the
 * name - both were consolidated into this one concept rather than ported
 * as two separate flows.
 */
#[Fillable([
    'customer_id', 'date', 'discount', 'vat_rate', 'reference_number',
    'narration', 'status', 'sale_id', 'created_by',
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
            'discount' => 'decimal:2',
            'vat_rate' => 'decimal:2',
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
     * Converts this quotation into a real, posted Sale.
     *
     * A quotation never captures a genuine payment method (matches legacy's
     * own reasoning - the field is agreed with the customer only once the
     * goods actually change hands), so the resulting sale always settles as
     * credit with a "full" tax invoice. Both the sale and this quotation's
     * own status update happen in one transaction via Sale::post()'s own
     * transaction plus this method's wrapping one.
     */
    public function convertToSale(User $actor): Sale
    {
        if ($this->status !== QuotationStatus::Draft) {
            throw new InvalidArgumentException('Only a draft quotation can be converted to a sale.');
        }

        $lines = $this->lines;

        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Cannot convert a quotation with no line items.');
        }

        return DB::transaction(function () use ($lines, $actor) {
            $sale = Sale::post(
                [
                    'customer_id' => $this->customer_id,
                    'invoice_type' => 'full',
                    'date' => now()->toDateString(),
                    'payment_mode' => 'credit',
                    'discount' => (float) $this->discount,
                    'vat_rate' => (float) $this->vat_rate,
                    'narration' => $this->narration ?? "Converted from quotation #{$this->id}",
                ],
                $lines->map(fn (QuotationLine $line) => [
                    'item_id' => $line->item_id,
                    'quantity' => (float) $line->quantity,
                    'rate' => (float) $line->rate,
                    'discount' => (float) $line->discount,
                ])->all(),
                $actor,
            );

            $this->update([
                'status' => QuotationStatus::Converted,
                'sale_id' => $sale->id,
            ]);

            return $sale;
        });
    }

    /**
     * Cancels a draft quotation the customer no longer wants. No ledger
     * reversal is needed since a draft quotation never posted anything.
     */
    public function cancel(): void
    {
        if ($this->status !== QuotationStatus::Draft) {
            throw new InvalidArgumentException('Only a draft quotation can be cancelled.');
        }

        $this->update(['status' => QuotationStatus::Cancelled]);
    }
}
