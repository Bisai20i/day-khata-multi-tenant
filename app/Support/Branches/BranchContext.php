<?php

namespace App\Support\Branches;

use App\Models\Branch;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;

/**
 * Which branch the current request or job acts in, and which branches its
 * user may see (plan 12, section 5.7).
 *
 * Two separate ideas live here and must not be confused:
 *
 * - The allowed set is security. allowedBranchIds() is null for an
 *   unrestricted user (the owner, or anyone with all_branches on), which
 *   BranchAccessScope reads as "add no predicate at all", so the common path
 *   has zero overhead and zero behavior change. A restricted user gets the
 *   active branches they are assigned to.
 * - The active branch is a UI choice (the top-bar switcher, BR11) and only
 *   narrows; it is never trusted without being checked against the allowed
 *   set. postingBranchId() turns it into the branch new records are filed
 *   under: active selection, then the user's default branch, then Main, then
 *   the user's first allowed branch, always validated.
 *
 * System code that needs the whole truth (year close, audits, reversal and
 * roll-forward lookups, numbering) runs inside unrestricted(); a job acting
 * for a particular user runs inside restrictedTo(). Both are frames on a
 * stack, so they nest and always restore what was there before, even when
 * the callback throws.
 *
 * Lifetime: #[Scoped], so the container keeps one instance per request and
 * the queue worker discards it after every job (forgetScopedInstances). A
 * single process can still switch tenants inside one lifetime (tests,
 * tenants:run), so every cached fact is filed under the tenant it was read
 * from and dropped the moment a different tenant is current. With no
 * authenticated tenant User (console, queue, central routes) nothing is
 * restricted. Cached facts are read once per lifetime; code that changes
 * branches or a user's branch access mid-request calls flush().
 */
#[Scoped]
final class BranchContext
{
    /**
     * Tenant key the cached facts below were read under.
     */
    private ?string $cachedForTenant = null;

    private ?int $mainBranchId = null;

    /**
     * Whether the Main lookup has run, so a tenant without Main is not
     * queried again on every call.
     */
    private bool $mainLookedUp = false;

    /**
     * user id => allowed active branch ids, restricted users only.
     *
     * @var array<int, list<int>>
     */
    private array $allowedByUser = [];

    /**
     * user id => Main's id when Main is in that restricted user's allowed
     * set, else null.
     *
     * @var array<int, int|null>
     */
    private array $allowedMainByUser = [];

    /**
     * branch id => whether it is active.
     *
     * @var array<int, bool>
     */
    private array $activeByBranch = [];

    /**
     * The UI selection (null means "All branches" or nothing chosen).
     */
    private ?int $selectedBranchId = null;

    /**
     * Innermost last. Each frame either lifts the access scope or pins the
     * acting user.
     *
     * @var list<array{unrestricted: bool, user: User|null}>
     */
    private array $frames = [];

    public static function current(): self
    {
        return app(self::class);
    }

    /**
     * Runs $callback with the access scope switched off, for system code that
     * must see every branch. The scope comes back on afterwards, even when
     * $callback throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function unrestricted(Closure $callback): mixed
    {
        return self::current()->withFrame(['unrestricted' => true, 'user' => null], $callback);
    }

    /**
     * Runs $callback as $user: the access scope and the posting branch follow
     * $user's branch access, even inside an outer unrestricted() block or in
     * a queued job with nobody logged in.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function restrictedTo(User $user, Closure $callback): mixed
    {
        return self::current()->withFrame(['unrestricted' => false, 'user' => $user], $callback);
    }

    /**
     * Whether the access scope is switched off right now: inside
     * unrestricted(), or for an unrestricted or absent user.
     */
    public static function isUnrestricted(): bool
    {
        return self::scopeBranchIds() === null;
    }

    /**
     * Whether the innermost frame is an unrestricted() block, i.e. system
     * code has deliberately lifted branch access checks, whoever the user is.
     */
    public static function accessIsLifted(): bool
    {
        return self::current()->innermostFrame()['unrestricted'] ?? false;
    }

    /**
     * The branch ids BranchAccessScope limits queries to, or null for no
     * limit at all.
     *
     * @return list<int>|null
     */
    public static function scopeBranchIds(): ?array
    {
        $context = self::current();
        $frame = $context->innermostFrame();

        if ($frame !== null && $frame['unrestricted']) {
            return null;
        }

        return $context->allowedFor($context->contextUser());
    }

