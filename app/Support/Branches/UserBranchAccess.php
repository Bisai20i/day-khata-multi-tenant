<?php

namespace App\Support\Branches;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Which branches a user may use, edited from the users page (plan 12,
 * sections 5.7 and 5.10, BR10).
 *
 * Three fields: `all_branches` (every branch, today's behaviour and the
 * default), `branch_ids` (the assigned subset, used only when all_branches is
 * off) and `default_branch_id` (where new records are filed when the user
 * picks nothing; BranchContext::postingBranchId() validates it on every read,
 * so a stale default is harmless).
 *
 * The same escalation rule as roles in UserController: holding users.manage
 * lets someone hand out only the branch access they hold themselves. A
 * restricted actor cannot give "all branches", cannot assign a branch they
 * cannot use, cannot change the access of someone who reaches further than
 * they do, and nobody but the owner changes their own access. The owner is
 * always unrestricted (BranchContext ignores all_branches for them), so the
 * owner row only takes a default branch.
 *
 * Applied only while the tenant is entitled to the `branches` module and the
 * request carries `all_branches`; otherwise the stored access is left alone,
 * so the users page keeps working unchanged for single-branch tenants.
 */
final class UserBranchAccess
{
    /**
     * Shape-only rules, merged into the users page's own validation. The
     * meaning (active branches, default inside the set, reach) is checked by
     * apply(), against the locked target row.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'all_branches' => ['sometimes', 'boolean'],
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['integer', 'distinct'],
            'default_branch_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * Whether the users page shows and accepts branch access at all.
     */
    public static function enabled(): bool
    {
        $tenant = function_exists('tenant') ? tenant() : null;

        return $tenant !== null && method_exists($tenant, 'hasModule') && $tenant->hasModule('branches');
    }

    /**
     * Whether $actor's branch reach covers $target's CURRENT access: the owner
     * and unrestricted actors reach everyone; a restricted actor reaches only
     * users limited to branches inside their own allowed set (never an
     * all-branches user or the owner). The users page checks it for every
     * edit of another user, not only when branch access changes, so a
     * restricted admin cannot take over a wider account by resetting its
     * email or password. Uses the loaded `branches` relation when present.
     */
    public static function actorReachesUser(User $actor, User $target): bool
    {
        if ($actor->isOwner()) {
            return true;
        }

        $reach = BranchContext::allowedBranchIds($actor);

        if ($reach === null) {
            return true;
        }

        if ($target->isOwner() || $target->getAttribute('all_branches') !== false) {
            return false;
        }

        $ids = $target->relationLoaded('branches')
            ? $target->branches->pluck('id')->all()
            : $target->branches()->pluck('branches.id')->all();

        return array_diff(self::normalisedIds($ids), $reach) === [];
    }

    /**
     * The user's access as the users page edits it.
     *
     * @return array{all_branches: bool, branch_ids: list<int>, default_branch_id: int|null}
     */
    public static function forUser(User $user): array
    {
        return [
            'all_branches' => $user->isOwner() || $user->getAttribute('all_branches') !== false,
            'branch_ids' => $user->branches->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            'default_branch_id' => $user->default_branch_id === null ? null : (int) $user->default_branch_id,
        ];
    }

    /**
     * Branch choices for the users page, Main first then the admin's order.
     * Inactive branches are listed (flagged) so an existing assignment can
     * still be seen, but apply() refuses to add one.
     *
     * @return list<array{id: int, name: string, code: string, is_main: bool, is_active: bool}>
     */
    public static function options(): array
    {
        return Branch::query()
            ->orderByDesc('is_main')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_main', 'is_active'])
            ->map(fn (Branch $branch): array => [
                'id' => (int) $branch->id,
                'name' => (string) $branch->name,
                'code' => (string) $branch->code,
                'is_main' => (bool) $branch->is_main,
                'is_active' => (bool) $branch->is_active,
            ])
            ->all();
    }

