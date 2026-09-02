<?php

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
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
function provisionRoleTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('a staff user is blocked from the admin-only user management page', function () {
    $domain = 'role-staff.tenant-test';
    $tenant = provisionRoleTestTenant($domain);

    $tenant->run(function () {
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();

        User::factory()->create([
            'email' => 'staffer@example.com',
            'role_id' => $staffRole->id,
        ]);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'staffer@example.com',
        'password' => 'password',
    ]);

    $response = $this->get("http://{$domain}/admin/users");

    $response->assertForbidden();

    $tenant->delete();
});

test('an admin user can access the admin-only user management page', function () {
    $domain = 'role-admin.tenant-test';
    $tenant = provisionRoleTestTenant($domain);

    $tenant->run(function () {
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();

        User::factory()->create([
            'email' => 'admin@example.com',
            'role_id' => $adminRole->id,
        ]);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $response = $this->get("http://{$domain}/admin/users");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Tenant/Admin/Users')
        ->has('users', 1)
        ->has('roles', 2)
    );

    $tenant->delete();
});

test('a user with no role is blocked from the admin-only user management page', function () {
    $domain = 'role-none.tenant-test';
    $tenant = provisionRoleTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'no-role@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'no-role@example.com',
        'password' => 'password',
    ]);

    $response = $this->get("http://{$domain}/admin/users");

    $response->assertForbidden();

    $tenant->delete();
});
