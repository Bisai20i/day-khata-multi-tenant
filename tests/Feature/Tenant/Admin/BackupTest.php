<?php

use App\Models\Backup;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Tenancy has no automatic "end of request" hook outside of a real PHP-FPM
 * style process boundary, so within a single test process the tenant
 * connection stays the default connection after an HTTP call. Revert to the
 * central connection after every test so RefreshDatabase's teardown rolls
 * back the connection it actually started a transaction on.
 */
afterEach(function () {
    tenancy()->end();
});

function provisionBackupTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsBackupAdmin(string $domain): User
{
    $admin = null;

    tenancy()->initialize(Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $domain))->firstOrFail());

    $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
    $admin = User::factory()->create([
        'email' => 'boss@example.com',
        'password' => 'password',
        'role_id' => $adminRole->id,
    ]);

    tenancy()->end();

    test()->post("http://{$domain}/login", [
        'email' => 'boss@example.com',
        'password' => 'password',
    ]);

    return $admin;
}

test('an admin can create a backup, which writes a row and a real file on the private local disk', function () {
    $domain = 'backup-create.tenant-test';
    $tenant = provisionBackupTestTenant($domain);
    loginAsBackupAdmin($domain);

    $response = test()->post("http://{$domain}/backups");

    $response->assertRedirect();

    $tenant->run(function () {
        $backup = Backup::query()->latest()->first();

        expect($backup)->not->toBeNull()
            ->and($backup->status)->toBe('completed')
            ->and($backup->disk)->toBe('local')
            ->and($backup->size_bytes)->toBeGreaterThan(0)
            ->and($backup->created_by)->not->toBeNull();

        $path = 'backups/'.tenant('id').'/'.$backup->filename;

        expect(Storage::disk('local')->exists($path))->toBeTrue();
    });

    $tenant->delete();
});

/**
 * Regression test for the exact bug legacy (day_khata's
 * databasebackupcontroller.php) had: backup files were written under
 * public_path('backup/'), which the webserver serves directly with zero
 * auth. This app's backups must never resolve to a path under public_path().
 */
test('a backup file is never stored under the public webroot', function () {
    $domain = 'backup-not-public.tenant-test';
    $tenant = provisionBackupTestTenant($domain);
    loginAsBackupAdmin($domain);

    test()->post("http://{$domain}/backups");

    $tenant->run(function () {
        $backup = Backup::query()->latest()->firstOrFail();
        $path = 'backups/'.tenant('id').'/'.$backup->filename;
        $absolutePath = Storage::disk('local')->path($path);

        expect(Storage::disk('local')->exists($path))->toBeTrue()
            ->and(str_starts_with($absolutePath, public_path()))->toBeFalse();
    });

    $tenant->delete();
});

test('downloading a backup streams the actual file content', function () {
    $domain = 'backup-download.tenant-test';
    $tenant = provisionBackupTestTenant($domain);
    loginAsBackupAdmin($domain);

    test()->post("http://{$domain}/backups");

    $backupId = null;
    $expectedSize = null;
    $tenant->run(function () use (&$backupId, &$expectedSize) {
        $backup = Backup::query()->latest()->firstOrFail();
        $backupId = $backup->id;
        $expectedSize = $backup->size_bytes;
    });

    $response = test()->get("http://{$domain}/backups/{$backupId}/download");

    $response->assertOk();
    expect(strlen($response->streamedContent()))->toBe($expectedSize);

    $tenant->delete();
});

test('destroying a backup removes both the database row and the underlying file', function () {
    $domain = 'backup-destroy.tenant-test';
    $tenant = provisionBackupTestTenant($domain);
    loginAsBackupAdmin($domain);

    test()->post("http://{$domain}/backups");

    $backupId = null;
    $path = null;
    $tenant->run(function () use (&$backupId, &$path) {
        $backup = Backup::query()->latest()->firstOrFail();
        $backupId = $backup->id;
        $path = 'backups/'.tenant('id').'/'.$backup->filename;
    });

    $response = test()->delete("http://{$domain}/backups/{$backupId}");
    $response->assertRedirect();

    $tenant->run(function () use ($backupId, $path) {
        expect(Backup::find($backupId))->toBeNull()
            ->and(Storage::disk('local')->exists($path))->toBeFalse();
    });

    $tenant->delete();
});

test('a non-admin cannot access any backup route', function () {
    $domain = 'backup-staff.tenant-test';
    $tenant = provisionBackupTestTenant($domain);

    $tenant->run(function () {
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
        User::factory()->create([
            'email' => 'staffer@example.com',
            'password' => 'password',
            'role_id' => $staffRole->id,
        ]);
    });

    test()->post("http://{$domain}/login", [
        'email' => 'staffer@example.com',
        'password' => 'password',
    ]);

    test()->get("http://{$domain}/backups")->assertForbidden();
    test()->post("http://{$domain}/backups")->assertForbidden();
    test()->get("http://{$domain}/backups/1/download")->assertForbidden();
    test()->delete("http://{$domain}/backups/1")->assertForbidden();

    $tenant->delete();
});
