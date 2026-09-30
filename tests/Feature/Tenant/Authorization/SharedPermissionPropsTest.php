<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * Every module except inventory (nothing requires it), so inventory keys must
 * be absent from the shared list for everyone, the owner included.
 *
 * @var list<string>
 */
const SHARED_PROPS_MODULES_WITHOUT_INVENTORY = ['sales', 'purchases', 'accounting', 'reports', 'admin'];

function sharedPropsTenant(string $domain): Tenant
{
    $tenant = Tenant::create([
        'company_name' => 'Props Co',
        'enabled_modules' => SHARED_PROPS_MODULES_WITHOUT_INVENTORY,
    ]);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function signInAsSharedPropsUser(string $domain, string $email): void
{
    test()->post("http://{$domain}/login", ['email' => $email, 'password' => 'password']);
}

test('a role user shares the sorted intersection of the role grants and the entitled modules', function () {
    $domain = 'shared-props-role.tenant-test';
    $tenant = sharedPropsTenant($domain);

    $tenant->run(function () {
        $user = userWithPermissions([
            'sales.view',
            'purchases.view',
            'day_book.view',
            'stores.view',
            'roles.manage',
            'backups.manage',
            'fiscal_year.close_archive',
        ]);
        $user->forceFill(['email' => 'cashier@example.com'])->save();
    });

    signInAsSharedPropsUser($domain, 'cashier@example.com');

    $response = $this->get("http://{$domain}/dashboard");
    $response->assertOk();

    expect($response->inertiaProps('auth.can'))->toBe(['day_book.view', 'purchases.view', 'sales.view'])
        ->and($response->inertiaProps('auth.isOwner'))->toBeFalse();

    $tenant->delete();
});

test('the owner shares every entitled key, owner-only ones included, and none of a disabled module', function () {
    $domain = 'shared-props-owner.tenant-test';
    $tenant = sharedPropsTenant($domain);

    $tenant->run(fn () => User::factory()->create(['email' => 'owner@example.com']));

    signInAsSharedPropsUser($domain, 'owner@example.com');

    $response = $this->get("http://{$domain}/dashboard");
    $response->assertOk();

    $can = $response->inertiaProps('auth.can');

    expect($response->inertiaProps('auth.isOwner'))->toBeTrue()
        ->and($can)->toContain('roles.manage', 'backups.manage', 'fiscal_year.close_archive', 'sales.view', 'day_book.view')
        ->and($can)->not->toContain('stores.view')
        ->and($can)->not->toContain('stock_transfers.view');

    foreach ($can as $key) {
        expect(PermissionCatalog::moduleOf($key))->not->toBe('inventory');
    }

    $sorted = $can;
    sort($sorted);
    expect($can)->toBe($sorted);

    $tenant->delete();
});

test('rendering the dashboard issues at most one query against roles', function () {
    $domain = 'shared-props-queries.tenant-test';
    $tenant = sharedPropsTenant($domain);

    $tenant->run(function () {
        $user = userWithPermissions(['sales.view', 'purchases.view']);
        $user->forceFill(['email' => 'cashier@example.com'])->save();
    });

    signInAsSharedPropsUser($domain, 'cashier@example.com');

    $roleQueries = 0;
    DB::listen(function ($query) use (&$roleQueries) {
        if (preg_match('/\bfrom\s+[`"]?roles[`"]?/i', $query->sql) === 1) {
            $roleQueries++;
        }
    });

    $this->get("http://{$domain}/dashboard")->assertOk();

    expect($roleQueries)->toBeLessThanOrEqual(1);

    $tenant->delete();
});
