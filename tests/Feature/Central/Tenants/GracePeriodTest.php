<?php

use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
function provisionGracePeriodTestTenant(TestCase $test, PlatformAdmin $admin, string $subdomain): Tenant
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

test('isPastGracePeriod is false for an active (never suspended) tenant', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionGracePeriodTestTenant($this, $admin, 'nevergraced');

    expect($tenant->isPastGracePeriod(30))->toBeFalse();
});

test('isPastGracePeriod is false while still inside the grace window and true once past it', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionGracePeriodTestTenant($this, $admin, 'boundarygrace');

    Carbon::setTestNow('2026-01-01 00:00:00');
    $tenant->update(['suspended_at' => now()]);
    $tenant->refresh();

    Carbon::setTestNow('2026-01-30 00:00:00');
    expect($tenant->fresh()->isPastGracePeriod(30))->toBeFalse();

    Carbon::setTestNow('2026-02-01 00:00:01');
    expect($tenant->fresh()->isPastGracePeriod(30))->toBeTrue();

    Carbon::setTestNow();
});

test('the tenant list and show pages flag a tenant past its grace period', function () {
    $admin = PlatformAdmin::factory()->create();
    PlatformSetting::current()->update(['default_grace_period_days' => 30]);

    $tenant = provisionGracePeriodTestTenant($this, $admin, 'gracetest');

    Carbon::setTestNow('2026-01-01 00:00:00');
    $tenant->update(['suspended_at' => now()]);

    Carbon::setTestNow('2026-02-05 00:00:00');

    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.index'))
        ->assertInertia(fn ($page) => $page
            ->where('tenants.data.0.past_grace_period', true));

    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.show', $tenant))
        ->assertInertia(fn ($page) => $page
            ->where('tenant.past_grace_period', true));

    Carbon::setTestNow();
});
