<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

uses(RefreshDatabase::class);

/**
 * Persists a tenant row without provisioning its database: only the
 * TenantCreated event (which drives CreateDatabase/migrations) is faked,
 * Eloquent's creating hook still runs.
 */
function createModulesTestTenant(array $attributes = []): Tenant
{
    Event::fake([TenantCreated::class]);

    return Tenant::create(['company_name' => 'Modules Co', ...$attributes]);
}

test('a NULL enabled_modules means core only (fail closed)', function () {
    $tenant = new Tenant;
    $tenant->enabled_modules = null;

    expect($tenant->entitledModules())->toBe(['core'])
        ->and($tenant->hasModule('core'))->toBeTrue()
        ->and($tenant->hasModule('sales'))->toBeFalse();
});

test('an explicit list resolves dependencies', function () {
    $tenant = new Tenant;
    $tenant->enabled_modules = ['pos'];

    expect($tenant->entitledModules())->toContain('core', 'sales', 'pos')
        ->and($tenant->hasModule('sales'))->toBeTrue()
        ->and($tenant->hasModule('purchases'))->toBeFalse();
});

test('unknown module keys are ignored', function () {
    $tenant = new Tenant;
    $tenant->enabled_modules = ['bogus', 'purchases'];

    expect($tenant->entitledModules())->not->toContain('bogus')
        ->and($tenant->hasModule('bogus'))->toBeFalse()
        ->and($tenant->hasModule('purchases'))->toBeTrue();
});

test('the creating hook enables every default module when unset', function () {
    $tenant = createModulesTestTenant();

    foreach (config('permissions.default_modules') as $module) {
        expect($tenant->hasModule($module))->toBeTrue();
    }

    expect(Tenant::find($tenant->id)->enabled_modules)->toEqual(config('permissions.default_modules'));
});

test('the creating hook respects an explicit list', function () {
    $tenant = createModulesTestTenant(['enabled_modules' => ['sales']]);

    $fresh = Tenant::find($tenant->id);

    expect($fresh->enabled_modules)->toBe(['sales'])
        ->and($fresh->hasModule('sales'))->toBeTrue()
        ->and($fresh->hasModule('purchases'))->toBeFalse();
});

test('an explicit empty list means core only and is not defaulted', function () {
    $tenant = createModulesTestTenant(['enabled_modules' => []]);

    $fresh = Tenant::find($tenant->id);

    expect($fresh->enabled_modules)->toBe([])
        ->and($fresh->entitledModules())->toBe(['core']);
});

test('hasModule core is always true', function () {
    expect((new Tenant)->hasModule('core'))->toBeTrue()
        ->and(createModulesTestTenant(['enabled_modules' => []])->hasModule('core'))->toBeTrue();
});

test('the value survives a fresh model load', function () {
    $tenant = createModulesTestTenant(['enabled_modules' => ['pos', 'reports']]);

    $fresh = Tenant::find($tenant->id);

    expect($fresh->enabled_modules)->toBe(['pos', 'reports'])
        ->and($fresh->entitledModules())->toContain('core', 'sales', 'pos', 'reports');
});
