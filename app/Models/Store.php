<?php

namespace App\Models;

use App\Support\Branches\BranchRuleViolation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'branch_id', 'address', 'phone', 'is_active'])]
class Store extends Model
{
    use HasFactory;

    /**
     * Every table column that points at a store. A store with a row in any of
     * them is "in use" and its branch is frozen (plan 12, invariant I5).
     *
     * @var array<string, list<string>>
     */
    public const USAGE_COLUMNS = [
        'item_stock_movements' => ['store_id'],
        'sales' => ['store_id'],
        'purchases' => ['store_id'],
        'sales_returns' => ['store_id'],
        'purchase_returns' => ['store_id'],
        'stock_adjustments' => ['store_id'],
        'stock_conversions' => ['store_id'],
        'stock_transfers' => ['from_store_id', 'to_store_id'],
        'capital_purchases' => ['store_id'],
        'capital_sales' => ['store_id'],
        'material_issue_notes' => ['store_id'],
        'production_entries' => ['store_id'],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $store): void {
            if ($store->branch_id === null) {
                $store->branch_id = Branch::main()->id;
            }
        });

        static::updating(function (self $store): void {
            if (! $store->isDirty('branch_id')) {
                return;
            }

            // Lock the row before the usage check, so it is the first read of
            // the transaction save() opened (MySQL takes its snapshot at the
            // first plain SELECT): a document committed by a concurrent
            // posting is then visible to the check. A document whose insert
            // is still running holds the foreign-key share lock on this row,
            // so the lock waits for it instead of missing it.
            $current = DB::table('stores')->where('id', $store->getKey())->lockForUpdate()->first(['branch_id']);

            if ($current !== null && (int) $current->branch_id === (int) $store->branch_id) {
                return;
            }

            if ($store->hasMovementsOrDocuments()) {
                throw BranchRuleViolation::storeBranchIsLocked((string) $store->name);
            }
        });
    }

    /**
     * A branch change runs in its own transaction, so the row lock the
     * updating hook takes holds until the new branch is written (invariant
     * I5). Joins the caller's transaction when there is one.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isDirty('branch_id')) {
            return DB::transaction(fn (): bool => parent::save($options));
        }

        return parent::save($options);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Whether any stock movement or store-bearing document references this
     * store, in which case its branch can no longer change.
     */
    public function hasMovementsOrDocuments(): bool
    {
        if (! $this->exists) {
            return false;
        }

        foreach (self::USAGE_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->where($column, $this->getKey())->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}
