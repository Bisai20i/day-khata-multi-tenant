<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSaleQuickCustomerAreaTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('sales index passes areas and customers with area_id in inertia response', function () {
    $domain = 'sale-area-index.tenant-test';
    $tenant = provisionSaleQuickCustomerAreaTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        $area = Area::create(['name' => 'Pokhara Lakeside']);
        Customer::factory()->create(['name' => 'Hari Thapa', 'area_id' => $area->id]);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->get("http://{$domain}/sales");
    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Tenant/Sales/Index')
        ->has('areas')
        ->where('areas', fn ($areas) => collect($areas)->contains('name', 'Pokhara Lakeside'))
        ->has('customers')
        ->where('customers', fn ($customers) => collect($customers)->contains(fn ($c) => $c['name'] === 'Hari Thapa' && ! empty($c['area_id'])))
    );

    $tenant->delete();
});

test('pos index passes areas and customers with area_id in inertia response', function () {
    $domain = 'pos-area-index.tenant-test';
    $tenant = provisionSaleQuickCustomerAreaTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        $area = Area::create(['name' => 'Kathmandu Thamel']);
        Customer::factory()->create(['name' => 'Shyam Shrestha', 'area_id' => $area->id]);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->get("http://{$domain}/pos");
    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Tenant/Sales/Pos')
        ->has('areas')
        ->where('areas', fn ($areas) => collect($areas)->contains('name', 'Kathmandu Thamel'))
        ->has('customers')
        ->where('customers', fn ($customers) => collect($customers)->contains(fn ($c) => $c['name'] === 'Shyam Shrestha' && ! empty($c['area_id'])))
    );

    $tenant->delete();
});

test('creating customer with area_id persists area_id on customer', function () {
    $domain = 'customer-area-persist.tenant-test';
    $tenant = provisionSaleQuickCustomerAreaTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Area::create(['name' => 'Lalitpur Patan']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $areaId = null;
    $tenant->run(function () use (&$areaId) {
        $areaId = Area::where('name', 'Lalitpur Patan')->firstOrFail()->id;
    });

    $response = $this->post("http://{$domain}/customers", [
        'name' => 'Quick Customer',
        'mobile_no' => '9841000001',
        'area_id' => $areaId,
    ]);

    $response->assertRedirect("http://{$domain}/customers");

    $tenant->run(function () use ($areaId) {
        $customer = Customer::where('name', 'Quick Customer')->firstOrFail();
        expect($customer->area_id)->toBe($areaId);
        expect($customer->area)->not->toBeNull();
        expect($customer->area->name)->toBe('Lalitpur Patan');
    });

    $tenant->delete();
});
