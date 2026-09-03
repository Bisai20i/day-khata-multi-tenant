<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

uses(RefreshDatabase::class);

afterEach(function () {
    // Tenant databases are real SQLite files on disk, independent of the
    // central (in-memory) DB reset RefreshDatabase gives us, so drop them
    // explicitly via the normal TenantDeleted -> DeleteDatabase pipeline
    // (matches the convention in TenantProvisioningTest/ImpersonationTest).
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Provisions a tenant through the real HTTP endpoint (as an authenticated
 * platform admin) and returns it, so this test exercises the same
 * provisioning flow (and its real seeded admin user/database) as the other
 * central tenant tests.
 */
function provisionTenantUsersTestTenant(TestCase $test, PlatformAdmin $admin, string $subdomain): Tenant
{
    $test->actingAs($admin, 'platform')->post(route('central.tenants.store'), [
        'company_name' => ucfirst($subdomain).' Inc',
        'subdomain' => $subdomain,
        'contact_email' => null,
        'admin_name' => 'Tenant Admin',
        'admin_email' => "admin@{$subdomain}.test",
        'admin_password' => 'password123',
    ]);

    return Tenant::where('company_name', ucfirst($subdomain).' Inc')->firstOrFail();
}

test('a platform admin can view a tenant\'s users', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantUsersTestTenant($this, $admin, 'userview');

    $tenant->run(function () {
        User::factory()->create([
            'name' => 'Second Staffer',
            'email' => 'staffer@userview.test',
        ]);
    });

    $response = $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.users', $tenant));

    // Controller orders users by name ascending: "Second Staffer" sorts
    // before the seeded "Tenant Admin".
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Central/Tenants/Users')
        ->where('tenant.id', $tenant->id)
        ->where('tenant.company_name', 'Userview Inc')
        ->has('users', 2)
        ->where('users.0.email', 'staffer@userview.test')
        ->where('users.1.email', 'admin@userview.test')
        ->where('users.1.role', 'Admin')
    );
});

test('a guest cannot view a tenant\'s users', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantUsersTestTenant($this, $admin, 'guestblocked');

    $this->get(route('central.tenants.users', $tenant))
        ->assertRedirect(route('login'));
});

test('a tenant-side web-guard user cannot view the tenant users page', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTenantUsersTestTenant($this, $admin, 'webguarded');

    $tenantUser = $tenant->run(fn () => User::where('email', 'admin@webguarded.test')->firstOrFail());

    $this->actingAs($tenantUser, 'web')
        ->get(route('central.tenants.users', $tenant))
        ->assertRedirect(route('login'));

    expect(Auth::guard('platform')->check())->toBeFalse();
});

test('the users page only reflects the requested tenant\'s own database, not another tenant\'s', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenantA = provisionTenantUsersTestTenant($this, $admin, 'tenantalpha');
    $tenantB = provisionTenantUsersTestTenant($this, $admin, 'tenantbeta');

    $tenantB->run(function () {
        User::factory()->create([
            'name' => 'Beta Only User',
            'email' => 'betaonly@tenantbeta.test',
        ]);
    });

    $response = $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.users', $tenantA));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Central/Tenants/Users')
        ->has('users', 1)
        ->where('users.0.email', 'admin@tenantalpha.test')
    );
});
