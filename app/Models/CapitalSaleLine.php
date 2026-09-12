<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `vatable` is what lets one capital sale mix a VAT-bearing account with an
 * exempt one, the same way a Sale mixes vatable and exempt items. Output VAT
 * is then computed from the vatable lines only, instead of being typed on the
 * document as a whole (audit P0-20).
 */
#[Fillable(['capital_sale_id', 'account_id', 'narration', 'amount', 'vatable'])]
class CapitalSaleLine extends Model
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
     * @return BelongsTo<CapitalSale, $this>
     */
    public function capitalSale(): BelongsTo
    {
        return $this->belongsTo(CapitalSale::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
