<?php

use App\Enums\InventoryClass;
use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/*
|--------------------------------------------------------------------------
| Item inventory class on the item form and import (manufacturing MC1)
|--------------------------------------------------------------------------
|
| Trading by default; raw material and finished good items must be
| stockable; and the class is fixed once the item has any stock movement,
| cancelled ones included, because its value already sits in one account.
|
*/

function provisionItemClassTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * Creates the owner and a category, logs in, and returns the category id.
 */
function itemClassLogin(object $test, Tenant $tenant, string $domain): int
{
    $categoryId = $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);

        return ItemCategory::factory()->create(['name' => 'Materials'])->id;
    });

    $test->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    return $categoryId;
}

test('an item is created as trading by default and as raw material when chosen', function () {
    $domain = 'item-class-create.tenant-test';
    $tenant = provisionItemClassTenant($domain);
    $categoryId = itemClassLogin($this, $tenant, $domain);

    $this->post("http://{$domain}/items", ['item_category_id' => $categoryId, 'name' => 'Shop Item', 'unit' => 'pcs'])
        ->assertRedirect("http://{$domain}/items");

    $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'Cotton Yarn',
        'unit' => 'kg',
        'is_stockable' => true,
        'inventory_class' => 'raw_material',
    ])->assertRedirect("http://{$domain}/items");

    $tenant->run(function () {
        expect(Item::where('name', 'Shop Item')->firstOrFail()->inventory_class)->toBe(InventoryClass::Trading)
            ->and(Item::where('name', 'Cotton Yarn')->firstOrFail()->inventory_class)->toBe(InventoryClass::RawMaterial);
    });

    $tenant->delete();
});

test('an unknown class is rejected', function () {
    $domain = 'item-class-unknown.tenant-test';
    $tenant = provisionItemClassTenant($domain);
    $categoryId = itemClassLogin($this, $tenant, $domain);

    $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'Odd Item',
        'unit' => 'pcs',
        'inventory_class' => 'work_in_process',
    ])->assertSessionHasErrors('inventory_class');

    $tenant->run(fn () => expect(Item::where('name', 'Odd Item')->exists())->toBeFalse());

    $tenant->delete();
});

test('a raw material or finished good item must be stockable', function () {
    $domain = 'item-class-stockable.tenant-test';
    $tenant = provisionItemClassTenant($domain);
    $categoryId = itemClassLogin($this, $tenant, $domain);

    $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'Packed Biscuit',
        'unit' => 'pcs',
        'is_stockable' => false,
        'inventory_class' => 'finished_good',
    ])->assertSessionHasErrors('is_stockable');

    $itemId = $tenant->run(fn () => Item::factory()->rawMaterial()->create(['item_category_id' => $categoryId])->id);

    // Unticking stockable on an existing raw item is refused too, even when
    // the form does not resend the class.
    $item = $tenant->run(fn () => Item::findOrFail($itemId));
    $this->put("http://{$domain}/items/{$itemId}", [
        'item_category_id' => $categoryId,
        'name' => $item->name,
        'unit' => $item->unit,
        'is_stockable' => false,
    ])->assertSessionHasErrors('is_stockable');

    $tenant->run(function () use ($itemId) {
        expect(Item::where('name', 'Packed Biscuit')->exists())->toBeFalse()
            ->and(Item::findOrFail($itemId)->is_stockable)->toBeTrue();
    });

    $tenant->delete();
});

test('the class can change while the item has never moved', function () {
    $domain = 'item-class-change-allowed.tenant-test';
    $tenant = provisionItemClassTenant($domain);
    $categoryId = itemClassLogin($this, $tenant, $domain);

    $item = $tenant->run(fn () => Item::factory()->create(['item_category_id' => $categoryId, 'inventory_class' => InventoryClass::Trading]));

    $this->put("http://{$domain}/items/{$item->id}", [
        'item_category_id' => $categoryId,
        'name' => $item->name,
        'unit' => $item->unit,
        'is_stockable' => true,
        'inventory_class' => 'finished_good',
    ])->assertSessionHasNoErrors();

    $tenant->run(fn () => expect(Item::findOrFail($item->id)->inventory_class)->toBe(InventoryClass::FinishedGood));

    $tenant->delete();
});

