<?php

use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/**
 * Tenancy keeps the tenant connection as the default after initialize(), so
 * revert to central after every test for RefreshDatabase's teardown (same
 * pattern as the other tenant feature tests).
 */
afterEach(function () {
    tenancy()->end();
});

/**
 * Every module except sales and the modules that require it (pos, agents,
 * quotations), so the sales module is effectively off.
 *
 * @var list<string>
 */
const GATE_TEST_MODULES_WITHOUT_SALES = ['purchases', 'inventory', 'accounting', 'reports', 'admin'];

function initializeGateTestTenant(): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Gate Co']);

    tenancy()->initialize($tenant);

    return $tenant;
}

/**
 * Change the current tenant's entitlements the way the central panel does
 * (a saved column), on the very instance tenancy holds.
 *
 * @param  list<string>  $modules
 */
function setGateTestModules(array $modules): void
{
    tenant()->update(['enabled_modules' => $modules]);
}

test('the owner is allowed every entitled catalog key, owner-only keys included', function () {
    initializeGateTestTenant();
    $owner = User::factory()->create();

    expect($owner->isOwner())->toBeTrue()
        ->and($owner->can('sales.create'))->toBeTrue()
        ->and($owner->can('purchases.view'))->toBeTrue()
        ->and($owner->can('roles.manage'))->toBeTrue()
        ->and($owner->can('backups.manage'))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('sales.view'))->toBeTrue();
});

test('the owner is denied keys of a module the tenant is not entitled to', function () {
    initializeGateTestTenant();
    $owner = User::factory()->create();

    setGateTestModules(GATE_TEST_MODULES_WITHOUT_SALES);

    expect($owner->can('sales.view'))->toBeFalse()
        ->and($owner->can('sales.create'))->toBeFalse()
        ->and($owner->can('purchases.view'))->toBeTrue()
        ->and($owner->can('roles.manage'))->toBeTrue();

    setGateTestModules([]);

    expect($owner->can('purchases.view'))->toBeFalse()
        ->and($owner->can('backups.manage'))->toBeFalse()
        ->and($owner->can('roles.manage'))->toBeTrue();
});

test('a role grant is allowed and a missing grant is denied', function () {
    initializeGateTestTenant();
    $user = userWithPermissions(['sales.view', 'sales.create']);

    expect($user->can('sales.view'))->toBeTrue()
        ->and($user->can('sales.create'))->toBeTrue()
        ->and($user->can('sales.cancel'))->toBeFalse()
        ->and($user->can('purchases.view'))->toBeFalse()
        ->and(Gate::forUser($user)->denies('sales.cancel'))->toBeTrue();
});

test('a role with null permissions grants nothing', function () {
    initializeGateTestTenant();
    $role = roleWithPermissions([]);
    $role->forceFill(['permissions' => null])->save();
    $user = User::factory()->create(['role_id' => $role->id, 'is_owner' => false]);

    expect($user->effectivePermissions())->toBe([])
        ->and($user->can('sales.view'))->toBeFalse();
});

test('owner-only keys are denied to a role even when its JSON row contains them', function () {
    initializeGateTestTenant();
    $user = userWithPermissions(['roles.manage', 'backups.manage', 'fiscal_year.close_archive', 'ownership.transfer', 'sales.view']);

    expect($user->can('roles.manage'))->toBeFalse()
        ->and($user->can('backups.manage'))->toBeFalse()
        ->and($user->can('fiscal_year.close_archive'))->toBeFalse()
        ->and($user->can('ownership.transfer'))->toBeFalse()
        ->and($user->can('sales.view'))->toBeTrue()
        ->and(array_keys($user->effectivePermissions()))->toBe(['sales.view']);
});

test('unknown keys stored in a role are ignored', function () {
    initializeGateTestTenant();
    $user = userWithPermissions(['sales.view', 'no.such.permission']);

    expect(array_keys($user->effectivePermissions()))->toBe(['sales.view']);
});

test('an inactive user is denied, including an inactive owner', function () {
    initializeGateTestTenant();
    $inactiveOwner = User::factory()->create(['is_active' => false]);
    $inactiveStaff = userWithPermissions(['sales.view']);
    $inactiveStaff->update(['is_active' => false]);

    expect($inactiveOwner->isOwner())->toBeTrue()
        ->and($inactiveOwner->can('sales.view'))->toBeFalse()
        ->and($inactiveOwner->can('roles.manage'))->toBeFalse()
        ->and($inactiveOwner->effectivePermissions())->toBe([])
        ->and($inactiveStaff->can('sales.view'))->toBeFalse()
        ->and($inactiveStaff->effectivePermissions())->toBe([]);
});

test('deactivating a user on the same instance takes effect on the next check', function () {
    initializeGateTestTenant();
    $user = userWithPermissions(['sales.view']);

    expect($user->can('sales.view'))->toBeTrue();

    $user->update(['is_active' => false]);

    expect($user->can('sales.view'))->toBeFalse();
});

test('an unknown ability is denied for owners and role users', function () {
    initializeGateTestTenant();
    $owner = User::factory()->create();
    $user = userWithPermissions(['sales.view']);

    expect(PermissionCatalog::has('no.such.ability'))->toBeFalse()
        ->and($owner->can('no.such.ability'))->toBeFalse()
        ->and($user->can('no.such.ability'))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('no.such.ability'))->toBeFalse();
});

