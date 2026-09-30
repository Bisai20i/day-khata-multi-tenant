<?php

use App\Support\Permissions\PermissionCatalog;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

/**
 * Authenticated tenant routes that deliberately carry no `can:` middleware.
 * Every entry needs a reason; adding a route here is a security decision, so
 * keep this list as short as todo/permissions/ROUTE-MAP.md's allowlist.
 *
 * @var array<string, string>
 */
const ROUTE_AUDIT_ALLOWLIST = [
    'tenant.dashboard' => 'Landing page for every signed-in user.',
    'tenant.logout' => 'Every user must be able to end their own session.',
    'tenant.profile.edit' => 'A user editing their own profile.',
    'tenant.profile.update' => 'A user editing their own profile.',
    'tenant.profile.password' => 'A user changing their own password.',
];

/**
 * Authenticated tenant routes authorized inside the controller because one
 * `can:` cannot express the rule: the account ledger accepts account_ledger.*
 * for any account, or party_ledger.* only for customer and supplier accounts.
 *
 * @var array<string, string>
 */
const ROUTE_AUDIT_CONTROLLER_AUTHORIZED = [
    'tenant.accounts.ledger' => 'account_ledger.view or party_ledger.view (AccountController)',
    'tenant.accounts.ledger.print' => 'account_ledger.print or party_ledger.print (AccountController)',
    'tenant.accounts.ledger.export' => 'account_ledger.export or party_ledger.export (AccountController)',
];

/**
 * Tenant routes still using the legacy `role:` middleware. Must stay empty:
 * the alias and EnsureUserHasRole were removed in P16, so a `role:` entry
 * would now fail at runtime as well.
 *
 * @var list<string>
 */
const ROUTE_AUDIT_LEGACY_ROLE_ROUTES = [];

/**
 * Every route registered behind tenancy initialization, with its resolved
 * middleware list (group middleware included).
 *
 * @return list<array{name: string, route: RoutingRoute, middleware: list<string>}>
 */
function tenantRoutesForAudit(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = array_values(array_map('strval', $route->gatherMiddleware()));

        if (! in_array(InitializeTenancyByDomain::class, $middleware, true)) {
            continue;
        }

        $routes[] = [
            'name' => $route->getName() ?? $route->methods()[0].' '.$route->uri(),
            'route' => $route,
            'middleware' => $middleware,
        ];
    }

    return $routes;
}

/**
 * The abilities named by the route's `can:` middleware.
 *
 * @param  list<string>  $middleware
 * @return list<string>
 */
function canAbilitiesOf(array $middleware): array
{
    $abilities = [];

    foreach ($middleware as $entry) {
        if (str_starts_with($entry, 'can:')) {
            $abilities[] = explode(',', substr($entry, 4))[0];
        }
    }

    return $abilities;
}

it('finds the tenant routes it audits', function () {
    $authenticated = array_filter(
        tenantRoutesForAudit(),
        fn (array $route): bool => in_array('auth:web', $route['middleware'], true),
    );

    // A sanity floor so a routing refactor that hides the middleware from
    // gatherMiddleware() cannot turn this audit into a silent no-op.
    expect(count($authenticated))->toBeGreaterThan(200);
});

it('gates every authenticated tenant route with a catalog permission', function () {
    $offenders = [];

    foreach (tenantRoutesForAudit() as $route) {
        if (! in_array('auth:web', $route['middleware'], true)) {
            continue;
        }

        $name = $route['name'];

        if (isset(ROUTE_AUDIT_ALLOWLIST[$name]) || isset(ROUTE_AUDIT_CONTROLLER_AUTHORIZED[$name])) {
            continue;
        }

        $abilities = canAbilitiesOf($route['middleware']);

        if ($abilities === []) {
            $offenders[] = "{$name}: no can: middleware";

            continue;
        }

        foreach ($abilities as $ability) {
            if (! PermissionCatalog::has($ability)) {
                $offenders[] = "{$name}: can:{$ability} is not in config/permissions.php";
            }
        }
    }

    expect($offenders)->toBe([], "Ungated or mis-gated tenant routes:\n".implode("\n", $offenders));
});

it('keeps the allowlists pointing at real, authenticated, ungated routes', function () {
    $byName = [];
    foreach (tenantRoutesForAudit() as $route) {
        $byName[$route['name']] = $route;
    }

    foreach (array_keys(ROUTE_AUDIT_ALLOWLIST + ROUTE_AUDIT_CONTROLLER_AUTHORIZED) as $name) {
        expect($byName)->toHaveKey($name);
        expect($byName[$name]['middleware'])->toContain('auth:web');
        expect(canAbilitiesOf($byName[$name]['middleware']))->toBe([], "{$name} is allowlisted but carries can:");
    }
});

it('uses no legacy role: middleware on tenant routes', function () {
    $offenders = [];

    foreach (tenantRoutesForAudit() as $route) {
        foreach ($route['middleware'] as $entry) {
            if (str_starts_with($entry, 'role:') && ! in_array($route['name'], ROUTE_AUDIT_LEGACY_ROLE_ROUTES, true)) {
                $offenders[] = "{$route['name']}: {$entry}";
            }
        }
    }

    expect($offenders)->toBe([], "Tenant routes still on role: middleware:\n".implode("\n", $offenders));
});
