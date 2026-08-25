<?php

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tenancy has no automatic "end of request" hook outside of a real PHP-FPM
 * style process boundary, so within a single test process the tenant
 * connection stays the default connection after an HTTP call. Revert to the
 * central connection after every test so RefreshDatabase's teardown rolls
 * back the connection it actually started a transaction on.
 */
afterEach(function () {
    tenancy()->end();
});

function provisionItemTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('an authenticated user can create an item category', function () {
    $domain = 'item-category-store.tenant-test';
    $tenant = provisionItemTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->post("http://{$domain}/item-categories", [
        'name' => 'Beverages',
        'is_active' => true,
    ]);

    $response->assertRedirect("http://{$domain}/item-categories");

    $tenant->run(function () {
        expect(ItemCategory::query()->where('name', 'Beverages')->exists())->toBeTrue();
    });

    $tenant->delete();
});

test('a subcategory must belong to a category', function () {
    $domain = 'item-subcategory-scope.tenant-test';
    $tenant = provisionItemTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        ItemCategory::factory()->create(['name' => 'Groceries']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $categoryId = null;
    $tenant->run(function () use (&$categoryId) {
        $categoryId = ItemCategory::query()->where('name', 'Groceries')->value('id');
    });

    $response = $this->post("http://{$domain}/item-subcategories", [
        'item_category_id' => $categoryId,
        'name' => 'Snacks',
    ]);

    $response->assertRedirect("http://{$domain}/item-subcategories");

    $tenant->run(function () use ($categoryId) {
        expect(
            ItemSubcategory::query()->where('item_category_id', $categoryId)->where('name', 'Snacks')->exists()
        )->toBeTrue();
    });

    $tenant->delete();
});

test('an item requires an existing category', function () {
    $domain = 'item-requires-category.tenant-test';
    $tenant = provisionItemTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->post("http://{$domain}/items", [
        'name' => 'Mystery Item',
        'unit' => 'pcs',
    ]);

    $response->assertSessionHasErrors('item_category_id');

    $tenant->delete();
});

test('an item subcategory from a different category is rejected', function () {
    $domain = 'item-subcategory-mismatch.tenant-test';
    $tenant = provisionItemTestTenant($domain);

    $categoryAId = null;
    $subcategoryBId = null;
    $tenant->run(function () use (&$categoryAId, &$subcategoryBId) {
        User::factory()->create(['email' => 'owner@example.com']);

        $categoryA = ItemCategory::factory()->create(['name' => 'Category A']);
        $categoryB = ItemCategory::factory()->create(['name' => 'Category B']);
        $subcategoryB = ItemSubcategory::factory()->create([
            'item_category_id' => $categoryB->id,
            'name' => 'Subcategory Under B',
        ]);

        $categoryAId = $categoryA->id;
        $subcategoryBId = $subcategoryB->id;
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryAId,
        'item_subcategory_id' => $subcategoryBId,
        'name' => 'Mismatched Item',
        'unit' => 'pcs',
    ]);

    $response->assertSessionHasErrors('item_subcategory_id');

    $tenant->delete();
});

test('an authenticated user can create, update, and delete an item', function () {
    $domain = 'item-crud.tenant-test';
    $tenant = provisionItemTestTenant($domain);

    $categoryId = null;
    $tenant->run(function () use (&$categoryId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Electronics'])->id;
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $store = $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'LED Bulb',
        'unit' => 'pcs',
        'is_vatable' => true,
    ]);
    $store->assertRedirect("http://{$domain}/items");

    $itemId = null;
    $tenant->run(function () use (&$itemId) {
        $item = Item::query()->where('name', 'LED Bulb')->firstOrFail();
        $itemId = $item->id;
        expect($item->is_vatable)->toBeTrue();
    });

    $update = $this->put("http://{$domain}/items/{$itemId}", [
        'item_category_id' => $categoryId,
        'name' => 'LED Bulb 9W',
        'unit' => 'pcs',
    ]);
    $update->assertRedirect("http://{$domain}/items");

    $tenant->run(function () use ($itemId) {
        expect(Item::query()->findOrFail($itemId)->name)->toBe('LED Bulb 9W');
    });

    $destroy = $this->delete("http://{$domain}/items/{$itemId}");
    $destroy->assertRedirect("http://{$domain}/items");

    $tenant->run(function () use ($itemId) {
        expect(Item::query()->find($itemId))->toBeNull();
    });

    $tenant->delete();
});
