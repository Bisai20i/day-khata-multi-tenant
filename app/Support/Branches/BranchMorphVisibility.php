<?php

namespace App\Support\Branches;

use App\Models\ActivityLog;
use App\Models\Purchase;
use App\Models\Scopes\BranchAccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Hides log rows that point (through a polymorphic type/id pair) at a
 * branch-scoped record the acting user cannot see (plan 12, section 5.7,
 * invariant I6). Used by the activity log and the print log, whose own
 * tables carry no branch column, so BranchAccessScope never reaches them.
 *
 * "Branch-scoped" means exactly "BranchAccessScope is registered on the
 * model": every BelongsToBranch model plus JournalVoucher and StockTransfer.
 * The predicate for each such type is the model's own
 * applyBranchAccessConstraint(), the same one the scope applies, so a voucher
 * visible through one of its lines or a transfer visible from either end is
 * visible here too, and nothing is invented on top.
 *
 * - Rows whose type is not branch-scoped (users, items, roles, settings...)
 *   stay visible.
 * - Rows whose type is branch-scoped are kept only when the target row still
 *   exists and passes the access predicate. Other global scopes (soft
 *   deletes) are left off the check, so a voided or deleted document of the
 *   user's own branch keeps its history; a hard-deleted target can no longer
 *   prove its branch and is hidden (fail closed).
 * - A type that no longer resolves to a model class cannot be classified and
 *   is hidden too (fail closed).
 *
 * - Activity-log rows with action 'price_updated' (subject: an open Item) also
 *   quote a purchase number and rate in their description and changes, so
 *   they are kept only when the purchase they reference is visible to the
 *   actor (see hideUnprovablePriceUpdates()).
 *
 * Parity: for the owner, an all-branch user, code with no user and anything
 * inside BranchContext::unrestricted(), scopeBranchIds() is null and apply()
 * adds nothing and runs no query, so the log is exactly what it was.
 */
final class BranchMorphVisibility
{
    /** The action ItemPurchasePriceUpdater writes; keep in sync with it. */
    private const PRICE_UPDATED_ACTION = 'price_updated';

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  A query on the log table itself.
     * @param  string  $typeColumn  The morph type column on the log table, unqualified.
     * @param  string  $idColumn  The morph id column on the log table, unqualified.
     * @param  string|null  $actionColumn  The action column on the log table, unqualified. Null means "action" for the activity log and no action-based rule for any other log table (the print log has no such column).
     * @param  string  $changesColumn  The JSON column holding the row's change payload, unqualified. Only read for the action-based rule.
     * @return Builder<TModel>
     */
    public static function apply(
        Builder $query,
        string $typeColumn,
        string $idColumn,
        ?string $actionColumn = null,
        string $changesColumn = 'changes',
    ): Builder {
        $branchIds = BranchContext::scopeBranchIds();

        if ($branchIds === null) {
            return $query;
        }

        $actionColumn ??= $query->getModel() instanceof ActivityLog ? 'action' : null;

        if ($actionColumn !== null) {
            self::hideUnprovablePriceUpdates($query, $actionColumn, $changesColumn, $branchIds);
        }

        $qualifiedType = $query->qualifyColumn($typeColumn);
        $qualifiedId = $query->qualifyColumn($idColumn);

        $openTypes = [];
        $scopedModels = [];

        // Every type that occurs in the table, read without any scope, so a
        // type is classified once rather than guessed from a hand-kept list.
        $types = $query->getModel()->newQueryWithoutScopes()
            ->distinct()
            ->pluck($typeColumn);

        foreach ($types as $type) {
            $type = (string) $type;
            $model = self::modelFor($type);

            if ($model === null) {
                continue;
            }

            if ($model::hasGlobalScope(BranchAccessScope::class)) {
                $scopedModels[$type] = $model;
            } else {
                $openTypes[] = $type;
            }
        }

        return $query->where(function (Builder $visible) use ($openTypes, $scopedModels, $qualifiedType, $qualifiedId, $branchIds): void {
            // whereIn() with an empty list matches nothing, which is right:
            // then only the per-type branches below can admit a row.
            $visible->whereIn($qualifiedType, $openTypes);

            foreach ($scopedModels as $type => $model) {
                $target = $model->newQueryWithoutScopes()
                    ->selectRaw('1')
                    ->whereColumn($model->getQualifiedKeyName(), $qualifiedId);

                $model->applyBranchAccessConstraint($target, $branchIds);

                $visible->orWhere(fn (Builder $ofType) => $ofType
                    ->where($qualifiedType, $type)
                    ->whereExists($target->toBase()));
            }
        });
    }

    /**
     * ItemPurchasePriceUpdater logs 'price_updated' against the (open, unscoped)
     * Item, but the row's description and changes name a purchase number and
     * rate that may belong to a branch the actor cannot see. Such a row is kept
     * only while the purchase referenced by changes->purchase_id still exists
     * and passes Purchase's own branch predicate. A missing, null, non-numeric
     * or dangling purchase id matches no purchase and the row is hidden (fail
     * closed). JSON extraction goes through the query grammar's
     * `column->key` path syntax, which compiles to json_extract() on both
     * SQLite and MySQL.
     *
     * @param  Builder<*>  $query
     * @param  list<int>  $branchIds
     */
    private static function hideUnprovablePriceUpdates(Builder $query, string $actionColumn, string $changesColumn, array $branchIds): void
    {
        $purchase = new Purchase;

        $target = $purchase->newQueryWithoutScopes()
            ->selectRaw('1')
            ->whereColumn($purchase->getQualifiedKeyName(), $query->qualifyColumn($changesColumn).'->purchase_id');

        $purchase->applyBranchAccessConstraint($target, $branchIds);

        $query->where(fn (Builder $visible) => $visible
            ->where($query->qualifyColumn($actionColumn), '!=', self::PRICE_UPDATED_ACTION)
            ->orWhereExists($target->toBase()));
    }

    /**
     * A fresh (and therefore booted, so its global scopes are registered)
     * instance of the model a morph type names, or null when it names none.
     */
    private static function modelFor(string $type): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $model = new $class;

        return $model::hasGlobalScope(BranchAccessScope::class) && ! method_exists($model, 'applyBranchAccessConstraint')
            ? null
            : $model;
    }
}
