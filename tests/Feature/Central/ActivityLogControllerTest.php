<?php

use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

afterEach(function () {
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('a guest cannot access the activity log', function () {
    $this->get(route('central.activity-log.index'))->assertRedirect(route('login'));
});

test('a platform admin sees logged entries in reverse-chronological order', function () {
    $admin = PlatformAdmin::factory()->create();

    $older = PlatformAdminActivityLog::create([
        'platform_admin_id' => $admin->id,
        'tenant_id' => null,
        'action' => 'tenant.create',
        'metadata' => [],
        'created_at' => now()->subMinutes(10),
    ]);
    $newer = PlatformAdminActivityLog::create([
        'platform_admin_id' => $admin->id,
        'tenant_id' => null,
        'action' => 'tenant.suspend',
        'metadata' => [],
        'created_at' => now(),
    ]);

    $response = $this->actingAs($admin, 'platform')->get(route('central.activity-log.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Central/ActivityLog/Index')
        ->where('logs.data.0.id', $newer->id)
        ->where('logs.data.1.id', $older->id)
    );
});

test('filtering by action narrows results', function () {
    $admin = PlatformAdmin::factory()->create();

    PlatformAdminActivityLog::create([
        'platform_admin_id' => $admin->id,
        'tenant_id' => null,
        'action' => 'tenant.create',
        'metadata' => [],
        'created_at' => now(),
    ]);
    PlatformAdminActivityLog::create([
        'platform_admin_id' => $admin->id,
        'tenant_id' => null,
        'action' => 'tenant.suspend',
        'metadata' => [],
        'created_at' => now(),
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->get(route('central.activity-log.index', ['action' => 'tenant.suspend']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('logs.total', 1)
        ->where('logs.data.0.action', 'tenant.suspend')
    );
});

test('filtering by tenant_id narrows results', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenantOne = Tenant::create(['company_name' => 'Acme Inc']);
    $tenantTwo = Tenant::create(['company_name' => 'Globex Inc']);

    PlatformAdminActivityLog::create([
        'platform_admin_id' => $admin->id,
        'tenant_id' => $tenantOne->id,
        'action' => 'tenant.update',
        'metadata' => [],
        'created_at' => now(),
    ]);
    PlatformAdminActivityLog::create([
        'platform_admin_id' => $admin->id,
        'tenant_id' => $tenantTwo->id,
        'action' => 'tenant.update',
        'metadata' => [],
        'created_at' => now(),
    ]);

    $response = $this->actingAs($admin, 'platform')
        ->get(route('central.activity-log.index', ['tenant_id' => $tenantOne->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('logs.total', 1)
        ->where('logs.data.0.tenant.id', $tenantOne->id)
        ->where('logs.data.0.tenant.company_name', 'Acme Inc')
    );
});

test('a non-platform-admin cannot access the activity log', function () {
    $admin = PlatformAdmin::factory()->create();

    // actingAs() below leaves the platform guard authenticated for the rest
    // of the TestCase - log out first so this request is genuinely anonymous.
    $this->actingAs($admin, 'platform');
    Auth::guard('platform')->logout();

    $this->get(route('central.activity-log.index'))->assertRedirect(route('login'));
});

test('pagination works: a second page is reachable', function () {
    $admin = PlatformAdmin::factory()->create();

    for ($i = 1; $i <= 30; $i++) {
        PlatformAdminActivityLog::create([
            'platform_admin_id' => $admin->id,
            'tenant_id' => null,
            'action' => 'tenant.create',
            'metadata' => ['seq' => $i],
            'created_at' => now()->subMinutes(30 - $i),
        ]);
    }

    $firstPage = $this->actingAs($admin, 'platform')->get(route('central.activity-log.index'));
    $firstPage->assertOk();
    $firstPage->assertInertia(fn ($page) => $page
        ->where('logs.total', 30)
        ->where('logs.last_page', 2)
        ->has('logs.data', 25)
    );

    $secondPage = $this->actingAs($admin, 'platform')
        ->get(route('central.activity-log.index', ['page' => 2]));
    $secondPage->assertOk();
    $secondPage->assertInertia(fn ($page) => $page
        ->where('logs.current_page', 2)
        ->has('logs.data', 5)
    );
});
