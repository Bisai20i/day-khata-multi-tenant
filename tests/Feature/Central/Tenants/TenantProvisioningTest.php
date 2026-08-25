<?php

use App\Enums\TenantStatus;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    expect($tenant->status)->toBe(TenantStatus::Active);
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
