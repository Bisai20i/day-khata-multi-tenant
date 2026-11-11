<?php

namespace App\Support\Branches;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The store dropdowns of a stock form (plan 12, sections 5.5 and 5.10, BR12):
 * which stores to offer and whether each one should name its branch.
 *
 * `Store` has no BranchAccessScope (BR10 checks store access explicitly), so
 * a plain `Store::where('is_active', true)` lists every branch's stores. In a
 * multi-branch company a store dropdown offers only the stores of the active
 * branches the actor may use, and each store carries its `branch_id` (always
 * selected) and, on request, its `branch` ({ id, name, code }) for a
 * "Store (Branch)" label.
 *
 * UI only, like BranchFormOptions: the posting path still checks the store's
 * branch itself (DocumentBranch, I5 and I6).
 *
 * Parity: a tenant that is not multi-branch (module off, or only Main) gets
 * the query back untouched, so the dropdown and the page payload are exactly
 * what they were.
 */
final class BranchStoreOptions
{
    /**
     * Narrows $query (a Store query) to the stores of the active branches the
     * signed-in user may use, and eager loads each store's branch name when
     * $withBranch is true. A no-op unless the company is multi-branch.
     *
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    public static function apply(Builder $query, Request $request, bool $withBranch = false): Builder
    {
        if (! ActiveBranchSelection::isMultiBranch($request)) {
            return $query;
        }

        $branchIds = array_column(ActiveBranchSelection::allowedBranches($request), 'id');

        return $query
            ->whereIn($query->qualifyColumn('branch_id'), $branchIds)
            ->when($withBranch, fn (Builder $query) => $query->with('branch:id,name,code'));
    }

    /**
     * $columns plus `branch_id` for a multi-branch company, for a store list
     * read with an explicit column list: the form needs each store's branch
     * to follow the branch picker. Unchanged otherwise (parity).
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function columns(Request $request, array $columns): array
    {
        if (! ActiveBranchSelection::isMultiBranch($request) || in_array('branch_id', $columns, true)) {
            return $columns;
        }

        return [...$columns, 'branch_id'];
    }
}