    /**
     * Checks and writes the submitted access onto $target. Call inside the
     * caller's transaction after the target row is locked (or created) and
     * saved. Flush BranchContext after the transaction commits: sync() fires
     * no model event, so nothing else would drop the cached allowed set.
     *
     * A new user created by a restricted actor without branch fields (the
     * module is off, or an older client) inherits the actor's own branches,
     * never the column default of "all branches".
     *
     * @param  array<string, mixed>  $data  Validated request data.
     * @param  bool  $isNew  $target was created by this same request.
     *
     * @throws ValidationException
     */
    public static function apply(User $actor, User $target, array $data, bool $isNew = false): void
    {
        if (! self::enabled() || ! array_key_exists('all_branches', $data)) {
            $reach = $actor->isOwner() ? null : BranchContext::allowedBranchIds($actor);

            if ($isNew && $reach !== null) {
                $target->all_branches = false;
                $target->default_branch_id = null;
                $target->save();
                $target->branches()->sync($reach);
            }

            return;
        }

        $wantsAll = (bool) $data['all_branches'];
        $wantedIds = self::normalisedIds($data['branch_ids'] ?? []);
        $defaultId = isset($data['default_branch_id']) ? (int) $data['default_branch_id'] : null;

        if ($target->isOwner()) {
            if (! $wantsAll) {
                throw ValidationException::withMessages(['all_branches' => 'The owner always has access to every branch.']);
            }

            $wantedIds = [];
        }

        if ($wantsAll) {
            $wantedIds = [];
        } elseif ($wantedIds === []) {
            throw ValidationException::withMessages(['branch_ids' => 'Choose at least one branch, or give access to all branches.']);
        }

        $current = self::currentAccess($target);
        $previousDefault = $isNew || $target->default_branch_id === null ? null : (int) $target->default_branch_id;

        // Only what is newly granted must be active: an existing assignment
        // to a branch deactivated since then does not block other edits.
        self::assertBranchesUsable(
            $isNew ? $wantedIds : array_values(array_diff($wantedIds, $current['ids'])),
            $defaultId === $previousDefault ? null : $defaultId,
        );

        if ($defaultId !== null && ! $wantsAll && ! in_array($defaultId, $wantedIds, true)) {
            throw ValidationException::withMessages(['default_branch_id' => 'The default branch must be one of the branches this user can use.']);
        }

        $changesAccess = $isNew || $current['all'] !== $wantsAll || $current['ids'] !== $wantedIds;

        if ($changesAccess) {
            self::assertActorMayChange($actor, $target, $isNew ? null : $current, $wantsAll, $wantedIds);
        }

        $target->all_branches = $wantsAll;
        $target->default_branch_id = $defaultId;
        $target->save();

        if ($changesAccess) {
            $target->branches()->sync($wantedIds);
        }
    }

    /**
     * @param  list<int>  $grantedIds  Branches being newly granted.
     * @param  int|null  $newDefaultId  The default branch when it changes, else null.
     *
     * @throws ValidationException
     */
    private static function assertBranchesUsable(array $grantedIds, ?int $newDefaultId): void
    {
        $lookupIds = array_values(array_unique([...$grantedIds, ...($newDefaultId === null ? [] : [$newDefaultId])]));
        $active = $lookupIds === []
            ? []
            : Branch::query()->whereIn('id', $lookupIds)->where('is_active', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if (array_diff($grantedIds, $active) !== []) {
            throw ValidationException::withMessages(['branch_ids' => 'Only active branches can be assigned.']);
        }

        if ($newDefaultId !== null && ! in_array($newDefaultId, $active, true)) {
            throw ValidationException::withMessages(['default_branch_id' => 'The default branch must be an active branch.']);
        }
    }

    /**
     * The escalation guard (class docblock). The owner may change anyone.
     *
     * @param  array{all: bool, ids: list<int>}|null  $current  Null for a user created by this request.
     * @param  list<int>  $wantedIds
     *
     * @throws ValidationException
     */
    private static function assertActorMayChange(User $actor, User $target, ?array $current, bool $wantsAll, array $wantedIds): void
    {
        if ($actor->isOwner()) {
            return;
        }

        if ($current !== null && (string) $actor->getKey() === (string) $target->getKey()) {
            throw ValidationException::withMessages(['all_branches' => 'You cannot change your own branch access.']);
        }

        $reach = BranchContext::allowedBranchIds($actor);

        if ($reach === null) {
            return;
        }

        $withinReach = fn (bool $all, array $ids): bool => ! $all && array_diff($ids, $reach) === [];

        if ($current !== null && ! $withinReach($current['all'], $current['ids'])) {
            throw ValidationException::withMessages([
                'all_branches' => "{$target->name} has access to branches you cannot use, so you cannot change their branch access.",
            ]);
        }

        if (! $withinReach($wantsAll, $wantedIds)) {
            throw ValidationException::withMessages([
                $wantsAll ? 'all_branches' : 'branch_ids' => 'You can only give access to branches you can use yourself.',
            ]);
        }
    }

    /**
     * Read from the database, not from loaded relations, so the comparison
     * sees what is stored now.
     *
     * @return array{all: bool, ids: list<int>}
     */
    private static function currentAccess(User $target): array
    {
        $all = $target->isOwner() || $target->getAttribute('all_branches') !== false;

        return [
            'all' => $all,
            'ids' => $all ? [] : self::normalisedIds($target->branches()->pluck('branches.id')->all()),
        ];
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return list<int>
     */
    private static function normalisedIds(iterable $ids): array
    {
        $normalised = [];

        foreach ($ids as $id) {
            $normalised[] = (int) $id;
        }

        $normalised = array_values(array_unique($normalised));
        sort($normalised);

        return $normalised;
    }
}
