<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'item_category_id',
    'item_subcategory_id',
    'account_id',
    'name',
    'description',
    'unit',
    'hs_code',
    'min_stock',
    'is_vatable',
    'is_stockable',
    'is_active',
])]
class Item extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_stock' => 'decimal:2',
            'is_vatable' => 'boolean',
            'is_stockable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ItemCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    /**
     * @return BelongsTo<ItemSubcategory, $this>
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ItemSubcategory::class, 'item_subcategory_id');
    }

    /**
     * The inventory/COGS ledger account this item posts against, if set.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
