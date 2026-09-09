<?php

use App\Models\Brand;
use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Brand CRUD
|--------------------------------------------------------------------------
|
| Covers the audit gap in legacy day_khata's misleadingly-named
| CompanyController (its own UI labelled this "Add Item Brand") - see
| BrandController's docblock. Mirrors ItemCategoryController's CRUD test
| shape (see SupplierTest for the closest existing analog of this style).
|
*/

afterEach(function () {
    tenancy()->end();
});

function provisionBrandTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('an authenticated user can create, update, and delete a brand', function () {
    $domain = 'brand-crud.tenant-test';
    $tenant = provisionBrandTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $store = $this->post("http://{$domain}/brands", [
        'name' => 'Unilever',
        'is_active' => true,
    ]);
    $store->assertRedirect("http://{$domain}/brands");

    $brandId = null;
    $tenant->run(function () use (&$brandId) {
        $brand = Brand::query()->where('name', 'Unilever')->firstOrFail();
        $brandId = $brand->id;
        expect($brand->is_active)->toBeTrue();
    });

    $update = $this->put("http://{$domain}/brands/{$brandId}", [
        'name' => 'Unilever Nepal',
        'is_active' => false,
    ]);
    $update->assertRedirect("http://{$domain}/brands");

    $tenant->run(function () use ($brandId) {
        $brand = Brand::query()->findOrFail($brandId);
        expect($brand->name)->toBe('Unilever Nepal')
            ->and($brand->is_active)->toBeFalse();
    });

    $destroy = $this->delete("http://{$domain}/brands/{$brandId}");
    $destroy->assertRedirect("http://{$domain}/brands");

    $tenant->run(function () use ($brandId) {
        expect(Brand::query()->find($brandId))->toBeNull();
    });

    $tenant->delete();
});

test('two brands cannot share the same name', function () {
    $domain = 'brand-unique-name.tenant-test';
    $tenant = provisionBrandTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Brand::factory()->create(['name' => 'Nestle']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $this->post("http://{$domain}/brands", ['name' => 'Nestle', 'is_active' => true])
        ->assertSessionHasErrors('name');
});

test('deleting a brand nulls out brand_id on items that used it, rather than blocking the delete', function () {
    $domain = 'brand-delete-nulls-items.tenant-test';
    $tenant = provisionBrandTestTenant($domain);

    $itemId = null;
    $brandId = null;
    $tenant->run(function () use (&$itemId, &$brandId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $brand = Brand::factory()->create(['name' => 'Dabur']);
        $item = Item::factory()->create(['brand_id' => $brand->id]);
        $brandId = $brand->id;
        $itemId = $item->id;
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $this->delete("http://{$domain}/brands/{$brandId}")->assertRedirect("http://{$domain}/brands");

    $tenant->run(function () use ($itemId) {
        expect(Item::query()->findOrFail($itemId)->brand_id)->toBeNull();
    });

    $tenant->delete();
});
