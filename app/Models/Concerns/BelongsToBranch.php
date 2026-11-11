<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\Scopes\BranchAccessScope;
use App\Support\Branches\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model that carries a branch_id (plan 12, sections 5.4 and 5.7).
 *
 * - Registers BranchAccessScope, which hides other branches' rows from a
 *   restricted user and is a no-op for everyone else.
 * - Stamps branch_id on create from BranchContext::postingBranchId(), but
 *   only when the caller left it unset. An explicit value is never
 *   overridden, so a document posted for another allowed branch, or a
 *   ledger line copied by a reversal, keeps the branch it was given.
 *
 * The column must be listed in the model's #[Fillable] as well: mass
 * assignment silently drops an unlisted column, and the stamp below would
 * then quietly replace the caller's branch with the context branch.
 */
trait BelongsToBranch
{
    protected static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchAccessScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('branch_id') === null) {
                $model->setAttribute('branch_id', BranchContext::postingBranchId());
            }
        });
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Rows of one branch. A report or list filter, not a security boundary:
     * the access scope still applies on top.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where($this->qualifyColumn('branch_id'), $branchId);
    }

    /**
     * Rows of any of the given branches. An empty list matches nothing.
     *
     * @param  Builder<static>  $query
     * @param  array<int, int>  $branchIds
     * @return Builder<static>
     */
    public function scopeForBranches(Builder $query, array $branchIds): Builder
    {
        return $query->whereIn($this->qualifyColumn('branch_id'), array_values($branchIds));
    }

    /**
     * The access predicate BranchAccessScope applies for a restricted user.
     * Models whose visibility is wider than their own branch_id override
     * this.
     *
     * @param  Builder<static>  $query
     * @param  list<int>  $branchIds
     */
    public function applyBranchAccessConstraint(Builder $query, array $branchIds): void
    {
        $query->whereIn($this->qualifyColumn('branch_id'), $branchIds);
    }
}