test('a class change is rejected after the first movement, even a cancelled one', function () {
    $domain = 'item-class-change-locked.tenant-test';
    $tenant = provisionItemClassTenant($domain);
    $categoryId = itemClassLogin($this, $tenant, $domain);

    $item = $tenant->run(function () use ($categoryId) {
        $item = Item::factory()->create(['item_category_id' => $categoryId, 'inventory_class' => InventoryClass::Trading]);
        $storeId = (int) Store::where('is_active', true)->orderBy('id')->value('id');

        $movement = $item->recordStockMovement(StockMovementType::Purchase, '5', '2026-06-01', $storeId, value: '500.00');
        $movement->update(['cancelled' => true]);

        return $item;
    });

    $this->put("http://{$domain}/items/{$item->id}", [
        'item_category_id' => $categoryId,
        'name' => $item->name,
        'unit' => $item->unit,
        'is_stockable' => true,
        'inventory_class' => 'raw_material',
    ])->assertSessionHasErrors('inventory_class');

    // Saving with the same class (or without one) is still fine.
    $this->put("http://{$domain}/items/{$item->id}", [
        'item_category_id' => $categoryId,
        'name' => 'Renamed Item',
        'unit' => $item->unit,
        'inventory_class' => 'trading',
    ])->assertSessionHasNoErrors();

    $tenant->run(function () use ($item) {
        $fresh = Item::findOrFail($item->id);

        expect($fresh->inventory_class)->toBe(InventoryClass::Trading)
            ->and($fresh->name)->toBe('Renamed Item');
    });

    $tenant->delete();
});

test('the items page reports which items have moved and offers the classes', function () {
    $domain = 'item-class-index.tenant-test';
    $tenant = provisionItemClassTenant($domain);
    $categoryId = itemClassLogin($this, $tenant, $domain);

    [$movedId, $idleId] = $tenant->run(function () use ($categoryId) {
        $moved = Item::factory()->rawMaterial()->create(['item_category_id' => $categoryId]);
        $idle = Item::factory()->create(['item_category_id' => $categoryId]);
        $storeId = (int) Store::where('is_active', true)->orderBy('id')->value('id');
        $moved->recordStockMovement(StockMovementType::Purchase, '2', '2026-06-01', $storeId, value: '20.00');

        return [$moved->id, $idle->id];
    });

    $this->get("http://{$domain}/items")
        ->assertOk()
        ->assertInertia(function ($page) use ($movedId, $idleId) {
            $items = collect($page->toArray()['props']['items'])->keyBy('id');

            expect($items[$movedId]['has_stock_movements'])->toBeTrue()
                ->and($items[$idleId]['has_stock_movements'])->toBeFalse()
                ->and($items[$movedId]['inventory_class'])->toBe('raw_material')
                ->and(collect($page->toArray()['props']['inventoryClasses'])->pluck('value')->all())
                ->toBe(['trading', 'raw_material', 'finished_good']);

            return $page;
        });

    $tenant->delete();
});

test('the import reads an optional inventory_class column', function () {
    $domain = 'item-class-import.tenant-test';
    $tenant = provisionItemClassTenant($domain);
    itemClassLogin($this, $tenant, $domain);

    $csv = "name,category,unit,is_stockable,inventory_class\n"
        ."Steel Sheet,Materials,kg,yes,Raw Material\n"
        ."Steel Box,Materials,pcs,yes,finished_good\n"
        ."Plain Item,Materials,pcs,yes,\n"
        ."Bad Class,Materials,pcs,yes,semi_finished\n"
        ."Service Raw,Materials,pcs,no,raw_material\n";

    $this->post("http://{$domain}/items/import", ['file' => UploadedFile::fake()->createWithContent('items.csv', $csv)])
        ->assertSessionHas('importResult', fn (array $result) => $result['imported'] === 3 && count($result['skipped']) === 2);

    $tenant->run(function () {
        expect(Item::where('name', 'Steel Sheet')->firstOrFail()->inventory_class)->toBe(InventoryClass::RawMaterial)
            ->and(Item::where('name', 'Steel Box')->firstOrFail()->inventory_class)->toBe(InventoryClass::FinishedGood)
            ->and(Item::where('name', 'Plain Item')->firstOrFail()->inventory_class)->toBe(InventoryClass::Trading)
            ->and(Item::whereIn('name', ['Bad Class', 'Service Raw'])->exists())->toBeFalse();
    });

    $tenant->delete();
});
