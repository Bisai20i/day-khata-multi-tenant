<?php

use App\Enums\TenantStatus;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

afterEach(function () {
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

/**
 * Constructed directly rather than through the real provisioning HTTP flow -
 * these tests only care about Tenant::index()'s own search/pagination
 * behaviour, not provisioning itself. Queue::fake() keeps the tenant at
 * Active without ever touching the real TenantCreated pipeline, same
 * technique ProvisioningFailureTest/TenantSuspensionTest already use.
 */
function makeIndexTestTenant(string $companyName, ?string $domain = null, ?string $contactEmail = null): Tenant
{
    Queue::fake();

    $tenant = new Tenant([
        'company_name' => $companyName,
        'status' => TenantStatus::Active,
        'contact_email' => $contactEmail,
    ]);
    $tenant->save();

    if ($domain !== null) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    return $tenant->fresh();
}

test('a guest cannot access the tenant list', function () {
    $this->get(route('central.tenants.index'))->assertRedirect(route('login'));
});

test('searching by company name narrows results', function () {
    $admin = PlatformAdmin::factory()->create();
    makeIndexTestTenant('Acme Inc', 'acme.localhost');
    makeIndexTestTenant('Globex Inc', 'globex.localhost');

    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.index', ['search' => 'Acme']))
        ->assertInertia(fn ($page) => $page
            ->where('tenants.total', 1)
            ->where('tenants.data.0.company_name', 'Acme Inc'));
});

test('searching by domain narrows results', function () {
    $admin = PlatformAdmin::factory()->create();
    makeIndexTestTenant('Acme Inc', 'acme.localhost');
    makeIndexTestTenant('Globex Inc', 'globex.localhost');

    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.index', ['search' => 'globex']))
        ->assertInertia(fn ($page) => $page
            ->where('tenants.total', 1)
            ->where('tenants.data.0.company_name', 'Globex Inc'));
});

test('searching by contact email narrows results', function () {
    $admin = PlatformAdmin::factory()->create();
    makeIndexTestTenant('Acme Inc', 'acme.localhost', 'billing@acme.test');
    makeIndexTestTenant('Globex Inc', 'globex.localhost', 'billing@globex.test');

    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.index', ['search' => 'billing@acme.test']))
        ->assertInertia(fn ($page) => $page
            ->where('tenants.total', 1)
            ->where('tenants.data.0.company_name', 'Acme Inc'));
});

test('a search with no matches returns an empty page, not an error', function () {
    $admin = PlatformAdmin::factory()->create();
    makeIndexTestTenant('Acme Inc', 'acme.localhost');

    $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.index', ['search' => 'nonexistent-co']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tenants.total', 0));
});

test('pagination works: a second page is reachable', function () {
    $admin = PlatformAdmin::factory()->create();

    for ($i = 1; $i <= 30; $i++) {
        makeIndexTestTenant("Tenant {$i}", "tenant{$i}.localhost");
    }

    $firstPage = $this->actingAs($admin, 'platform')->get(route('central.tenants.index'));
    $firstPage->assertOk();
    $firstPage->assertInertia(fn ($page) => $page
        ->where('tenants.total', 30)
        ->where('tenants.last_page', 2)
        ->has('tenants.data', 25));

    $secondPage = $this->actingAs($admin, 'platform')
        ->get(route('central.tenants.index', ['page' => 2]));
    $secondPage->assertOk();
    $secondPage->assertInertia(fn ($page) => $page
        ->where('tenants.current_page', 2)
        ->has('tenants.data', 5));
});
