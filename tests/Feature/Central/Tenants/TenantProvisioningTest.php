<?php

use App\Enums\TenantStatus;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

afterEach(function () {
    // Tenant databases are real SQLite files on disk, independent of the
    // central (in-memory) DB reset RefreshDatabase gives us, so drop them
    // explicitly via the normal TenantDeleted -> DeleteDatabase pipeline.
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('guests are redirected away from tenant management routes', function () {
    $this->get(route('central.tenants.index'))->assertRedirect(route('login'));
});

test('creating a tenant via the endpoint provisions a working tenant database with a first admin user', function () {
    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'platform')->post(route('central.tenants.store'), [
        'company_name' => 'Acme Inc',
        'subdomain' => 'acme',
        'contact_email' => 'billing@acme.test',
        'admin_name' => 'Acme Admin',
        'admin_email' => 'admin@acme.test',
        'admin_password' => 'password123',
    ]);

    $tenant = Tenant::where('company_name', 'Acme Inc')->firstOrFail();

    $response->assertRedirect(route('central.tenants.show', $tenant));

    // The TenantCreated job pipeline is queued (TenancyServiceProvider), but
    // the test suite runs on the `sync` queue connection, so by the time the
    // request above returns, CreateDatabase/MigrateDatabase/SeedDatabase/
    // CreateTenantFirstAdmin have already all run inline and the tenant is
    // already Active — this is exercising the real pipeline, not a stub.
    expect($tenant->status)->toBe(TenantStatus::Active);
    expect($tenant->pending_admin)->toBeNull();
    expect($tenant->contact_email)->toBe('billing@acme.test');
    expect($tenant->domains()->where('domain', 'acme.localhost')->exists())->toBeTrue();

    // The tenant database is a real file created by the TenantCreated job pipeline.
    expect(file_exists(database_path($tenant->database()->getName())))->toBeTrue();

    // Resolve everything (including the `role` relation) inside run(), since
    // the tenant DB connection is torn down again once the closure returns.
    $adminUser = $tenant->run(function () {
        $user = User::with('role')->where('email', 'admin@acme.test')->firstOrFail();

        return ['name' => $user->name, 'role_slug' => $user->role->slug];
    });

    expect($adminUser['name'])->toBe('Acme Admin')
        ->and($adminUser['role_slug'])->toBe('admin');

    expect(PlatformAdminActivityLog::where('action', 'tenant.create')
        ->where('tenant_id', $tenant->id)
        ->where('platform_admin_id', $admin->id)
        ->exists())->toBeTrue();
});

test('a request into a still-provisioning tenant is blocked instead of hitting a missing database', function () {
    // Fake the queue so the TenantCreated pipeline (CreateDatabase/
    // MigrateDatabase/SeedDatabase/CreateTenantFirstAdmin) never actually
    // runs, leaving the tenant stuck in Provisioning — exactly the window a
    // real deployment has between the store() request returning and a queue
    // worker picking the job up.
    Queue::fake();

    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')->post(route('central.tenants.store'), [
        'company_name' => 'Still Cooking Inc',
        'subdomain' => 'stillcooking',
        'contact_email' => null,
        'admin_name' => 'Pending Admin',
        'admin_email' => 'admin@stillcooking.test',
        'admin_password' => 'password123',
    ]);

    $tenant = Tenant::where('company_name', 'Still Cooking Inc')->firstOrFail();

    expect($tenant->status)->toBe(TenantStatus::Provisioning);
    // Nothing was ever created for this tenant, so there's no database file
    // on disk to clean up — the afterEach's Tenant::delete() call is a no-op
    // database-file-wise here (also queued/faked), which is fine.
    expect(file_exists(database_path($tenant->database()->getName())))->toBeFalse();

    // Matches the existing convention in TenantSuspensionTest (status-only,
    // hitting the public root route rather than an auth-gated one, since the
    // abort fires from AbortIfTenantSuspended, which runs early in the tenant
    // route middleware stack — right after tenancy is initialized).
    $this->get('http://stillcooking.localhost/')->assertStatus(403);
});

test('the subdomain must be unique across tenants', function () {
    $admin = PlatformAdmin::factory()->create();

    $payload = [
        'company_name' => 'First Co',
        'subdomain' => 'taken',
        'contact_email' => null,
        'admin_name' => 'First Admin',
        'admin_email' => 'first@example.test',
        'admin_password' => 'password123',
    ];

    $this->actingAs($admin, 'platform')->post(route('central.tenants.store'), $payload);

    $payload['company_name'] = 'Second Co';
    $payload['admin_email'] = 'second@example.test';

    $this->actingAs($admin, 'platform')
        ->post(route('central.tenants.store'), $payload)
        ->assertSessionHasErrors('subdomain');

    expect(Tenant::count())->toBe(1);
});