test('tenant keys are denied outside an initialized tenant', function () {
    $tenant = initializeGateTestTenant();
    $owner = User::factory()->create();
    tenancy()->end();

    expect($owner->can('sales.view'))->toBeFalse()
        ->and($owner->effectivePermissions())->toBe([]);

    tenancy()->initialize($tenant);

    expect($owner->can('sales.view'))->toBeTrue();
});

test('the central platform-owner gate is unaffected in both directions', function () {
    $platformOwner = PlatformAdmin::factory()->create();
    $support = PlatformAdmin::factory()->support()->create();

    expect(Gate::forUser($platformOwner)->allows('platform-owner'))->toBeTrue()
        ->and(Gate::forUser($support)->allows('platform-owner'))->toBeFalse()
        ->and(Gate::forUser($platformOwner)->allows('sales.view'))->toBeFalse();

    initializeGateTestTenant();

    // A platform admin is never a tenant user: tenant keys stay denied and
    // platform-owner keeps its own answer even while a tenant is active.
    expect(Gate::forUser($platformOwner)->allows('sales.view'))->toBeFalse()
        ->and(Gate::forUser($platformOwner)->allows('platform-owner'))->toBeTrue()
        ->and(Gate::forUser($support)->allows('platform-owner'))->toBeFalse();
});

test('revoking then restoring a module restores role grants untouched', function () {
    initializeGateTestTenant();
    $user = userWithPermissions(['sales.view', 'sales.create', 'purchases.view']);

    expect($user->can('sales.view'))->toBeTrue();

    setGateTestModules(GATE_TEST_MODULES_WITHOUT_SALES);

    expect($user->can('sales.view'))->toBeFalse()
        ->and($user->can('sales.create'))->toBeFalse()
        ->and($user->can('purchases.view'))->toBeTrue()
        ->and($user->role->fresh()->permissions)->toBe(['sales.view', 'sales.create', 'purchases.view']);

    setGateTestModules(config('permissions.default_modules'));

    expect($user->can('sales.view'))->toBeTrue()
        ->and($user->can('sales.create'))->toBeTrue()
        ->and($user->can('purchases.view'))->toBeTrue()
        ->and($user->role->fresh()->permissions)->toBe(['sales.view', 'sales.create', 'purchases.view']);
});

test('effective permissions of a role user never contain keys from a disabled module', function () {
    initializeGateTestTenant();
    $user = userWithPermissions(PermissionCatalog::grantable());

    setGateTestModules(GATE_TEST_MODULES_WITHOUT_SALES);

    $disabled = ['sales', 'pos', 'agents', 'quotations'];
    $keys = array_keys($user->effectivePermissions());

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        expect(in_array(PermissionCatalog::moduleOf($key), $disabled, true))->toBeFalse()
            ->and(PermissionCatalog::isOwnerOnly($key))->toBeFalse();
    }
});

test('twenty checks issue at most one roles query', function () {
    initializeGateTestTenant();
    $created = userWithPermissions(['sales.view', 'purchases.view']);

    // A fresh instance, so the role relation is not already loaded.
    $user = User::query()->findOrFail($created->id);
    expect($user->relationLoaded('role'))->toBeFalse();

    $roleQueries = 0;
    DB::listen(function ($query) use (&$roleQueries) {
        if (preg_match('/\bfrom\s+[`"]?roles[`"]?/i', $query->sql) === 1) {
            $roleQueries++;
        }
    });

    $abilities = ['sales.view', 'purchases.view', 'sales.cancel', 'roles.manage', 'no.such.ability'];
    for ($i = 0; $i < 10; $i++) {
        $ability = $abilities[$i % count($abilities)];
        $user->can($ability);
        Gate::forUser($user)->allows($ability);
    }

    expect($roleQueries)->toBeLessThanOrEqual(1)
        ->and($user->can('sales.view'))->toBeTrue()
        ->and($user->can('sales.cancel'))->toBeFalse();
});

test('the memoized permission set is not serialized with the user', function () {
    initializeGateTestTenant();
    $user = userWithPermissions(['sales.view']);
    $user->can('sales.view');

    // The role relation is shared on purpose elsewhere (auth.user.role), so
    // look at the user's own attributes only.
    $serialized = $user->withoutRelations()->toArray();

    expect($serialized)->not->toHaveKey('effectivePermissionsMemo')
        ->and($serialized)->not->toHaveKey('effectivePermissionsFingerprint')
        ->and($serialized)->not->toHaveKey('effective_permissions')
        ->and(json_encode($serialized))->not->toContain('sales.view');
});

test('flushEffectivePermissions picks up a role edited through another instance', function () {
    initializeGateTestTenant();
    $user = userWithPermissions(['sales.view']);

    expect($user->can('sales.view'))->toBeTrue();

    $user->role()->first()->update(['permissions' => ['purchases.view']]);

    $user->flushEffectivePermissions();

    expect($user->can('sales.view'))->toBeFalse()
        ->and($user->can('purchases.view'))->toBeTrue();
});
