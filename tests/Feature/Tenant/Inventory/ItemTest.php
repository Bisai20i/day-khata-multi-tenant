<?php

use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use App\Models\Store;
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

test('an item can be created with a barcode, and a duplicate barcode is rejected', function () {
    $domain = 'item-barcode.tenant-test';
    $tenant = provisionItemTestTenant($domain);

    $categoryId = null;
    $tenant->run(function () use (&$categoryId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Groceries'])->id;
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $store = $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'Canned Beans',
        'unit' => 'pcs',
        'barcode' => '8901234567890',
    ]);
    $store->assertRedirect("http://{$domain}/items");

    $tenant->run(function () {
        $item = Item::query()->where('name', 'Canned Beans')->firstOrFail();
        expect($item->barcode)->toBe('8901234567890');

        // The exact lookup the barcode-aware item search relies on
        // (Sales/Purchases Create.vue's Combobox searchValue, Pos.vue's
        // scan-to-add) - confirms a barcode round-trips to a real,
        // findable row rather than only being stored.
        expect(Item::query()->where('barcode', '8901234567890')->first()?->name)->toBe('Canned Beans');
    });

    $duplicate = $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'Another Item',
        'unit' => 'pcs',
        'barcode' => '8901234567890',
    ]);
    $duplicate->assertSessionHasErrors('barcode');

    // Multiple items with no barcode at all must not collide with each
    // other under the unique index (SQLite allows multiple NULLs).
    $first = $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'No Barcode One',
        'unit' => 'pcs',
    ]);
    $first->assertRedirect("http://{$domain}/items");

    $second = $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'No Barcode Two',
        'unit' => 'pcs',
    ]);
    $second->assertRedirect("http://{$domain}/items");

    $tenant->run(function () {
        expect(Item::query()->whereNull('barcode')->count())->toBe(2);
    });

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

/*
|--------------------------------------------------------------------------
| Posting account, deactivation and deletion guards (audit P1/P3)
|--------------------------------------------------------------------------
|
| An item's `account_id` decides which ledger account buying it is debited
| to, and there was no way to set it in the UI at all, so a service item and
| a capital item both landed in EXE8 "Purchases Account". It is offered and
| validated as an Expense or Fixed Asset account only: those are the only
| two things buying an item can be.
|
| The other two guards are about stock that cannot be reached: an inactive
| item disappears from every picker, so deactivating one that still holds
| stock strands that quantity in the valuation, and deleting an item any
| document references used to render a raw SQL exception page.
|
*/

test('an item can be given an expense posting account', function () {
    $domain = 'item-posting-account.tenant-test';
    $tenant = provisionItemTestTenant($domain);

    $categoryId = null;
    $accountId = null;
    $tenant->run(function () use (&$categoryId, &$accountId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Services'])->id;
        $accountId = Account::where('code', 'EXE8')->value('id');
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'account_id' => $accountId,
        'name' => 'Annual Audit Fee',
        'unit' => 'pcs',
        'is_stockable' => false,
    ])->assertRedirect("http://{$domain}/items");

    $tenant->run(function () use ($accountId) {
        expect(Item::where('name', 'Annual Audit Fee')->value('account_id'))->toBe($accountId);
    });

    $tenant->delete();
});

test('an item cannot post to an account that is neither an expense nor a fixed asset', function () {
    $domain = 'item-posting-account-invalid.tenant-test';
    $tenant = provisionItemTestTenant($domain);

    $categoryId = null;
    $accountId = null;
    $tenant->run(function () use (&$categoryId, &$accountId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Groceries'])->id;
        // CA2 "Profit & Loss" is an equity account: a real account id, so a
        // plain exists:accounts,id rule would have let it through.
        $accountId = Account::where('code', 'CA2')->value('id');
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'account_id' => $accountId,
        'name' => 'Wrongly Posted Item',
        'unit' => 'pcs',
    ])->assertSessionHasErrors('account_id');

    $tenant->run(function () {
        expect(Item::where('name', 'Wrongly Posted Item')->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('an item still holding stock cannot be deactivated', function () {
    $domain = 'item-deactivate-with-stock.tenant-test';
    $tenant = provisionItemTestTenant($domain);

    $itemId = null;
    $categoryId = null;
    $tenant->run(function () use (&$itemId, &$categoryId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $category = ItemCategory::factory()->create(['name' => 'Groceries']);
        $categoryId = $category->id;

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Stocked Item',
            'unit' => 'pcs',
            'is_stockable' => true,
            'is_active' => true,
        ]);
        $itemId = $item->id;

        $item->recordStockMovement(
            StockMovementType::Opening,
            '5',
            '2026-01-01',
            (int) Store::where('is_active', true)->orderBy('id')->value('id'),
        );
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $this->put("http://{$domain}/items/{$itemId}", [
        'item_category_id' => $categoryId,
        'name' => 'Stocked Item',
        'unit' => 'pcs',
        'is_stockable' => true,
        'is_active' => false,
    ])->assertSessionHasErrors('is_active');

    $tenant->run(function () use ($itemId) {
        expect(Item::find($itemId)->is_active)->toBeTrue();
    });

    $tenant->delete();
});

test('deleting an item a stock movement references gives a friendly error, not a database exception', function () {
    $domain = 'item-delete-referenced.tenant-test';
    $tenant = provisionItemTestTenant($domain);

    $itemId = null;
    $tenant->run(function () use (&$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);

        $item = Item::factory()->create(['name' => 'Referenced Item', 'is_stockable' => true]);
        $itemId = $item->id;

        $item->recordStockMovement(
            StockMovementType::Opening,
            '5',
            '2026-01-01',
            (int) Store::where('is_active', true)->orderBy('id')->value('id'),
        );
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $this->delete("http://{$domain}/items/{$itemId}")->assertSessionHasErrors('item');

    $tenant->run(function () use ($itemId) {
        expect(Item::find($itemId))->not->toBeNull();
    });

    $tenant->delete();
});
