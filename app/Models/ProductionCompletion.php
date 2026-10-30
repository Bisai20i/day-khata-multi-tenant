<?php

namespace App\Models;

use App\Casts\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One transfer of finished units out of work in process into Finished Goods
 * inventory. Partial output posts several of these; `is_final` closes the
 * entry and takes the whole remaining WIP so none is orphaned.
 */
#[Fillable([
    'production_entry_id',
    'date',
    'quantity',
    'cost',
    'unit_cost',
    'is_final',
    'stock_movement_id',
    'journal_voucher_id',
    'cancelled_by',
    'cancelled_at',
    'cancel_reason',
])]
class ProductionCompletion extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'quantity' => Decimal::class.':4',
            'cost' => Decimal::class.':2',
            'unit_cost' => Decimal::class.':4',
            'is_final' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductionEntry, $this>
     */
    public function productionEntry(): BelongsTo
    {
        return $this->belongsTo(ProductionEntry::class);
    }

    /**
     * @return BelongsTo<ItemStockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(ItemStockMovement::class, 'stock_movement_id');
    }

    /**
     * @return BelongsTo<JournalVoucher, $this>
     */
    public function journalVoucher(): BelongsTo
    {
        return $this->belongsTo(JournalVoucher::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
