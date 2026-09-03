<?php

use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

uses(RefreshDatabase::class);

afterEach(function () {
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

function provisionDomainTestTenant(TestCase $test, PlatformAdmin $admin, string $subdomain): Tenant
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

test('a platform admin can add an additional domain to a tenant', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionDomainTestTenant($this, $admin, 'domainadd');

    $this->actingAs($admin, 'platform')
        ->post(route('central.tenants.domains.store', $tenant), ['domain' => 'alt-domainadd.localhost'])
        ->assertRedirect(route('central.tenants.show', $tenant));

    expect($tenant->domains()->where('domain', 'alt-domainadd.localhost')->exists())->toBeTrue();

    expect(PlatformAdminActivityLog::where('tenant_id', $tenant->id)
        ->where('action', 'tenant.domain.add')
        ->exists())->toBeTrue();
});

test('a domain must be unique across all tenants', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionDomainTestTenant($this, $admin, 'domaindupe');

    $this->actingAs($admin, 'platform')
        ->post(route('central.tenants.domains.store', $tenant), ['domain' => 'domaindupe.localhost'])
        ->assertSessionHasErrors('domain');
});

test('a platform admin can remove an additional domain from a tenant', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionDomainTestTenant($this, $admin, 'domainremove');

    $this->actingAs($admin, 'platform')
        ->post(route('central.tenants.domains.store', $tenant), ['domain' => 'alt-domainremove.localhost']);

    $extraDomain = Domain::where('domain', 'alt-domainremove.localhost')->firstOrFail();

    $this->actingAs($admin, 'platform')
        ->delete(route('central.tenants.domains.destroy', [$tenant, $extraDomain]))
        ->assertRedirect(route('central.tenants.show', $tenant));

    expect(Domain::where('domain', 'alt-domainremove.localhost')->exists())->toBeFalse();

    expect(PlatformAdminActivityLog::where('tenant_id', $tenant->id)
        ->where('action', 'tenant.domain.remove')
        ->exists())->toBeTrue();
});

test('a tenant last domain cannot be removed', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionDomainTestTenant($this, $admin, 'domainlast');

    $onlyDomain = $tenant->domains()->firstOrFail();

    $this->actingAs($admin, 'platform')
        ->delete(route('central.tenants.domains.destroy', [$tenant, $onlyDomain]))
        ->assertRedirect(route('central.tenants.show', $tenant))
        ->assertSessionHas('status', 'A tenant must have at least one domain.');

    expect(Domain::where('domain', $onlyDomain->domain)->exists())->toBeTrue();
});

test('a domain belonging to another tenant cannot be removed through this tenant', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenantA = provisionDomainTestTenant($this, $admin, 'domainowner');
    $tenantB = provisionDomainTestTenant($this, $admin, 'domainintruder');

    $tenantBDomain = $tenantB->domains()->firstOrFail();

    $this->actingAs($admin, 'platform')
        ->delete(route('central.tenants.domains.destroy', [$tenantA, $tenantBDomain]))
        ->assertNotFound();

    expect(Domain::where('domain', $tenantBDomain->domain)->exists())->toBeTrue();
});

test('guests cannot manage tenant domains', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionDomainTestTenant($this, $admin, 'domainguest');

    // provisionDomainTestTenant() calls actingAs() internally, which (unlike
    // a per-request header) stays authenticated on the guard for the rest
    // of this test until explicitly logged out - matches the same
    // Auth::guard('platform')->logout() convention already used in
    // PlatformSettingControllerTest's tenant-web-user-blocked case.
    Auth::guard('platform')->logout();

    $this->post(route('central.tenants.domains.store', $tenant), ['domain' => 'nope.localhost'])
        ->assertRedirect(route('login'));
});
