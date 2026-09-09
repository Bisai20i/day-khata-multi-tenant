<?php

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

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

function provisionProfileTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * Logs in a plain staff user (not admin) - the profile page must be usable
 * by every tenant user, not just admins, unlike Tenant\Admin\UserController.
 */
function loginAsStaffMember(string $domain): User
{
    $staff = null;

    tenancy()->initialize(Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $domain))->firstOrFail());

    $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
    $staff = User::factory()->create([
        'email' => 'staffer@example.com',
        'password' => 'password',
        'role_id' => $staffRole->id,
    ]);

    tenancy()->end();

    test()->post("http://{$domain}/login", [
        'email' => 'staffer@example.com',
        'password' => 'password',
    ]);

    return $staff;
}

test('an authenticated user can view their own profile page', function () {
    $domain = 'profile-view.tenant-test';
    $tenant = provisionProfileTestTenant($domain);
    $staff = loginAsStaffMember($domain);

    $response = test()->get("http://{$domain}/profile");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Tenant/Profile/Edit')
        ->where('user.email', $staff->email)
    );

    $tenant->delete();
});

test('a guest is redirected to login when visiting the profile page', function () {
    $domain = 'profile-guest.tenant-test';
    $tenant = provisionProfileTestTenant($domain);

    $response = test()->get("http://{$domain}/profile");

    $response->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});

test('a user can update their own name and email', function () {
    $domain = 'profile-update.tenant-test';
    $tenant = provisionProfileTestTenant($domain);
    $staff = loginAsStaffMember($domain);

    $response = test()->put("http://{$domain}/profile", [
        'name' => 'Renamed Staffer',
        'email' => 'renamed@example.com',
    ]);

    $response->assertRedirect();

    $tenant->run(function () use ($staff) {
        $fresh = $staff->fresh();
        expect($fresh->name)->toBe('Renamed Staffer');
        expect($fresh->email)->toBe('renamed@example.com');
    });

    $tenant->delete();
});

test('updating the profile requires a name and a unique email', function () {
    $domain = 'profile-validation.tenant-test';
    $tenant = provisionProfileTestTenant($domain);
    $staff = loginAsStaffMember($domain);

    $tenant->run(function () {
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
        User::factory()->create(['email' => 'taken@example.com', 'role_id' => $staffRole->id]);
    });

    test()->put("http://{$domain}/profile", ['name' => '', 'email' => 'staffer@example.com'])
        ->assertSessionHasErrors('name');

    test()->put("http://{$domain}/profile", ['name' => $staff->name, 'email' => 'taken@example.com'])
        ->assertSessionHasErrors('email');

    $tenant->delete();
});

test('a user can change their own password given the correct current password', function () {
    $domain = 'profile-password-update.tenant-test';
    $tenant = provisionProfileTestTenant($domain);
    $staff = loginAsStaffMember($domain);

    $response = test()->put("http://{$domain}/profile/password", [
        'current_password' => 'password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertRedirect();

    $tenant->run(function () use ($staff) {
        expect(Hash::check('new-password-123', $staff->fresh()->password))->toBeTrue();
    });

    $tenant->delete();
});

test('changing the password requires the correct current password', function () {
    $domain = 'profile-password-wrong-current.tenant-test';
    $tenant = provisionProfileTestTenant($domain);
    $staff = loginAsStaffMember($domain);

    $response = test()->put("http://{$domain}/profile/password", [
        'current_password' => 'not-the-current-password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertSessionHasErrors('current_password');

    $tenant->run(function () use ($staff) {
        expect(Hash::check('password', $staff->fresh()->password))->toBeTrue();
    });

    $tenant->delete();
});

test('changing the password requires confirmation to match', function () {
    $domain = 'profile-password-mismatch.tenant-test';
    $tenant = provisionProfileTestTenant($domain);
    loginAsStaffMember($domain);

    $response = test()->put("http://{$domain}/profile/password", [
        'current_password' => 'password',
        'password' => 'new-password-123',
        'password_confirmation' => 'does-not-match',
    ]);

    $response->assertSessionHasErrors('password');

    $tenant->delete();
});
