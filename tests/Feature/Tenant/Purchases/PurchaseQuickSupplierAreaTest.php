<?php

use App\Models\Area;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseQuickSupplierAreaTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginPurchaseQuickSupplierAreaUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('purchases index passes areas prop and suppliers include area_id in inertia response', function () {
    $domain = 'purchases-quick-supplier-area-index.tenant-test';
    $tenant = provisionPurchaseQuickSupplierAreaTenant($domain);

    $areaId = null;
    $supplierId = null;

    $tenant->run(function () use (&$areaId, &$supplierId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $area = Area::create(['name' => 'Pokhara']);
        $areaId = $area->id;

        $supplier = Supplier::factory()->create([
            'name' => 'Himalayan Suppliers',
            'mobile_no' => '9801122334',
            'area_id' => $area->id,
        ]);
        $supplierId = $supplier->id;
    });

    loginPurchaseQuickSupplierAreaUser($domain);

    $response = $this->get("http://{$domain}/purchases");

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Tenant/Purchases/Index')
        ->has('areas')
        ->has('areas.0', fn (Assert $item) => $item
            ->where('id', $areaId)
            ->where('name', 'Pokhara')
        )
        ->has('suppliers')
        ->has('suppliers.0', fn (Assert $item) => $item
            ->where('id', $supplierId)
            ->where('name', 'Himalayan Suppliers')
            ->where('area_id', $areaId)
            ->etc()
        )
    );

    $tenant->delete();
});

test('creating a supplier with area_id persists area_id correctly', function () {
    $domain = 'purchases-quick-supplier-area-store.tenant-test';
    $tenant = provisionPurchaseQuickSupplierAreaTenant($domain);

    $areaId = null;

    $tenant->run(function () use (&$areaId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $area = Area::create(['name' => 'Biratnagar']);
        $areaId = $area->id;
    });

    loginPurchaseQuickSupplierAreaUser($domain);

    $response = $this->post("http://{$domain}/suppliers", [
        'name' => 'Eastern Distributors',
        'mobile_no' => '9812345678',
        'area_id' => $areaId,
    ]);

    $response->assertRedirect("http://{$domain}/suppliers");

    $tenant->run(function () use ($areaId) {
        $supplier = Supplier::query()->where('name', 'Eastern Distributors')->first();

        expect($supplier)->not->toBeNull();
        expect($supplier->mobile_no)->toBe('9812345678');
        expect($supplier->area_id)->toBe($areaId);
    });

    $tenant->delete();
});
