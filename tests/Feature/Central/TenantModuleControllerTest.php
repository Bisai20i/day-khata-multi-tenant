<?php

use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivityLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stancl\Tenancy\Events\TenantCreated;

uses(RefreshDatabase::class);

/**
 * Tests that hit tenant routes leave tenancy initialized, so revert to the
 * central connection for RefreshDatabase's teardown.
 */
afterEach(function () {
    tenancy()->end();
});

/**
 * A central tenant row with no tenant database: only TenantCreated (which
 * drives provisioning) is faked, so the Tenant creating hook still runs.
 * Enough for everything that only reads and writes the central row.
 */
function tenantModuleRowOnly(array $attributes = []): Tenant
{
    Event::fake([TenantCreated::class]);

    return Tenant::create(['company_name' => 'Modules Row Co', ...$attributes]);
}

/**
 * A fully provisioned tenant with a domain, for tests that need tenant users
 * or tenant routes. The caller deletes it at the end (drops its database).
 *
 * @return array{0: Tenant, 1: string}
 */
function tenantModuleProvisioned(): array
{
    $domain = 'modules-'.Str::lower(Str::random(8)).'.tenant-test';
    $tenant = Tenant::create(['company_name' => 'Modules Live Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return [$tenant, $domain];
}

/**
 * Ends tenancy first so the domain middleware resolves a fresh tenant row:
 * tenancy()->initialize() is a no-op for an already initialized tenant,
 * which would otherwise keep serving stale enabled_modules.
 */
function tenantModuleGet(string $domain, string $routeName): TestResponse
{
    tenancy()->end();

    return test()->get("http://{$domain}".route($routeName, [], false));
}

test('guests cannot update tenant modules', function () {
    $tenant = tenantModuleRowOnly();

    $this->put(route('central.tenants.modules.update', $tenant), ['enabled_modules' => []])
        ->assertRedirect(route('login'));

    expect($tenant->fresh()->enabled_modules)->toBe(config('permissions.default_modules'))
        ->and(PlatformAdminActivityLog::where('action', 'tenant.update_modules')->exists())->toBeFalse();
});

test('a tenant user cannot update modules through the central route', function () {
    [$tenant] = tenantModuleProvisioned();
    $owner = $tenant->run(fn () => User::factory()->create());

    $this->actingAs($owner, 'web')
        ->put(route('central.tenants.modules.update', $tenant), ['enabled_modules' => ['sales']])
        ->assertRedirect(route('login'));

    expect($tenant->fresh()->enabled_modules)->toBe(config('permissions.default_modules'));

    $tenant->delete();
});

test('any platform admin, support included, can update modules', function (string $state) {
    $admin = $state === 'support' ? PlatformAdmin::factory()->support()->create() : PlatformAdmin::factory()->create();
    $tenant = tenantModuleRowOnly();

    $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.modules.update', $tenant), ['enabled_modules' => ['sales', 'purchases']])
        ->assertRedirect(route('central.tenants.show', $tenant))
        ->assertSessionHas('status', 'Modules updated.');

    expect($tenant->fresh()->enabled_modules)->toBe(['sales', 'purchases']);
})->with(['owner', 'support']);

test('required modules are added server side and the list is stored in config order', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = tenantModuleRowOnly();

    // pos and quotations need sales; the client did not send it.
    $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.modules.update', $tenant), ['enabled_modules' => ['quotations', 'reports', 'pos']])
        ->assertRedirect(route('central.tenants.show', $tenant));

    $fresh = $tenant->fresh();

    expect($fresh->enabled_modules)->toBe(['sales', 'pos', 'quotations', 'reports'])
        ->and($fresh->hasModule('sales'))->toBeTrue()
        ->and($fresh->hasModule('purchases'))->toBeFalse();
});

test('core cannot be removed: an empty selection keeps core and stores no always-on module', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = tenantModuleRowOnly();

    $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.modules.update', $tenant), ['enabled_modules' => []])
        ->assertSessionHasNoErrors();

    $fresh = $tenant->fresh();

    expect($fresh->enabled_modules)->toBe([])
        ->and($fresh->entitledModules())->toBe(['core'])
        ->and($fresh->hasModule('core'))->toBeTrue();

    // Sending core explicitly is accepted and never stored.
    $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.modules.update', $tenant), ['enabled_modules' => ['core', 'admin']])
        ->assertSessionHasNoErrors();

    expect($tenant->fresh()->enabled_modules)->toBe(['admin']);
});

test('unknown or malformed module payloads are rejected with 422 and change nothing', function (mixed $payload, string $errorKey) {
    $admin = PlatformAdmin::factory()->create();
    $tenant = tenantModuleRowOnly(['enabled_modules' => ['sales']]);

    $this->actingAs($admin, 'platform')
        ->putJson(route('central.tenants.modules.update', $tenant), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($errorKey);

    expect($tenant->fresh()->enabled_modules)->toBe(['sales'])
        ->and(PlatformAdminActivityLog::where('action', 'tenant.update_modules')->exists())->toBeFalse();
})->with([
    'unknown key' => [['enabled_modules' => ['sales', 'payroll']], 'enabled_modules.1'],
    'missing field' => [[], 'enabled_modules'],
    'not an array' => [['enabled_modules' => 'sales'], 'enabled_modules'],
    'keyed object' => [['enabled_modules' => ['a' => 'sales']], 'enabled_modules'],
    'duplicate key' => [['enabled_modules' => ['sales', 'sales']], 'enabled_modules.0'],
]);

test('a module change writes a central activity entry with before and after', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = tenantModuleRowOnly(['enabled_modules' => ['sales', 'pos', 'purchases']]);

    $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.modules.update', $tenant), ['enabled_modules' => ['purchases', 'reports']]);

    $entry = PlatformAdminActivityLog::where('action', 'tenant.update_modules')
        ->where('tenant_id', $tenant->id)
        ->where('platform_admin_id', $admin->id)
        ->sole();

    // toEqual: MySQL's JSON type does not keep object key order.
    expect($entry->metadata)->toEqual([
        'before' => ['sales', 'pos', 'purchases'],
        'after' => ['purchases', 'reports'],
        'added' => ['reports'],
        'removed' => ['sales', 'pos'],
    ]);
});

