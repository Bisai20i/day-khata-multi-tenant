<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Tenancy stays initialized after an HTTP call inside one test process, so
 * revert to the central connection for RefreshDatabase's teardown.
 */
afterEach(function () {
    tenancy()->end();
});

/**
 * Route groups of the masters and inventory chunk (todo/permissions/ROUTE-MAP.md).
 * Only routes without model binding are listed, so authorization (403) is the
 * only thing that can differ: [route name, method, permission key, module].
 * Bound routes (update, destroy, cancel, print) share their key with a row here
 * or are covered by the route audit test.
 *
 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
 */
function mastersInventoryRouteRows(): array
{
    $rows = [
        ['tenant.account-groups.index', 'get', 'accounts.view', 'core'],
        ['tenant.account-groups.store', 'post', 'accounts.create', 'core'],
        ['tenant.account-subgroups.index', 'get', 'accounts.view', 'core'],
        ['tenant.account-subgroups.store', 'post', 'accounts.create', 'core'],
        ['tenant.accounts.index', 'get', 'accounts.view', 'core'],
        ['tenant.accounts.store', 'post', 'accounts.create', 'core'],
        ['tenant.accounts.opening-balances.import', 'post', 'opening_balances.import', 'core'],
        ['tenant.customers.index', 'get', 'customers.view', 'core'],
        ['tenant.customers.store', 'post', 'customers.create', 'core'],
        ['tenant.customers.import.template', 'get', 'customers.import', 'core'],
        ['tenant.customers.import', 'post', 'customers.import', 'core'],
        ['tenant.suppliers.index', 'get', 'suppliers.view', 'core'],
        ['tenant.suppliers.store', 'post', 'suppliers.create', 'core'],
        ['tenant.suppliers.import.template', 'get', 'suppliers.import', 'core'],
        ['tenant.suppliers.import', 'post', 'suppliers.import', 'core'],
        ['tenant.brands.index', 'get', 'brands.view', 'core'],
        ['tenant.brands.store', 'post', 'brands.manage', 'core'],
        ['tenant.item-categories.index', 'get', 'item_categories.view', 'core'],
        ['tenant.item-categories.store', 'post', 'item_categories.manage', 'core'],
        ['tenant.item-subcategories.index', 'get', 'item_categories.view', 'core'],
        ['tenant.item-subcategories.store', 'post', 'item_categories.manage', 'core'],
        ['tenant.items.index', 'get', 'items.view', 'core'],
        ['tenant.items.store', 'post', 'items.create', 'core'],
        ['tenant.items.import.template', 'get', 'items.import', 'core'],
        ['tenant.items.import', 'post', 'items.import', 'core'],
        ['tenant.items.mark-vatable', 'post', 'items.edit', 'core'],
        ['tenant.stores.index', 'get', 'stores.view', 'inventory'],
        ['tenant.stores.store', 'post', 'stores.manage', 'inventory'],
        ['tenant.item-varieties.index', 'get', 'item_varieties.view', 'inventory'],
        ['tenant.item-varieties.store', 'post', 'item_varieties.manage', 'inventory'],
        ['tenant.stock-adjustments.index', 'get', 'stock_adjustments.view', 'inventory'],
        ['tenant.stock-adjustments.store', 'post', 'stock_adjustments.create', 'inventory'],
        ['tenant.stock-adjustments.opening-stock.template', 'get', 'opening_stock.import', 'inventory'],
        ['tenant.stock-adjustments.opening-stock.import', 'post', 'opening_stock.import', 'inventory'],
        ['tenant.stock-transfers.index', 'get', 'stock_transfers.view', 'inventory'],
        ['tenant.stock-transfers.store', 'post', 'stock_transfers.create', 'inventory'],
        ['tenant.stock-conversions.index', 'get', 'stock_conversions.view', 'inventory'],
        ['tenant.stock-conversions.store', 'post', 'stock_conversions.create', 'inventory'],
    ];

    $named = [];
    foreach ($rows as $row) {
        $named[$row[0]] = $row;
    }

    return $named;
}

/**
 * @return array{0: Tenant, 1: string}
 */
function provisionMastersPermissionTenant(): array
{
    $domain = 'masters-perm-'.Str::lower(Str::random(8)).'.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Masters Perm Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return [$tenant, $domain];
}

/**
 * @param  array<int, string>|null  $keys  null creates the owner
 */
function mastersPermissionUser(Tenant $tenant, ?array $keys): User
{
    return $tenant->run(fn () => $keys === null ? User::factory()->create() : userWithPermissions($keys));
}

/**
 * Ends tenancy first so the domain middleware resolves a fresh tenant
 * instance: tenancy()->initialize() is a no-op for an already initialized
 * tenant, which would otherwise keep serving stale enabled_modules.
 */
function callMastersRoute(string $method, string $domain, string $routeName): int
{
    tenancy()->end();

    $url = "http://{$domain}".route($routeName, [], false);

    return test()->{$method}($url)->getStatusCode();
}

it('gates each masters and inventory route by its permission key and module', function (string $routeName, string $method, string $key, string $module) {
    [$tenant, $domain] = provisionMastersPermissionTenant();

    $owner = mastersPermissionUser($tenant, null);
    $granted = mastersPermissionUser($tenant, [$key]);
    $denied = mastersPermissionUser($tenant, array_values(array_diff(PermissionCatalog::grantable(), [$key])));

    // Owner allowed when entitled (default modules include inventory).
    test()->actingAs($owner, 'web');
    expect(callMastersRoute($method, $domain, $routeName))->not->toBe(403);

    // Allowed with the key.
    test()->actingAs($granted, 'web');
    expect(callMastersRoute($method, $domain, $routeName))->not->toBe(403);

    // 403 without the key, even holding every other grantable key.
    test()->actingAs($denied, 'web');
    expect(callMastersRoute($method, $domain, $routeName))->toBe(403);

    if ($module === 'core') {
        // Core masters stay reachable for the owner with every optional module off.
        $tenant->update(['enabled_modules' => []]);
        test()->actingAs($owner, 'web');
        expect(callMastersRoute($method, $domain, $routeName))->not->toBe(403);
    } else {
        // A disabled module blocks even the owner and a role that holds the key.
        $tenant->update(['enabled_modules' => array_values(array_diff(config('permissions.default_modules'), [$module]))]);
        test()->actingAs($owner, 'web');
        expect(callMastersRoute($method, $domain, $routeName))->toBe(403);
        test()->actingAs($granted, 'web');
        expect(callMastersRoute($method, $domain, $routeName))->toBe(403);
    }

    $tenant->delete();
})->with(fn () => mastersInventoryRouteRows());
