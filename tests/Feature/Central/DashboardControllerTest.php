<?php

use App\Enums\TenantStatus;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

afterEach(function () {
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Constructed directly rather than through the real provisioning HTTP flow -
 * these tests only care about DashboardController's own aggregation, not
 * provisioning itself. Queue::fake() keeps a tenant at whatever status is
 * passed without ever touching the real TenantCreated pipeline, same
 * technique the other Central\Tenants test files already use.
 */
function makeDashboardTestTenant(string $companyName, TenantStatus $status = TenantStatus::Active): Tenant
{
    Queue::fake();

    $tenant = new Tenant(['company_name' => $companyName, 'status' => $status]);
    $tenant->save();

    return $tenant->fresh();
}

test('a guest cannot access the dashboard', function () {
    $this->get(route('central.dashboard'))->assertRedirect(route('login'));
});

test('the dashboard surfaces tenant counts by status', function () {
    $admin = PlatformAdmin::factory()->create();
    makeDashboardTestTenant('Active One', TenantStatus::Active);
    makeDashboardTestTenant('Active Two', TenantStatus::Active);
    makeDashboardTestTenant('Provisioning One', TenantStatus::Provisioning);
    makeDashboardTestTenant('Suspended One', TenantStatus::Suspended);

    $this->actingAs($admin, 'platform')
        ->get(route('central.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Central/Dashboard')
            ->where('stats.total', 4)
            ->where('stats.active', 2)
            ->where('stats.provisioning', 1)
            ->where('stats.suspended', 1));
});

test('the dashboard flags suspended tenants past their grace period', function () {
    $admin = PlatformAdmin::factory()->create();
    PlatformSetting::current()->update(['default_grace_period_days' => 30]);

    Carbon::setTestNow('2026-01-01 00:00:00');
    $tenant = makeDashboardTestTenant('Overdue Co', TenantStatus::Suspended);
    $tenant->update(['suspended_at' => now()]);

    Carbon::setTestNow('2026-01-20 00:00:00');

    // Still within its grace period (16 days old, 30-day grace) at the
    // moment the dashboard is checked below - must not show up.
    $withinGrace = makeDashboardTestTenant('Within Grace Co', TenantStatus::Suspended);
    $withinGrace->update(['suspended_at' => now()]);

    Carbon::setTestNow('2026-02-05 00:00:00');

    $this->actingAs($admin, 'platform')
        ->get(route('central.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('tenantsPastGracePeriod', 1)
            ->where('tenantsPastGracePeriod.0.company_name', 'Overdue Co'));

    Carbon::setTestNow();
});

test('the dashboard surfaces trials expiring within 7 days, excluding already-expired ones', function () {
    $admin = PlatformAdmin::factory()->create();

    $expiringSoon = makeDashboardTestTenant('Expiring Soon Co');
    $expiringSoon->update(['trial_ends_at' => now()->addDays(3)]);

    $expiringLater = makeDashboardTestTenant('Expiring Later Co');
    $expiringLater->update(['trial_ends_at' => now()->addDays(20)]);

    $alreadyExpired = makeDashboardTestTenant('Already Expired Co');
    $alreadyExpired->update(['trial_ends_at' => now()->subDay()]);

    $this->actingAs($admin, 'platform')
        ->get(route('central.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('trialsExpiringSoon', 1)
            ->where('trialsExpiringSoon.0.company_name', 'Expiring Soon Co'));
});

test('the dashboard surfaces the 10 most recent activity log entries in reverse-chronological order', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = makeDashboardTestTenant('Logged Co');

    $older = PlatformAdminActivityLog::create([
        'platform_admin_id' => $admin->id,
        'tenant_id' => $tenant->id,
        'action' => 'tenant.create',
        'metadata' => [],
        'created_at' => now()->subMinutes(10),
    ]);
    $newer = PlatformAdminActivityLog::create([
        'platform_admin_id' => $admin->id,
        'tenant_id' => $tenant->id,
        'action' => 'tenant.suspend',
        'metadata' => [],
        'created_at' => now(),
    ]);

    $this->actingAs($admin, 'platform')
        ->get(route('central.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('recentActivity.0.id', $newer->id)
            ->where('recentActivity.1.id', $older->id));
});