test('an unchanged selection writes nothing and logs nothing', function () {
    $admin = PlatformAdmin::factory()->create();
    $tenant = tenantModuleRowOnly(['enabled_modules' => ['sales', 'pos']]);

    // Same resolved set, sent in a different order and with core.
    $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.modules.update', $tenant), ['enabled_modules' => ['pos', 'core']])
        ->assertSessionHas('status', 'Modules unchanged.');

    expect(PlatformAdminActivityLog::where('action', 'tenant.update_modules')->exists())->toBeFalse();
});

test('creating a tenant stores the explicit module selection, resolved', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')->post(route('central.tenants.store'), [
        'company_name' => 'Picky Co',
        'subdomain' => 'picky',
        'contact_email' => null,
        'admin_name' => 'Picky Admin',
        'admin_email' => 'admin@picky.test',
        'admin_password' => 'password123',
        'enabled_modules' => ['core', 'agents', 'inventory'],
    ])->assertSessionHasNoErrors();

    $tenant = Tenant::where('company_name', 'Picky Co')->firstOrFail();

    expect($tenant->enabled_modules)->toBe(['sales', 'agents', 'inventory'])
        ->and(PlatformAdminActivityLog::where('action', 'tenant.create')->sole()->metadata['enabled_modules'])
        ->toBe(['sales', 'agents', 'inventory']);

    $tenant->delete();
});

test('creating a tenant with an empty selection gives core only', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')->postJson(route('central.tenants.store'), [
        'company_name' => 'Bare Co',
        'subdomain' => 'bare',
        'contact_email' => null,
        'admin_name' => 'Bare Admin',
        'admin_email' => 'admin@bare.test',
        'admin_password' => 'password123',
        'enabled_modules' => [],
    ]);

    $tenant = Tenant::where('company_name', 'Bare Co')->firstOrFail();

    expect($tenant->enabled_modules)->toBe([])
        ->and($tenant->entitledModules())->toBe(['core']);

    $tenant->delete();
});

test('creating a tenant without a selection keeps the default modules', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')->post(route('central.tenants.store'), [
        'company_name' => 'Default Co',
        'subdomain' => 'defaultco',
        'contact_email' => null,
        'admin_name' => 'Default Admin',
        'admin_email' => 'admin@defaultco.test',
        'admin_password' => 'password123',
    ])->assertSessionHasNoErrors();

    $tenant = Tenant::where('company_name', 'Default Co')->firstOrFail();

    expect($tenant->enabled_modules)->toBe(config('permissions.default_modules'));

    $tenant->delete();
});

test('creating a tenant with an unknown module is rejected and creates nothing', function () {
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'platform')->postJson(route('central.tenants.store'), [
        'company_name' => 'Typo Co',
        'subdomain' => 'typo',
        'contact_email' => null,
        'admin_name' => 'Typo Admin',
        'admin_email' => 'admin@typo.test',
        'admin_password' => 'password123',
        'enabled_modules' => ['sales', 'payroll'],
    ])->assertStatus(422)->assertJsonValidationErrors('enabled_modules.1');

    expect(Tenant::where('company_name', 'Typo Co')->exists())->toBeFalse();
});

test('removing a module centrally blocks its routes on the tenant while other modules keep working', function () {
    [$tenant, $domain] = tenantModuleProvisioned();
    $owner = $tenant->run(fn () => User::factory()->create());
    $admin = PlatformAdmin::factory()->create();

    $withoutPurchases = array_values(array_diff(config('permissions.default_modules'), ['purchases']));

    $this->actingAs($admin, 'platform')
        ->put(route('central.tenants.modules.update', $tenant), ['enabled_modules' => $withoutPurchases])
        ->assertSessionHasNoErrors();

    // Entitlements apply to the owner too.
    $this->actingAs($owner, 'web');
    tenantModuleGet($domain, 'tenant.purchases.index')->assertForbidden();
    tenantModuleGet($domain, 'tenant.sales.index')->assertOk();

    // Switching it back on restores access with no tenant-side change.
    tenancy()->end();
    // Absolute central URL: route() would otherwise reuse the tenant host of
    // the previous request and the PUT would silently 404 on the tenant. The
    // guards are reset because actingAs() keeps the tenant web user across
    // requests in a test, which real per-domain sessions never do.
    auth()->forgetGuards();
    $this->actingAs($admin, 'platform')
        ->put('http://localhost'.route('central.tenants.modules.update', $tenant, false), ['enabled_modules' => config('permissions.default_modules')])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($owner, 'web');
    tenantModuleGet($domain, 'tenant.purchases.index')->assertOk();

    tenancy()->end();
    $tenant->delete();
});