    /**
     * The branches $user may read and post in (defaults to the context user),
     * or null when they may use every branch. Ignores unrestricted() frames:
     * this answers a question about the user, not about the running code.
     *
     * @return list<int>|null
     */
    public static function allowedBranchIds(?User $user = null): ?array
    {
        $context = self::current();

        return $context->allowedFor($user ?? $context->contextUser());
    }

    public static function canAccessBranch(int $branchId, ?User $user = null): bool
    {
        $allowed = self::allowedBranchIds($user);

        return $allowed === null ? self::current()->isActiveBranch($branchId) : in_array($branchId, $allowed, true);
    }

    /**
     * Record the user's UI branch selection (BR11 middleware). Null means
     * "All branches". Not validated here: activeBranchId() checks it on read,
     * against whoever the context user is at that moment.
     */
    public static function setActiveBranchId(?int $branchId): void
    {
        $context = self::current();

        // File the selection under the current tenant first, so the next
        // cached read does not mistake it for another tenant's and drop it.
        $context->refreshForTenant();
        $context->selectedBranchId = $branchId;
    }

    /**
     * The UI branch selection when the context user may use it, else null
     * ("All branches", or a stale or forged selection).
     */
    public static function activeBranchId(): ?int
    {
        $context = self::current();

        // Before reading the selection: a selection made under another tenant
        // in this same process must not answer for this one.
        $context->refreshForTenant();
        $selected = $context->selectedBranchId;

        return $selected !== null && self::canAccessBranch($selected) ? $selected : null;
    }

    /**
     * The branch a new record is filed under when the caller names none.
     *
     * Order: the UI selection (only when $user is the context user, since the
     * selection belongs to that session), then $user's default branch, then
     * Main, then $user's first allowed branch. Every candidate must be active
     * and allowed for $user. A NULL default_branch_id (every user created
     * after the branches migration) therefore lands on Main whenever Main is
     * allowed. With no user at all (console, queue) the answer is Main.
     *
     * Main is resolved only when the earlier candidates fail (and, for a
     * restricted user, read from their allowed set rather than looked up), so
     * a valid selection or default never depends on the Main lookup.
     *
     * @throws BranchRuleViolation When a restricted user has no usable branch.
     */
    public static function postingBranchId(?User $user = null): int
    {
        $context = self::current();
        $context->refreshForTenant();
        $contextUser = $context->contextUser();
        $user ??= $contextUser;

        $selected = $user !== null && $contextUser !== null && $user->is($contextUser)
            ? $context->selectedBranchId
            : null;

        $default = $user?->getAttribute('default_branch_id');

        foreach ([$selected, $default === null ? null : (int) $default] as $candidate) {
            if ($candidate !== null && self::canAccessBranch($candidate, $user)) {
                return $candidate;
            }
        }

        $allowed = $context->allowedFor($user);

        // Unrestricted (or no user): Main, which is always active.
        if ($allowed === null) {
            return $context->mainId();
        }

        $user = $user ?? throw BranchRuleViolation::noUsableBranch();
        $allowedMain = $context->allowedMainFor($user);

        if ($allowedMain !== null) {
            return $allowedMain;
        }

        if ($allowed === []) {
            throw BranchRuleViolation::noUsableBranch();
        }

        return $allowed[0];
    }

    public static function mainBranchId(): int
    {
        return self::current()->mainId();
    }

    /**
     * Main's id, or null when the tenant has none (only possible in a broken
     * or half-provisioned tenant). For callers that merely compare against
     * Main and must not fail because of it.
     */
    public static function mainBranchIdOrNull(): ?int
    {
        return self::current()->mainIdOrNull();
    }

    /**
     * The user the context acts for: the innermost restrictedTo() user, else
     * the authenticated tenant User. Null in console and queue code and on
     * central routes (a PlatformAdmin is not a tenant User).
     */
    public static function user(): ?User
    {
        return self::current()->contextUser();
    }

    /**
     * Forget every cached fact (Main id, allowed sets, active flags) and the
     * UI selection. Call after creating or (de)activating a branch or
     * changing a user's branch access when the same request reads the
     * context again.
     */
    public static function flush(): void
    {
        $context = self::current();
        $context->resetCache($context->tenantKey());
        $context->selectedBranchId = null;
    }

    /**
     * @template TReturn
     *
     * @param  array{unrestricted: bool, user: User|null}  $frame
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withFrame(array $frame, Closure $callback): mixed
    {
        $this->frames[] = $frame;
        $depth = count($this->frames);

        try {
            return $callback();
        } finally {
            // Restore exactly the stack that was there before, even if the
            // callback left a frame of its own behind.
            $this->frames = array_slice($this->frames, 0, $depth - 1);
        }
    }

    /**
     * @return array{unrestricted: bool, user: User|null}|null
     */
    private function innermostFrame(): ?array
    {
        return $this->frames === [] ? null : $this->frames[count($this->frames) - 1];
    }

