<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A display/pricing sub-record under an Item (e.g. "Red / Large"). Deliberately
 * NOT a separate stock-tracked entity - stock movements stay scoped to the
 * parent Item only, see Item::recordStockMovement(). price_adjustment is
 * added to/subtracted from the parent item's rate for this specific variant.
 */
#[Fillable(['item_id', 'name', 'sku_suffix', 'price_adjustment', 'is_active'])]
class ItemVariety extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_adjustment' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
