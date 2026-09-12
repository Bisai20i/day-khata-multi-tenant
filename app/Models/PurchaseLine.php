<?php

namespace App\Models;

use App\Casts\Decimal;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'purchase_id', 'item_id', 'item_unit_id', 'account_id', 'quantity', 'unit_conversion_factor',
    'rate', 'discount', 'discount_type', 'vatable', 'line_total', 'net_value', 'vat_amount', 'tds_amount',
])]
class PurchaseLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => Decimal::class.':4',
            'unit_conversion_factor' => Decimal::class.':4',
            'rate' => Decimal::class.':4',
            'discount' => Decimal::class.':2',
            'vatable' => 'boolean',
            'line_total' => Decimal::class.':2',
            'net_value' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'tds_amount' => Decimal::class.':2',
        ];
    }

    /**
     * Quantity x rate, before any discount - the "gross" the bill prints.
     * Computed the same way DocumentCalculator computed it at posting (one
     * HalfUp rounding to 2 decimals), so the PDF can never disagree with the
     * stored line total by a paisa.
     */
    public function grossAmount(): Money
    {
        return Money::round(
            Quantity::of($this->quantity)->toBigDecimal()->multipliedBy(Quantity::of($this->rate)->toBigDecimal())
        );
    }

    /**
     * The rupees this line's own discount took off, whatever type it was.
     */
    public function discountAmount(): Money
    {
        return $this->grossAmount()->minus(Money::of($this->line_total));
    }

    /**
     * The unit the line was entered in: the alternate unit when one was chosen,
     * otherwise the item's own base unit.
     */
    public function unitName(): ?string
    {
        return $this->itemUnit?->name ?? $this->item?->unit;
    }

    /**
     * @return BelongsTo<Purchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * The alternate unit this line was entered in, if any - see SaleLine::
     * itemUnit() for the full rationale (mirrors it exactly).
     *
     * @return BelongsTo<ItemUnit, $this>
     */
    public function itemUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class);
    }

    /**
     * The expense or asset account this line actually debited when the bill
     * was posted. A debit note credits THIS account, never the item's current
     * account_id - re-pointing an item afterwards must not leave two accounts
     * permanently out by the returned amount.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return MorphMany<ItemStockMovement, $this>
     */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(ItemStockMovement::class, 'reference');
    }
}
