<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

test('deleting a tenant removes its database from disk', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')->post(route('central.tenants.store'), [
        'company_name' => 'Deleteme Inc',
        'subdomain' => 'deleteme',
        'contact_email' => null,
        'admin_name' => 'Admin',
        'admin_email' => 'admin@deleteme.test',
        'admin_password' => 'password123',
    ]);

    $tenant = Tenant::where('company_name', 'Deleteme Inc')->firstOrFail();
    $databasePath = database_path($tenant->database()->getName());

    expect(file_exists($databasePath))->toBeTrue();

    $this->actingAs($admin, 'platform')
        ->delete(route('central.tenants.destroy', $tenant))
        ->assertRedirect(route('central.tenants.index'));

    expect(file_exists($databasePath))->toBeFalse();
    expect(Tenant::find($tenant->id))->toBeNull();
});
