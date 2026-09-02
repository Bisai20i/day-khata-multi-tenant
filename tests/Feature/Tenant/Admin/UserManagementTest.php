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

function provisionUserManagementTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsAdmin(string $domain): User
{
    $admin = null;

    tenancy()->initialize(Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $domain))->firstOrFail());

    $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
    $admin = User::factory()->create([
        'email' => 'boss@example.com',
        'password' => 'password',
        'role_id' => $adminRole->id,
    ]);

    tenancy()->end();

    test()->post("http://{$domain}/login", [
        'email' => 'boss@example.com',
        'password' => 'password',
    ]);

    return $admin;
}

test('an admin can create a new employee', function () {
    $domain = 'user-mgmt-create.tenant-test';
    $tenant = provisionUserManagementTestTenant($domain);
    loginAsAdmin($domain);

    $staffRole = null;
    $tenant->run(function () use (&$staffRole) {
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
    });

    $response = test()->post("http://{$domain}/admin/users", [
        'name' => 'New Employee',
        'email' => 'employee@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role_id' => $staffRole->id,
    ]);

    $response->assertRedirect();

    $tenant->run(function () use ($staffRole) {
        expect(User::where('email', 'employee@example.com')->first())
            ->not->toBeNull()
            ->is_active->toBeTrue()
            ->role_id->toBe($staffRole->id);
    });

    $tenant->delete();
});

test('an admin can edit and deactivate an employee', function () {
    $domain = 'user-mgmt-edit.tenant-test';
    $tenant = provisionUserManagementTestTenant($domain);
    loginAsAdmin($domain);

    $employee = null;
    $staffRoleId = null;
    $tenant->run(function () use (&$employee, &$staffRoleId) {
        $staffRoleId = Role::query()->where('slug', 'staff')->firstOrFail()->id;
        $employee = User::factory()->create(['email' => 'staffer@example.com', 'role_id' => $staffRoleId]);
    });

    $response = test()->put("http://{$domain}/admin/users/{$employee->id}", [
        'name' => 'Renamed Staffer',
        'email' => 'staffer@example.com',
        'role_id' => $staffRoleId,
        'is_active' => false,
    ]);

    $response->assertRedirect();

    $tenant->run(function () use ($employee) {
        $fresh = $employee->fresh();
        expect($fresh->name)->toBe('Renamed Staffer');
        expect($fresh->is_active)->toBeFalse();
    });

    $tenant->delete();
});

test('a staff user cannot create, edit, or view employees', function () {
    $domain = 'user-mgmt-staff.tenant-test';
    $tenant = provisionUserManagementTestTenant($domain);

    $staff = null;
    $tenant->run(function () use (&$staff) {
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
        $staff = User::factory()->create(['email' => 'staffer@example.com', 'role_id' => $staffRole->id]);
    });

    test()->post("http://{$domain}/login", ['email' => 'staffer@example.com', 'password' => 'password']);

    test()->get("http://{$domain}/admin/users")->assertForbidden();
    test()->post("http://{$domain}/admin/users", ['name' => 'x'])->assertForbidden();
    test()->put("http://{$domain}/admin/users/{$staff->id}", ['name' => 'x'])->assertForbidden();

    $tenant->delete();
});

test('deactivating the sole remaining active admin is rejected', function () {
    $domain = 'user-mgmt-last-admin.tenant-test';
    $tenant = provisionUserManagementTestTenant($domain);
    $admin = loginAsAdmin($domain);

    $adminRoleId = null;
    $tenant->run(function () use (&$adminRoleId) {
        $adminRoleId = Role::query()->where('slug', 'admin')->firstOrFail()->id;
    });

    $response = test()->put("http://{$domain}/admin/users/{$admin->id}", [
        'name' => $admin->name,
        'email' => $admin->email,
        'role_id' => $adminRoleId,
        'is_active' => false,
    ]);

    $response->assertSessionHasErrors();

    $tenant->run(function () use ($admin) {
        expect($admin->fresh()->is_active)->toBeTrue();
    });

    $tenant->delete();
});

test('reassigning the sole admin away from the admin role is rejected', function () {
    $domain = 'user-mgmt-last-admin-role.tenant-test';
    $tenant = provisionUserManagementTestTenant($domain);
    $admin = loginAsAdmin($domain);

    $staffRoleId = null;
    $tenant->run(function () use (&$staffRoleId) {
        $staffRoleId = Role::query()->where('slug', 'staff')->firstOrFail()->id;
    });

    $response = test()->put("http://{$domain}/admin/users/{$admin->id}", [
        'name' => $admin->name,
        'email' => $admin->email,
        'role_id' => $staffRoleId,
        'is_active' => true,
    ]);

    $response->assertSessionHasErrors();

    $tenant->run(function () use ($admin) {
        expect($admin->fresh()->role->slug)->toBe('admin');
    });

    $tenant->delete();
});

test('an inactive employee cannot log in', function () {
    $domain = 'user-mgmt-inactive-login.tenant-test';
    $tenant = provisionUserManagementTestTenant($domain);

    $tenant->run(function () {
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => 'password',
            'role_id' => $staffRole->id,
            'is_active' => false,
        ]);
    });

    $response = test()->from("http://{$domain}/login")->post("http://{$domain}/login", [
        'email' => 'inactive@example.com',
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    test()->assertGuest('web');

    $tenant->delete();
});
