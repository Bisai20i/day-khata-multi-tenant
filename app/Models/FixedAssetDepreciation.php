<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per fixed asset per fiscal year: the charge
 * App\Models\FixedAsset::postDepreciationForAsset() posted, alongside the
 * opening and closing written-down values it was measured between. The money
 * columns use the App\Casts\Decimal cast rather than `decimal:2`, so a value
 * carrying more than two decimals throws at the line that produced it
 * instead of being silently rounded on insert (CONTRACTS C2).
 */
#[Fillable([
    'fixed_asset_id', 'fiscal_year_id', 'journal_voucher_id', 'posted_date',
    'opening_wdv', 'depreciation_amount', 'closing_wdv',
])]
class FixedAssetDepreciation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posted_date' => 'date',
            'opening_wdv' => Decimal::class.':2',
            'depreciation_amount' => Decimal::class.':2',
            'closing_wdv' => Decimal::class.':2',
        ];
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }
}
