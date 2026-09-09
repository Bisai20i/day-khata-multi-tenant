<?php

use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

uses(RefreshDatabase::class);

afterEach(function () {
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Provisions a tenant through the real HTTP endpoint (as an authenticated
 * platform admin), matching the established convention in
 * GracePeriodTest/TenantSuspensionTest.
 */
function provisionTrialTestTenant(TestCase $test, PlatformAdmin $admin, string $subdomain): Tenant
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

test('creating a tenant sets trial_ends_at from the platform default trial days setting', function () {
    PlatformSetting::current()->update(['default_trial_days' => 21]);

    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTrialTestTenant($this, $admin, 'trialdefault');

    expect($tenant->trial_ends_at)->not->toBeNull();
    expect(abs(now()->addDays(21)->diffInMinutes($tenant->trial_ends_at)))->toBeLessThan(5);
});

test('isTrialExpired is false before trial_ends_at and true after it', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTrialTestTenant($this, $admin, 'trialcheck');

    $tenant->update(['trial_ends_at' => now()->addDay()]);
    expect($tenant->fresh()->isTrialExpired())->toBeFalse();

    $tenant->update(['trial_ends_at' => now()->subDay()]);
    expect($tenant->fresh()->isTrialExpired())->toBeTrue();
});

test('isTrialExpired is false when a tenant has no trial_ends_at at all', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTrialTestTenant($this, $admin, 'notrial');

    $tenant->update(['trial_ends_at' => null]);

    expect($tenant->fresh()->isTrialExpired())->toBeFalse();
});

test('the tenant list and show pages surface trial_expired', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = provisionTrialTestTenant($this, $admin, 'trialsurfaced');
    $tenant->update(['trial_ends_at' => now()->subDay()]);

    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.index'))
        ->assertInertia(fn ($page) => $page->where('tenants.data.0.trial_expired', true));

    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.show', $tenant))
        ->assertInertia(fn ($page) => $page->where('tenant.trial_expired', true));
});

test('an owner can update a tenant\'s trial expiry', function () {
    $owner = PlatformAdmin::factory()->create();
    $tenant = provisionTrialTestTenant($this, $owner, 'trialupdate');

    $this->actingAs($owner, 'platform')
        ->put(route('central.tenants.update-trial', $tenant), ['trial_ends_at' => '2026-12-01'])
        ->assertRedirect(route('central.tenants.show', $tenant));

    expect($tenant->fresh()->trial_ends_at->toDateString())->toBe('2026-12-01');

    expect(PlatformAdminActivityLog::where('tenant_id', $tenant->id)
        ->where('action', 'tenant.update_trial')
        ->where('platform_admin_id', $owner->id)
        ->exists())->toBeTrue();
});

test('clearing the trial expiry field sets it to null', function () {
    $owner = PlatformAdmin::factory()->create();
    $tenant = provisionTrialTestTenant($this, $owner, 'trialclear');
    $tenant->update(['trial_ends_at' => now()->addDays(14)]);

    $this->actingAs($owner, 'platform')
        ->put(route('central.tenants.update-trial', $tenant), ['trial_ends_at' => null])
        ->assertRedirect(route('central.tenants.show', $tenant));

    expect($tenant->fresh()->trial_ends_at)->toBeNull();
});

test('a support admin cannot update a tenant\'s trial expiry', function () {
    $owner = PlatformAdmin::factory()->create();
    $tenant = provisionTrialTestTenant($this, $owner, 'trialsupportblocked');
    Auth::guard('platform')->logout();

    $support = PlatformAdmin::factory()->support()->create();

    $this->actingAs($support, 'platform')
        ->put(route('central.tenants.update-trial', $tenant), ['trial_ends_at' => '2026-12-01'])
        ->assertForbidden();

    expect($tenant->fresh()->trial_ends_at)->not->toBe('2026-12-01');
});

test('guests cannot update a tenant\'s trial expiry', function () {
    $owner = PlatformAdmin::factory()->create();
    $tenant = provisionTrialTestTenant($this, $owner, 'trialguestblocked');
    Auth::guard('platform')->logout();

    $this->put(route('central.tenants.update-trial', $tenant), ['trial_ends_at' => '2026-12-01'])
        ->assertRedirect(route('login'));
});
