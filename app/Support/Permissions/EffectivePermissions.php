<?php

namespace App\Support\Permissions;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use WeakMap;

/**
 * The single place that turns "who is this user, what does their role say,
 * and what is this tenant entitled to" into a set of permission keys:
 *
 *     can(P) = tenant is entitled to module(P)
 *              AND user.is_active
 *              AND ( user.is_owner OR ( role.permissions contains P AND P is not owner_only ) )
 *
 * Entitlement applies to the owner too: the owner bypasses role checks,
 * never entitlements. Everything that asks "may this user do P?" (the Gate,
 * the Inertia `auth.can` share, the escalation guard) must read the result
 * of this class, never re-derive the formula, so the answers cannot drift.
 *
 * This class is pure computation over already-loaded state plus at most one
 * role query. Per-user memoization lives on the User instance
 * (User::effectivePermissions()), keyed by fingerprint() so edits to the
 * inputs made on the same instances invalidate the memo automatically.
 */
class EffectivePermissions
{
    /**
     * Per-tenant-instance cache of the entitled module set. Keyed by the
     * Tenant object itself (a WeakMap), so it can never serve one tenant's
     * modules to another in a long-lived worker (Octane, queue) and it is
     * garbage collected with the tenant instance, so it cannot grow without
     * bound. Each entry also stores the raw enabled_modules value it was
     * computed from, so a central edit on the same instance is seen at once.
     *
     * @var WeakMap<Tenant, array{raw: string, modules: array<string, true>}>|null
     */
    private static ?WeakMap $entitledCache = null;

    /**
     * The effective permission keys of the user in the current tenant, as a
     * flipped set (key => true) for O(1) lookups.
     *
     * Empty when tenancy is not initialized (central context has no tenant
     * permissions) or the user is inactive (a stale session of a deactivated
     * user must not keep working). The owner gets every catalog key of the
     * entitled modules, owner-only keys included. Anyone else gets their
     * role's keys intersected with the entitled keys, minus owner-only keys
     * (even if a role row somehow contains them), minus keys the catalog no
     * longer knows (removed keys are ignored on read, never fatal). A null
     * or missing role grants nothing.
     *
     * Prefer User::effectivePermissions(), which memoizes this.
     *
     * @return array<string, true>
     */
    public static function for(User $user): array
    {
        $tenant = self::currentTenant();

        if ($tenant === null || ! $user->isActive()) {
            return [];
        }

        $entitledKeys = array_fill_keys(
            PermissionCatalog::keysForModules(array_keys(self::entitledModuleSet($tenant))),
            true,
        );

        if ($user->isOwner()) {
            return $entitledKeys;
        }

        $granted = self::roleOf($user)?->permissions;

        if (! is_array($granted)) {
            return [];
        }

        $effective = [];
        foreach ($granted as $key) {
            if (! is_string($key) || ! isset($entitledKeys[$key]) || PermissionCatalog::isOwnerOnly($key)) {
                continue;
            }
            $effective[$key] = true;
        }

        return $effective;
    }

    /**
     * A string that changes whenever any input of for() changes on the
     * instances in hand: tenant, its raw enabled_modules, the user's active
     * and owner flags, the role id and the role's raw permissions JSON. The
     * User memo compares it on every read, so toggling a module or changing
     * role_id on the same instance mid-request never serves a stale set.
     *
     * Resolving the role here is what performs the (single) role query; the
     * loaded relation is reused afterwards, so repeated calls are free.
     */
    public static function fingerprint(User $user): string
    {
        $tenant = self::currentTenant();

        if ($tenant === null || ! $user->isActive()) {
            return 'none';
        }

        $prefix = $tenant->getTenantKey().'|'.self::rawEnabledModules($tenant);

        if ($user->isOwner()) {
            return 'owner|'.$prefix;
        }

        $role = self::roleOf($user);
        $rawPermissions = $role === null ? '' : self::rawString($role->getAttributes()['permissions'] ?? null);

        return 'role|'.$prefix.'|'.($role?->getKey() ?? '').'|'.$rawPermissions;
    }

    /**
     * Whether the current tenant is entitled to the given module. Used by
     * the Gate::before hook on every check, hence the per-instance cache.
     */
    public static function tenantHasModule(Tenant $tenant, string $module): bool
    {
        return isset(self::entitledModuleSet($tenant)[$module]);
    }

    /**
     * Drop the entitlement cache. Only needed by tests that swap
     * config('permissions') and call PermissionCatalog::flush(): the cache
     * is keyed by the tenant's raw column value, not by the catalog.
     */
    public static function flush(): void
    {
        self::$entitledCache = null;
    }

    /**
     * The initialized tenant, or null in central context.
     */
    public static function currentTenant(): ?Tenant
    {
        if (! tenancy()->initialized) {
            return null;
        }

        $tenant = tenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * The entitled module keys of the tenant as a flipped set, memoized per
     * tenant instance and revalidated against the raw column value.
     * Tenant::entitledModules() is deliberately not memoized itself (see its
     * docblock); the Gate calls this once per check, so caching the resolved
     * set here keeps a page with a hundred checks at one resolution.
     *
     * @return array<string, true>
     */
    private static function entitledModuleSet(Tenant $tenant): array
    {
        self::$entitledCache ??= new WeakMap;

        $raw = self::rawEnabledModules($tenant);
        $cached = self::$entitledCache[$tenant] ?? null;

        if ($cached !== null && $cached['raw'] === $raw) {
            return $cached['modules'];
        }

        $modules = array_fill_keys($tenant->entitledModules(), true);
        self::$entitledCache[$tenant] = ['raw' => $raw, 'modules' => $modules];

        return $modules;
    }

    /**
     * The enabled_modules value as currently held by the instance (dirty
     * values included), normalised to a string for cheap comparison.
     */
    private static function rawEnabledModules(Tenant $tenant): string
    {
        return self::rawString($tenant->getAttributes()['enabled_modules'] ?? null);
    }

    /**
     * Raw attribute values of JSON-cast columns are normally strings; this
     * only guards against an odd non-string sneaking in, without decoding.
     */
    private static function rawString(mixed $raw): string
    {
        if ($raw === null) {
            return 'null';
        }

        return is_string($raw) ? $raw : (string) json_encode($raw);
    }

    /**
     * The user's role, issuing at most one query per user instance: an
     * already-loaded relation is reused as long as it matches role_id. A
     * relation loaded as null is kept as is (fail closed, and no query per
     * check for a dangling role_id); User::flushEffectivePermissions() is
     * the way to force a reload.
     */
    private static function roleOf(User $user): ?Role
    {
        if ($user->role_id === null) {
            return null;
        }

        if ($user->relationLoaded('role')) {
            $role = $user->getRelation('role');

            if ($role === null || (string) $role->getKey() === (string) $user->role_id) {
                return $role;
            }

            $user->unsetRelation('role');
        }

        $user->load('role');

        return $user->getRelation('role');
    }
}
