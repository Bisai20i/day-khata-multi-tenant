<?php

namespace App\Models\Scopes;

use App\Support\Branches\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits a branch-owned model to the branches the acting user may access
 * (plan 12, invariant I6). Registered by the BelongsToBranch trait.
 *
 * Active only for a restricted user. For the owner, an all-branch user, code
 * with no user (console, queue) and anything inside
 * BranchContext::unrestricted(), it adds nothing at all, so those queries
 * are exactly what they were before branches existed. The predicate itself
 * comes from the model (BelongsToBranch::applyBranchAccessConstraint()), so
 * a model whose visibility is not just its own branch_id (a journal voucher
 * with lines in several branches, a transfer between two) can widen it.
 */
class BranchAccessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $branchIds = BranchContext::scopeBranchIds();

        if ($branchIds === null) {
            return;
        }

        $model->applyBranchAccessConstraint($builder, $branchIds);
    }
}
