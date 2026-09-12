<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `vatable` is what lets one capital purchase mix a VAT-bearing account with
 * an exempt one, the same way a Purchase mixes vatable and exempt items. Input
 * VAT is then computed from the vatable lines only, instead of being typed on
 * the document as a whole (audit P0-20).
 */
#[Fillable(['capital_purchase_id', 'account_id', 'narration', 'amount', 'vatable'])]
class CapitalPurchaseLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => Decimal::class.':2',
            'vatable' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CapitalPurchase, $this>
     */
    public function capitalPurchase(): BelongsTo
    {
        return $this->belongsTo(CapitalPurchase::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
