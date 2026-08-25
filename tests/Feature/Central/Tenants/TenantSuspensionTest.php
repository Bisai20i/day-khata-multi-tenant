<?php

use App\Enums\TenantStatus;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

afterEach(function () {
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Provisions a tenant through the real HTTP endpoint (as an authenticated
 * platform admin) and returns it, so these tests exercise the same
 * provisioning flow as TenantProvisioningTest.
 */
function provisionSuspensionTestTenant(TestCase $test, PlatformAdmin $admin, string $subdomain): Tenant
{
    $test->actingAs($admin, 'platform')->post(route('central.tenants.store'), [
        'company_name' => ucfirst($subdomain).' Inc',
        'subdomain' => $subdomain,
        'contact_email' => null,
        'admin_name' => 'Admin',
        'admin_email' => "admin@{$subdomain}.test",
        'admin_password' => 'password123',
    ]);

    return Tenant::where('company_name', ucfirst($subdomain).' Inc')->firstOrFail();
}

test('suspending a tenant blocks requests to its domain with a 403', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionSuspensionTestTenant($this, $admin, 'suspendme');

    $this->actingAs($admin, 'platform')
        ->post(route('central.tenants.suspend', $tenant))
        ->assertRedirect(route('central.tenants.show', $tenant));

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended);

    $this->get('http://suspendme.localhost/')->assertStatus(403);
});

test('resuming a suspended tenant restores access to its domain', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionSuspensionTestTenant($this, $admin, 'resumeme');

    $this->actingAs($admin, 'platform')->post(route('central.tenants.suspend', $tenant));
    $this->get('http://resumeme.localhost/')->assertStatus(403);

    $this->actingAs($admin, 'platform')
        ->post(route('central.tenants.resume', $tenant))
        ->assertRedirect(route('central.tenants.show', $tenant));

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);

    $this->get('http://resumeme.localhost/')->assertStatus(200);
});
