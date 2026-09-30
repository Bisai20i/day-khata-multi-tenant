<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions\EffectivePermissions;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the permission catalog into Laravel's Gate, so routes can use the
 * native `can:<permission>` middleware and code can call $user->can().
 *
 * One Gate::define per catalog key, each delegating to
 * User::hasPermission() (which applies the full formula, see
 * EffectivePermissions), plus one Gate::before that short-circuits the two
 * denials that must hold regardless of any define: inactive user, and a
 * catalog key whose module the tenant is not entitled to (owner included).
 *
 * Scope rules, so this never disturbs the central panel:
 * - Outside an initialized tenant, or for any authenticatable that is not a
 *   tenant App\Models\User (e.g. a central PlatformAdmin), the before hook
 *   returns null and the defines return false, so `can:platform-owner` and
 *   any other central gate behave exactly as before.
 * - The before hook never returns true. It only denies or falls through.
 *
 * Unknown abilities: the plan's "deny by default" is met by Laravel itself.
 * An ability with no define and no policy resolves to a null result, which
 * Gate::allows()/`can:` treat as denied. The before hook deliberately
 * returns null (not false) for non-catalog abilities, so a real policy or
 * named gate added later for tenant users (none exist today; the only other
 * gate is the central `platform-owner`) is not silently broken by it.
 */
class AuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (array_keys(PermissionCatalog::permissions()) as $key) {
            // Untyped $user: a typed User parameter would throw a TypeError
            // when a PlatformAdmin is checked against a tenant key. Guests
            // never reach this (Laravel skips callbacks whose first
            // parameter does not allow null), so they are denied too.
            Gate::define($key, fn ($user): bool => $user instanceof User && $user->hasPermission($key));
        }

        Gate::before(function ($user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            $tenant = EffectivePermissions::currentTenant();

            if ($tenant === null) {
                return null;
            }

            if (! $user->isActive()) {
                return false;
            }

            if (PermissionCatalog::has($ability)) {
                $module = PermissionCatalog::moduleOf($ability);

                if ($module === null || ! EffectivePermissions::tenantHasModule($tenant, $module)) {
                    return false;
                }
            }

            return null;
        });
    }
}
