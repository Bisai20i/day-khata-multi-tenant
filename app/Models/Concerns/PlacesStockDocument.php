<?php

namespace App\Models\Concerns;

use App\Models\Store;
use App\Models\User;
use App\Support\Branches\DocumentBranch;
use InvalidArgumentException;

/**
 * Branch and store of a new stock or manufacturing document (stock
 * adjustment, stock conversion, material issue note, production entry;
 * plan 12, BR08, invariants I5 and I6).
 *
 * A thin wrapper around DocumentBranch::forStoreDocument() that keeps the
 * one default these documents have always had: with no store given they
 * used the lowest-id active store, not the company default store that
 * sales and purchases use. That store is still the default whenever it
 * belongs to the document's branch, which is always true for a
 * single-branch tenant, so a Main-only tenant files every stock document in
 * exactly the store it got before. For any other branch the lowest-id
 * active store is somebody else's, and DocumentBranch picks the branch's
 * own default store instead.
 *
 * Every failure is an InvalidArgumentException, which the stock and
 * manufacturing controllers already turn into a form error.
 */
trait PlacesStockDocument
{
    /**
     * @param  mixed  $branchId  The requested branch; null or '' means "not given".
     * @param  mixed  $storeId  The requested store; null or '' means "not given".
     *
     * @throws InvalidArgumentException
     */
    protected static function placeStockDocument(User $actor, mixed $branchId, mixed $storeId): DocumentBranch
    {
        if ($storeId !== null && $storeId !== '') {
            return DocumentBranch::forStoreDocument($actor, $branchId, $storeId);
        }

        // Resolves the branch (requested, else the actor's posting branch)
        // and checks the actor may use it, before any store is chosen.
        $branchId = DocumentBranch::forDocument($actor, $branchId)->branchId;

        $legacyDefault = Store::query()->where('is_active', true)->orderBy('id')->first(['id', 'branch_id']);

        if ($legacyDefault === null) {
            throw new InvalidArgumentException('No active store is configured.');
        }

        return DocumentBranch::forStoreDocument(
            $actor,
            $branchId,
            (int) $legacyDefault->branch_id === $branchId ? (int) $legacyDefault->id : null,
        );
    }
}
