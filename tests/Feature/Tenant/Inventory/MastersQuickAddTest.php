<?php

use App\Models\Brand;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Quick add of item masters from another form
|--------------------------------------------------------------------------
|
| The item form's "+ New" shortcuts (category, subcategory, brand) and the
| subcategory form's "+ New category" post to the ordinary store endpoints
| with `quick_add`, which must send the user back to the page they were on
| rather than to the master's own index. Without the flag the redirect is
| unchanged - ItemTest and BrandControllerTest cover that.
|
*/

afterEach(function () {
    tenancy()->end();
});

function provisionQuickAddTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    return $tenant;
}

test('a quick-added category returns to the page it was added from', function () {
    $domain = 'quick-add-category.tenant-test';
    $tenant = provisionQuickAddTestTenant($domain);

    $this->from("http://{$domain}/items")
        ->post("http://{$domain}/item-categories", ['name' => 'Beverages', 'is_active' => true, 'quick_add' => true])
        ->assertRedirect("http://{$domain}/items")
        ->assertSessionHas('status', 'Category added.');

    $tenant->run(function () {
        expect(ItemCategory::query()->where('name', 'Beverages')->exists())->toBeTrue();
    });

    $tenant->delete();
});

test('a quick-added subcategory returns to the page it was added from', function () {
    $domain = 'quick-add-subcategory.tenant-test';
    $tenant = provisionQuickAddTestTenant($domain);

    $categoryId = null;
    $tenant->run(function () use (&$categoryId) {
        $categoryId = ItemCategory::factory()->create(['name' => 'Beverages'])->id;
    });

    $this->from("http://{$domain}/items")
        ->post("http://{$domain}/item-subcategories", [
            'item_category_id' => $categoryId,
            'name' => 'Soft drinks',
            'is_active' => true,
            'quick_add' => true,
        ])
        ->assertRedirect("http://{$domain}/items")
        ->assertSessionHas('status', 'Subcategory added.');

    $tenant->run(function () use ($categoryId) {
        expect(
            ItemSubcategory::query()->where('item_category_id', $categoryId)->where('name', 'Soft drinks')->exists()
        )->toBeTrue();
    });

    $tenant->delete();
});

test('a quick-added brand returns to the page it was added from', function () {
    $domain = 'quick-add-brand.tenant-test';
    $tenant = provisionQuickAddTestTenant($domain);

    $this->from("http://{$domain}/items")
        ->post("http://{$domain}/brands", ['name' => 'Unilever', 'is_active' => true, 'quick_add' => true])
        ->assertRedirect("http://{$domain}/items")
        ->assertSessionHas('status', 'Brand added.');

    $tenant->run(function () {
        expect(Brand::query()->where('name', 'Unilever')->exists())->toBeTrue();
    });

    $tenant->delete();
});

test('a quick add that fails validation creates nothing and reports the error', function () {
    $domain = 'quick-add-duplicate.tenant-test';
    $tenant = provisionQuickAddTestTenant($domain);

    $tenant->run(function () {
        ItemCategory::factory()->create(['name' => 'Beverages']);
    });

    $this->from("http://{$domain}/items")
        ->post("http://{$domain}/item-categories", ['name' => 'Beverages', 'is_active' => true, 'quick_add' => true])
        ->assertRedirect("http://{$domain}/items")
        ->assertSessionHasErrors('name');

    $tenant->run(function () {
        expect(ItemCategory::query()->where('name', 'Beverages')->count())->toBe(1);
    });

    $tenant->delete();
});
