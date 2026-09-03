<?php

use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

uses(RefreshDatabase::class);

afterEach(function () {
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Provisions a tenant through the real HTTP endpoint (as an authenticated
 * platform admin) and returns it, so these tests exercise the same
 * provisioning flow as the other central tenant tests.
 */
function provisionUpdateTestTenant(TestCase $test, PlatformAdmin $admin, string $subdomain): Tenant
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

test('a platform admin can update a tenant\'s company name and contact email', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionUpdateTestTenant($this, $admin, 'updateme');

    $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.update', $tenant), [
            'company_name' => 'Updateme Renamed Inc',
            'contact_email' => 'billing@updateme.test',
        ])
        ->assertRedirect(route('central.tenants.show', $tenant));

    $tenant->refresh();

    expect($tenant->company_name)->toBe('Updateme Renamed Inc')
        ->and($tenant->contact_email)->toBe('billing@updateme.test');
});

test('updating a tenant requires a company name', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionUpdateTestTenant($this, $admin, 'noname');

    $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.update', $tenant), [
            'company_name' => '',
            'contact_email' => null,
        ])
        ->assertSessionHasErrors('company_name');

    expect($tenant->fresh()->company_name)->toBe('Noname Inc');
});

test('guests cannot access the tenant edit or update routes', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionUpdateTestTenant($this, $admin, 'guestblocked');

    // provisionUpdateTestTenant() used actingAs() to provision, which leaves
    // the platform guard authenticated for the rest of this TestCase - log
    // out so these requests are genuinely anonymous.
    Auth::guard('platform')->logout();

    $this->get(route('central.tenants.edit', $tenant))->assertRedirect(route('login'));

    $this->put(route('central.tenants.update', $tenant), [
        'company_name' => 'Should Not Apply',
    ])->assertRedirect(route('login'));

    expect($tenant->fresh()->company_name)->toBe('Guestblocked Inc');
});

test('a tenant-side user cannot access the tenant edit or update routes', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionUpdateTestTenant($this, $admin, 'webuser');

    Auth::guard('platform')->logout();

    $webUser = User::factory()->make();

    $this->actingAs($webUser, 'web')
        ->get(route('central.tenants.edit', $tenant))
        ->assertRedirect(route('login'));

    $this->actingAs($webUser, 'web')
        ->put(route('central.tenants.update', $tenant), [
            'company_name' => 'Should Not Apply',
        ])
        ->assertRedirect(route('login'));

    expect($tenant->fresh()->company_name)->toBe('Webuser Inc');
});

test('a successful update records a platform admin activity log entry', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionUpdateTestTenant($this, $admin, 'auditme');

    $this->actingAs($admin, 'platform')->put(route('central.tenants.update', $tenant), [
        'company_name' => 'Auditme Renamed Inc',
        'contact_email' => 'contact@auditme.test',
    ]);

    $log = PlatformAdminActivityLog::where('action', 'tenant.update')
        ->where('tenant_id', $tenant->id)
        ->where('platform_admin_id', $admin->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['changed']['company_name'] ?? null)->toBe('Auditme Renamed Inc')
        ->and($log->metadata['changed']['contact_email'] ?? null)->toBe('contact@auditme.test');
});
