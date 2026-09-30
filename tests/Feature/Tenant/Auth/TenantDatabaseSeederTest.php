<?php

use App\Models\Role;
use App\Models\Tenant;
use App\Support\Permissions\PermissionCatalog;
use App\Support\Permissions\RoleTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Tenancy has no automatic "end of request" hook outside of a real PHP-FPM
 * style process boundary, so within a single test process the tenant
 * connection stays the default connection after an HTTP call. Revert to the
 * central connection after every test so RefreshDatabase's teardown rolls
 * back the connection it actually started a transaction on.
 */
afterEach(function () {
    tenancy()->end();
});

/**
 * Tenant creation already migrates and seeds the tenant database via the
 * TenantCreated event pipeline (see app/Providers/TenancyServiceProvider).
 * No explicit `tenants:seed` call is needed here, and calling it again would
 * seed a second time and violate the roles/permissions unique constraints.
 */
function provisionSeederTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('the tenant database seeder produces exactly the admin, manager and cashier role slugs', function () {
    $tenant = provisionSeederTestTenant('seeder-roles.tenant-test');

    $tenant->run(function () {
        expect(Role::query()->pluck('slug')->sort()->values()->all())
            ->toBe(['admin', 'cashier', 'manager']);
    });

    $tenant->delete();
});

test('the legacy permissions and permission_role tables no longer exist', function () {
    $tenant = provisionSeederTestTenant('seeder-no-placeholders.tenant-test');

    $tenant->run(function () {
        expect(Schema::hasTable('permissions'))->toBeFalse()
            ->and(Schema::hasTable('permission_role'))->toBeFalse();
    });

    $tenant->delete();
});

test('the seeded admin role is a system role granting the live grantable catalog, manager and cashier get their templates', function () {
    $tenant = provisionSeederTestTenant('seeder-json-permissions.tenant-test');

    $tenant->run(function () {
        $admin = Role::query()->where('slug', 'admin')->firstOrFail();
        $manager = Role::query()->where('slug', 'manager')->firstOrFail();
        $cashier = Role::query()->where('slug', 'cashier')->firstOrFail();

        // A tenant created without an explicit list is entitled to every
        // default module, so the templates come through unfiltered.
        expect($admin->permissions)->toBe(PermissionCatalog::grantable())
            ->and($admin->is_system)->toBeTrue()
            ->and($manager->permissions)->toBe(RoleTemplates::MANAGER)
            ->and($manager->is_system)->toBeFalse()
            ->and($cashier->permissions)->toBe(RoleTemplates::CASHIER)
            ->and($cashier->is_system)->toBeFalse();

        foreach (PermissionCatalog::ownerOnly() as $ownerOnlyKey) {
            expect($admin->permissions)->not->toContain($ownerOnlyKey)
                ->and($manager->permissions)->not->toContain($ownerOnlyKey)
                ->and($cashier->permissions)->not->toContain($ownerOnlyKey);
        }
    });

    $tenant->delete();
});
