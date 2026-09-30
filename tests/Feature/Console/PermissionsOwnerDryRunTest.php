<?php

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * @param  callable(): void  $seed  Runs inside the tenant after the legacy rewind.
 */
function provisionDryRunTenant(string $company, string $domain, ?string $contactEmail, callable $seed): Tenant
{
    $tenant = Tenant::create(['company_name' => $company, 'contact_email' => $contactEmail]);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () use ($seed) {
        DB::table('roles')->whereNotIn('slug', ['admin'])->delete();
        DB::table('roles')->insert(['name' => 'Staff', 'slug' => 'staff', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('roles')->update(['permissions' => null, 'is_system' => false]);

        $seed();

        DB::table('users')->update(['is_owner' => false]);
    });

    return $tenant;
}

function dryRunUser(string $email, string $roleSlug = 'admin', bool $isActive = true): User
{
    return User::factory()->create([
        'email' => $email,
        'role_id' => (int) Role::query()->where('slug', $roleSlug)->value('id'),
        'is_active' => $isActive,
    ]);
}

test('it reports the contact email match and the fallback, writes nothing, and exits 0', function () {
    $matched = provisionDryRunTenant('Matched Co', 'dry-match.tenant-test', 'boss@example.com', function () {
        dryRunUser('first@example.com');
        dryRunUser('boss@example.com');
    });
    $fallback = provisionDryRunTenant('Fallback Co', 'dry-fallback.tenant-test', 'nobody@example.com', function () {
        dryRunUser('lowest@example.com');
        dryRunUser('second@example.com');
    });

    $this->artisan('permissions:owner-dry-run')
        ->expectsOutputToContain('Matched Co')
        ->expectsOutputToContain('boss@example.com (contact email match)')
        ->expectsOutputToContain('other active admins keeping the Admin role: #')
        ->expectsOutputToContain('lowest@example.com (lowest-id active admin)')
        ->assertExitCode(0);

    foreach ([$matched, $fallback] as $tenant) {
        $tenant->run(function () {
            expect(DB::table('users')->where('is_owner', true)->count())->toBe(0)
                ->and(DB::table('roles')->whereNotNull('permissions')->count())->toBe(0);
        });
    }

    $matched->delete();
    $fallback->delete();
});

test('it flags a tenant with no active admin and exits 1', function () {
    $tenant = provisionDryRunTenant('Orphan Co', 'dry-orphan.tenant-test', 'boss@example.com', function () {
        dryRunUser('boss@example.com', 'staff');
        dryRunUser('gone@example.com', 'admin', false);
    });

    $this->artisan('permissions:owner-dry-run')
        ->expectsOutputToContain('Orphan Co')
        ->expectsOutputToContain('FLAG: NO OWNER CANDIDATE')
        ->assertExitCode(1);

    $tenant->run(fn () => expect(DB::table('users')->where('is_owner', true)->count())->toBe(0));

    $tenant->delete();
});

test('a tenant that already has an owner is reported as ok', function () {
    $tenant = provisionDryRunTenant('Done Co', 'dry-done.tenant-test', null, function () {
        dryRunUser('only@example.com');
    });

    $tenant->run(fn () => DB::table('users')->update(['is_owner' => true]));

    $this->artisan('permissions:owner-dry-run')
        ->expectsOutputToContain('already has owner: #')
        ->assertExitCode(0);

    $tenant->delete();
});

test('the json option prints a machine readable report', function () {
    $tenant = provisionDryRunTenant('Json Co', 'dry-json.tenant-test', 'a@example.com', function () {
        dryRunUser('a@example.com');
    });

    $this->artisan('permissions:owner-dry-run', ['--json' => true])
        ->expectsOutputToContain('"reason": "contact email match"')
        ->assertExitCode(0);

    $tenant->delete();
});
