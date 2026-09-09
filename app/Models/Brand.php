<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Item brand/manufacturer master data - see BrandController's docblock for
 * the legacy gap this fills (day_khata's misleadingly-named
 * CompanyController, labelled "Add Item Brand" in its own UI). Tags
 * Item.brand_id and drives BrandWiseReportController's stock-by-brand
 * report, the same way ItemCategory tags Item.item_category_id and drives
 * CategoryWiseReportController. Entirely unrelated to CompanySetting, which
 * is the tenant's own business profile, not item master data.
 */
#[Fillable(['name', 'logo_path', 'is_active'])]
class Brand extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
