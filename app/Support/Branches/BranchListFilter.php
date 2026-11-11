<?php

namespace App\Support\Branches;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The branch half of a document list (plan 12, section 5.10, BR12): narrows
 * the list to the branch chosen in the top-bar switcher, and says whether the
 * list should show a branch column.
 *
 * Not a security boundary. BranchAccessScope already limits a restricted
 * user's rows to their branches; this only narrows further, and never widens.
 * The selection is read from the request's session through
 * ActiveBranchSelection (validated against the user's access there), never
 * from route-model binding or a query string, so a crafted URL cannot change
 * what a list shows.
 *
 * Parity: a tenant that is not multi-branch (module off, or only Main) has no
 * selection, so apply() adds no predicate and columnVisible() is false; the
 * list query and payload are exactly what they were.
 */
final class BranchListFilter
{
    /**
     * Narrows $query to the switcher's branch, or leaves it alone when the
     * user is looking at all branches or the tenant is not multi-branch.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $column  The branch column; qualified with the model's table unless it already names one.
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, Request $request, string $column = 'branch_id'): Builder
    {
        $branchId = ActiveBranchSelection::narrowedBranchId($request);

        if ($branchId !== null) {
            $query->where($query->qualifyColumn($column), $branchId);
        }

        return $query;
    }

    /**
     * Whether rows of more than one branch can appear in the list, so each
     * row should name its branch: a multi-branch tenant viewed as "All
     * branches". Narrowed to one branch, every row is in that branch and the
     * column would only repeat the switcher. The Vue side computes the same
     * thing from the shared `branch` prop (`branch.isMulti && branch.isAll`);
     * the server uses this to decide whether to load the branch names.
     */
    public static function columnVisible(Request $request): bool
    {
        return ActiveBranchSelection::isMultiBranch($request) && ActiveBranchSelection::viewingAll($request);
    }
}
