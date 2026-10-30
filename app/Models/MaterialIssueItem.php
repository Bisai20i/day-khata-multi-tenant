<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One raw material line of a Material Issue Note: the entered quantity and
 * unit, the base-unit quantity, and the cost the issue was relieved at.
 */
#[Fillable([
    'material_issue_note_id',
    'item_id',
    'item_unit_id',
    'quantity',
    'base_quantity',
    'unit_cost_rate',
    'line_cost',
    'stock_movement_id',
])]
class MaterialIssueItem extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => Decimal::class.':4',
            'base_quantity' => Decimal::class.':4',
            'unit_cost_rate' => Decimal::class.':4',
            'line_cost' => Decimal::class.':2',
        ];
    }

    /**
     * @return BelongsTo<MaterialIssueNote, $this>
     */
    public function materialIssueNote(): BelongsTo
    {
        return $this->belongsTo(MaterialIssueNote::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<ItemUnit, $this>
     */
    public function itemUnit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class);
    }

    /**
     * @return BelongsTo<ItemStockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(ItemStockMovement::class, 'stock_movement_id');
    }
}
