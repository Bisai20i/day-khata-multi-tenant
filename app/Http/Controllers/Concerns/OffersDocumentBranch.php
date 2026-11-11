<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Branch;
use App\Models\Store;
use App\Models\User;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchFormOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The two branch details the sales-side document pages need beyond
 * BranchFormOptions (plan 12, sections 5.5 and 5.10, BR12b): a store
 * dropdown that knows each store's branch, and the branch line printed on a
 * document of a multi-branch company.
 *
 * Parity: for a company that is not multi-branch (module off, or only Main)
 * both answers are exactly what the pages had before branches existed: the
 * store list is the same `id, name` query and no branch is printed.
 */
trait OffersDocumentBranch
{
    /**
     * Active stores for a document's store dropdown, ordered by name.
     *
     * Multi-branch: each store also carries `branch_id` (so the form can
     * offer only the chosen branch's stores, `useBranchField().storesForBranch`)
     * and `is_branch_default` (the store the form re-selects when the branch
     * changes, matching what DocumentBranch would default to). Only stores of
     * branches the user may file into are offered, so a branch-restricted
     * user is never shown a store the posting would refuse. UI only: the
     * posting still checks the store against the branch (I5) and the actor
     * (I6).
     *
     * @return Collection<int, mixed>
     */
    protected function branchStoreOptions(Request $request): Collection
    {
        $query = Store::query()->where('is_active', true)->orderBy('name');

        if (! ActiveBranchSelection::isMultiBranch($request) || ! $request->user() instanceof User) {
            return $query->get(['id', 'name']);
        }

        $branchIds = array_column(BranchFormOptions::choices($request->user(), $request), 'id');

        $branchDefaults = Branch::query()
            ->whereIn('id', $branchIds)
            ->whereNotNull('default_store_id')
            ->pluck('default_store_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $query->whereIn('branch_id', $branchIds)
            ->get(['id', 'name', 'branch_id'])
            ->map(fn (Store $store): array => [
                'id' => $store->id,
                'name' => $store->name,
                'branch_id' => (int) $store->branch_id,
                'is_branch_default' => in_array($store->id, $branchDefaults, true),
            ]);
    }

    /**
     * The branch line of a printed document: the document's branch name and
     * the company's word for a branch, only for a multi-branch company. A
     * single-branch company gets a null name, so the views print nothing new
     * and the PDF stays byte-identical.
     *
     * @return array{branchName: ?string, branchLabel: string}
     */
    protected function printBranch(Request $request, Model $document): array
    {
        if (! ActiveBranchSelection::isMultiBranch($request)) {
            return ['branchName' => null, 'branchLabel' => 'Branch'];
        }

        $document->loadMissing('branch:id,name');

        return [
            'branchName' => $document->getRelation('branch')?->name,
            'branchLabel' => ActiveBranchSelection::label(),
        ];
    }
}
