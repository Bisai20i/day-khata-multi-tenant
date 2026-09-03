<?php

use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
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

    // The log is written before $tenant->delete() runs (see the ordering
    // note in TenantController::destroy()) precisely so it survives the
    // tenant being gone. platform_admin_activity_logs.tenant_id is a real
    // FK with nullOnDelete, and this app's default config (database.php:
    // `foreign_key_constraints` => true, not overridden for tests) means
    // SQLite actually enforces it here - the row is expected to survive
    // with its tenant_id nulled out, not be removed.
    $log = PlatformAdminActivityLog::where('action', 'tenant.delete')
        ->where('platform_admin_id', $admin->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['company_name'] ?? null)->toBe('Deleteme Inc')
        ->and($log->tenant_id)->toBeNull();
});