    private function contextUser(): ?User
    {
        // An unrestricted() frame carries no user, so a restrictedTo() user
        // further out still names who is acting.
        for ($index = count($this->frames) - 1; $index >= 0; $index--) {
            if ($this->frames[$index]['user'] !== null) {
                return $this->frames[$index]['user'];
            }
        }

        if (! app()->bound('auth')) {
            return null;
        }

        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Null means unrestricted. The owner always is; anyone else is unless
     * all_branches is explicitly false. all_branches is NOT NULL DEFAULT
     * true, so a null here only means "not loaded on this instance" (a
     * freshly created model), which is unrestricted, the same reading
     * User::isActive() gives is_active. No user at all is unrestricted too.
     *
     * @return list<int>|null
     */
    private function allowedFor(?User $user): ?array
    {
        if ($user === null || $user->isOwner() || $user->getAttribute('all_branches') !== false) {
            return null;
        }

        $this->refreshForTenant();
        $userId = $user->getKey();

        if (! array_key_exists($userId, $this->allowedByUser)) {
            $rows = DB::table('branch_user')
                ->join('branches', 'branches.id', '=', 'branch_user.branch_id')
                ->where('branch_user.user_id', $userId)
                ->where('branches.is_active', true)
                ->orderBy('branches.sort_order')
                ->orderBy('branches.id')
                ->get(['branches.id', 'branches.is_main']);

            $this->allowedByUser[$userId] = $rows->map(fn (object $row): int => (int) $row->id)->values()->all();
            $main = $rows->first(fn (object $row): bool => (bool) $row->is_main);
            $this->allowedMainByUser[$userId] = $main === null ? null : (int) $main->id;
        }

        return $this->allowedByUser[$userId];
    }

    /**
     * Main's id when it is in $user's allowed set, else null. Read from the
     * same row set as allowedFor(), so it never needs the Main lookup.
     */
    private function allowedMainFor(User $user): ?int
    {
        $this->allowedFor($user);

        return $this->allowedMainByUser[$user->getKey()] ?? null;
    }

    private function isActiveBranch(int $branchId): bool
    {
        $this->refreshForTenant();

        // Main can never be deactivated (Branch::booted()). Only used as a
        // shortcut once Main is known; never looked up just for this.
        if ($this->mainBranchId !== null && $branchId === $this->mainBranchId) {
            return true;
        }

        if (! array_key_exists($branchId, $this->activeByBranch)) {
            // One row read answers "active?" and, when it is Main, also
            // caches Main's id, so a user whose default is Main costs a
            // single query per lifetime, not two.
            $branch = Branch::query()->whereKey($branchId)->first(['id', 'is_active', 'is_main']);
            $this->activeByBranch[$branchId] = $branch !== null && $branch->is_active;

            if ($branch !== null && $branch->is_main) {
                $this->mainBranchId = (int) $branch->id;
            }
        }

        return $this->activeByBranch[$branchId];
    }

    private function mainId(): int
    {
        // Branch::main() throws ModelNotFoundException for a tenant with no
        // Main, which is the honest answer when Main is really needed.
        return $this->mainIdOrNull() ?? (int) Branch::main()->getKey();
    }

    private function mainIdOrNull(): ?int
    {
        $this->refreshForTenant();

        if ($this->mainBranchId === null && ! $this->mainLookedUp) {
            $id = Branch::query()->where('is_main', true)->value('id');
            $this->mainBranchId = $id === null ? null : (int) $id;
            $this->mainLookedUp = true;
        }

        return $this->mainBranchId;
    }

    /**
     * Drops the cache when the current tenant is not the one it was read
     * under, so one tenant's branch ids never answer for another's.
     */
    private function refreshForTenant(): void
    {
        $tenantKey = $this->tenantKey();

        if ($tenantKey !== $this->cachedForTenant) {
            $this->resetCache($tenantKey);
            $this->selectedBranchId = null;
        }
    }

    private function resetCache(?string $tenantKey): void
    {
        $this->cachedForTenant = $tenantKey;
        $this->mainBranchId = null;
        $this->mainLookedUp = false;
        $this->allowedByUser = [];
        $this->allowedMainByUser = [];
        $this->activeByBranch = [];
    }

    private function tenantKey(): ?string
    {
        $tenant = function_exists('tenant') ? tenant() : null;

        return $tenant === null ? null : (string) $tenant->getTenantKey();
    }
}
