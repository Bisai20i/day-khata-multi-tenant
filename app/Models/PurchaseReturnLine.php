<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One returned line of a debit note.
 *
 * `net_value` is the load-bearing amount: the rupees this line credited back
 * after the original bill's line AND header discounts, excluding VAT. It is
 * what CONTRACTS C6 cuts every further return's share from, so a second and
 * third partial return of the same purchase line can never credit a paisa more
 * or less than the line was worth. `line_total` holds the same figure for
 * display; rows posted before that rule existed keep their historical value in
 * `line_total` and carry the recovered one in `net_value`.
 */
#[Fillable([
    'purchase_return_id', 'purchase_line_id', 'quantity', 'rate',
    'line_total', 'net_value', 'vat_amount', 'tds_amount',
])]
class PurchaseReturnLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => Decimal::class.':4',
            'rate' => Decimal::class.':4',
            'line_total' => Decimal::class.':2',
            'net_value' => Decimal::class.':2',
            'vat_amount' => Decimal::class.':2',
            'tds_amount' => Decimal::class.':2',
        ];
    }

    /**
     * @return BelongsTo<PurchaseReturn, $this>
     */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    /**
     * @return BelongsTo<PurchaseLine, $this>
     */
    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class);
    }
}
