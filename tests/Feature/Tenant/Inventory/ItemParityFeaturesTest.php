<?php

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemUnit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionItemParityTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginItemParityTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

/*
|--------------------------------------------------------------------------
| Unique item names, case-insensitive (item 6)
|--------------------------------------------------------------------------
*/

test('an item name that only differs by case is rejected as a duplicate', function () {
    $domain = 'item-name-case-insensitive.tenant-test';
    $tenant = provisionItemParityTestTenant($domain);

    $categoryId = null;
    $tenant->run(function () use (&$categoryId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Groceries'])->id;
        Item::factory()->create(['item_category_id' => $categoryId, 'name' => 'Coke 500ml']);
    });

    loginItemParityTestUser($domain);

    $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'COKE 500ML',
        'unit' => 'pcs',
    ])->assertSessionHasErrors('name');

    $tenant->run(function () {
        expect(Item::query()->count())->toBe(1);
    });

    $tenant->delete();
});

test('an item keeps its own name on update without tripping the uniqueness check on itself', function () {
    $domain = 'item-name-self-update.tenant-test';
    $tenant = provisionItemParityTestTenant($domain);

    $categoryId = null;
    $itemId = null;
    $tenant->run(function () use (&$categoryId, &$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Groceries'])->id;
        $itemId = Item::factory()->create(['item_category_id' => $categoryId, 'name' => 'Coke 500ml'])->id;
    });

    loginItemParityTestUser($domain);

    $this->put("http://{$domain}/items/{$itemId}", [
        'item_category_id' => $categoryId,
        'name' => 'coke 500ml',
        'unit' => 'pcs',
        'min_stock' => '5',
    ])->assertRedirect("http://{$domain}/items");

    $tenant->run(function () use ($itemId) {
        expect(Item::findOrFail($itemId)->min_stock)->toBe('5.00');
    });

    $tenant->delete();
});

/*
|--------------------------------------------------------------------------
| Base-unit MRP (item 6)
|--------------------------------------------------------------------------
*/

test('an item can be created with a base-unit MRP', function () {
    $domain = 'item-mrp.tenant-test';
    $tenant = provisionItemParityTestTenant($domain);

    $categoryId = null;
    $tenant->run(function () use (&$categoryId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Groceries'])->id;
    });

    loginItemParityTestUser($domain);

    $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'Bottled Water 1L',
        'unit' => 'pcs',
        'mrp' => '25.00',
    ])->assertRedirect("http://{$domain}/items");

    $tenant->run(function () {
        expect(Item::where('name', 'Bottled Water 1L')->value('mrp'))->toBe('25.0000');
    });

    $tenant->delete();
});

/*
|--------------------------------------------------------------------------
| Bulk "mark vatable" (item 6)
|--------------------------------------------------------------------------
*/

test('bulk mark vatable updates exactly the selected items', function () {
    $domain = 'item-bulk-mark-vatable.tenant-test';
    $tenant = provisionItemParityTestTenant($domain);

    $itemAId = null;
    $itemBId = null;
    $itemCId = null;
    $tenant->run(function () use (&$itemAId, &$itemBId, &$itemCId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Groceries'])->id;

        $itemAId = Item::factory()->create(['item_category_id' => $categoryId, 'name' => 'Item A', 'is_vatable' => false])->id;
        $itemBId = Item::factory()->create(['item_category_id' => $categoryId, 'name' => 'Item B', 'is_vatable' => false])->id;
        $itemCId = Item::factory()->create(['item_category_id' => $categoryId, 'name' => 'Item C', 'is_vatable' => false])->id;
    });

    loginItemParityTestUser($domain);

    $this->post("http://{$domain}/items/mark-vatable", [
        'item_ids' => [$itemAId, $itemBId],
    ])->assertRedirect("http://{$domain}/items");

    $tenant->run(function () use ($itemAId, $itemBId, $itemCId) {
        expect(Item::findOrFail($itemAId)->is_vatable)->toBeTrue()
            ->and(Item::findOrFail($itemBId)->is_vatable)->toBeTrue()
            ->and(Item::findOrFail($itemCId)->is_vatable)->toBeFalse();
    });

    $tenant->delete();
});

test('bulk mark vatable requires at least one item id', function () {
    $domain = 'item-bulk-mark-vatable-empty.tenant-test';
    $tenant = provisionItemParityTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginItemParityTestUser($domain);

    $this->post("http://{$domain}/items/mark-vatable", [
        'item_ids' => [],
    ])->assertSessionHasErrors('item_ids');

    $tenant->delete();
});

/*
|--------------------------------------------------------------------------
| Per-unit barcode lookup (item 6)
|--------------------------------------------------------------------------
*/

test('the barcode lookup endpoint resolves a unit barcode to its item and specific unit', function () {
    $domain = 'item-barcode-lookup-unit.tenant-test';
    $tenant = provisionItemParityTestTenant($domain);

    $itemId = null;
    $unitId = null;
    $tenant->run(function () use (&$itemId, &$unitId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Groceries'])->id;

        $item = Item::factory()->create(['item_category_id' => $categoryId, 'name' => 'Cooking Oil', 'unit' => 'pcs', 'barcode' => '1000000000001']);
        $itemId = $item->id;

        $unit = ItemUnit::factory()->create([
            'item_id' => $item->id,
            'name' => 'Carton of 12',
            'conversion_factor' => '12',
            'barcode' => '2000000000002',
        ]);
        $unitId = $unit->id;
    });

    loginItemParityTestUser($domain);

    $response = $this->getJson("http://{$domain}/items/lookup-barcode?code=2000000000002");

    $response->assertOk()
        ->assertJsonPath('item.id', $itemId)
        ->assertJsonPath('item_unit_id', $unitId);

    $tenant->delete();
});

test('the barcode lookup endpoint falls back to the base-unit barcode with a null unit id', function () {
    $domain = 'item-barcode-lookup-base.tenant-test';
    $tenant = provisionItemParityTestTenant($domain);

    $itemId = null;
    $tenant->run(function () use (&$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Groceries'])->id;

        $itemId = Item::factory()->create(['item_category_id' => $categoryId, 'name' => 'Cooking Oil', 'barcode' => '1000000000001'])->id;
    });

    loginItemParityTestUser($domain);

    $response = $this->getJson("http://{$domain}/items/lookup-barcode?code=1000000000001");

    $response->assertOk()
        ->assertJsonPath('item.id', $itemId)
        ->assertJsonPath('item_unit_id', null);

    $tenant->delete();
});

test('the barcode lookup endpoint returns 404 when nothing matches', function () {
    $domain = 'item-barcode-lookup-missing.tenant-test';
    $tenant = provisionItemParityTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginItemParityTestUser($domain);

    $this->getJson("http://{$domain}/items/lookup-barcode?code=nonexistent")->assertNotFound();

    $tenant->delete();
});

test('the barcode lookup endpoint requires a code', function () {
    $domain = 'item-barcode-lookup-blank.tenant-test';
    $tenant = provisionItemParityTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginItemParityTestUser($domain);

    $this->getJson("http://{$domain}/items/lookup-barcode")->assertStatus(422);

    $tenant->delete();
});
