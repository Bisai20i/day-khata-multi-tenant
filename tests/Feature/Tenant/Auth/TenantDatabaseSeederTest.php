<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

test('the tenant database seeder produces exactly the admin and staff role slugs', function () {
    $tenant = provisionSeederTestTenant('seeder-roles.tenant-test');

    $tenant->run(function () {
        expect(Role::query()->pluck('slug')->sort()->values()->all())
            ->toBe(['admin', 'staff']);
    });

    $tenant->delete();
});

test('the seeded admin role has every seeded permission and staff has none', function () {
    $tenant = provisionSeederTestTenant('seeder-permissions.tenant-test');

    $tenant->run(function () {
        $totalPermissions = Permission::query()->count();
        expect($totalPermissions)->toBeGreaterThan(0);

        $admin = Role::query()->where('slug', 'admin')->firstOrFail();
        $staff = Role::query()->where('slug', 'staff')->firstOrFail();

        expect($admin->permissions()->count())->toBe($totalPermissions);
        expect($staff->permissions()->count())->toBe(0);
    });

    $tenant->delete();
});

test('the admin role grants every seeded permission via hasPermission', function () {
    $tenant = provisionSeederTestTenant('seeder-haspermission.tenant-test');

    $tenant->run(function () {
        $admin = Role::query()->where('slug', 'admin')->firstOrFail();
        $staff = Role::query()->where('slug', 'staff')->firstOrFail();

        Permission::query()->get()->each(function (Permission $permission) use ($admin, $staff) {
            expect($admin->hasPermission($permission->slug))->toBeTrue();
            expect($staff->hasPermission($permission->slug))->toBeFalse();
        });
    });

    $tenant->delete();
});
