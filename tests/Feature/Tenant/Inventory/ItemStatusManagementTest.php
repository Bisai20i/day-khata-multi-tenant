<?php

use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemStockMovement;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionItemStatusTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('toggling item active status requires authentication', function () {
    $domain = 'item-toggle-auth.tenant-test';
    $tenant = provisionItemStatusTestTenant($domain);

    $itemId = null;
    $tenant->run(function () use (&$itemId) {
        $category = ItemCategory::factory()->create();
        $itemId = Item::factory()->create(['item_category_id' => $category->id])->id;
    });

    $response = $this->patch("http://{$domain}/items/{$itemId}/toggle-active");

    $response->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});

test('toggling item active status requires items.edit permission', function () {
    $domain = 'item-toggle-permission.tenant-test';
    $tenant = provisionItemStatusTestTenant($domain);

    $itemId = null;
    $deniedUser = null;
    $allowedUser = null;

    $tenant->run(function () use (&$itemId, &$deniedUser, &$allowedUser) {
        $category = ItemCategory::factory()->create();
        $itemId = Item::factory()->create(['item_category_id' => $category->id, 'is_active' => true])->id;
        $deniedUser = userWithPermissions(['items.view']);
        $allowedUser = userWithPermissions(['items.edit']);
    });

    // User without items.edit gets 403 Forbidden
    $this->actingAs($deniedUser, 'web')
        ->patch("http://{$domain}/items/{$itemId}/toggle-active")
        ->assertForbidden();

    $tenant->run(function () use ($itemId) {
        expect(Item::findOrFail($itemId)->is_active)->toBeTrue();
    });

    // User with items.edit is allowed
    $this->actingAs($allowedUser, 'web')
        ->patch("http://{$domain}/items/{$itemId}/toggle-active")
        ->assertSessionHas('status', '"'.Item::find($itemId)->name.'" marked as inactive.');

    $tenant->run(function () use ($itemId) {
        expect(Item::findOrFail($itemId)->is_active)->toBeFalse();
    });

    $tenant->delete();
});

test('active item with stock greater than zero cannot be deactivated', function () {
    $domain = 'item-deactivate-stocked.tenant-test';
    $tenant = provisionItemStatusTestTenant($domain);

    $itemId = null;
    $owner = null;

    $tenant->run(function () use (&$itemId, &$owner) {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $store = Store::factory()->create();
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Premium Rice',
            'unit' => 'kg',
            'is_stockable' => true,
            'is_active' => true,
        ]);
        $itemId = $item->id;

        ItemStockMovement::create([
            'item_id' => $item->id,
            'store_id' => $store->id,
            'movement_type' => StockMovementType::Opening,
            'quantity' => '10.0000',
            'date' => '2026-01-01',
            'cancelled' => false,
        ]);
    });

    $this->actingAs($owner, 'web')
        ->patch("http://{$domain}/items/{$itemId}/toggle-active")
        ->assertSessionHasErrors([
            'item' => '"Premium Rice" still has 10 kg in stock. Clear the stock first, or leave the item active.',
        ]);

    $tenant->run(function () use ($itemId) {
        expect(Item::findOrFail($itemId)->is_active)->toBeTrue();
    });

    $tenant->delete();
});

test('active item with zero stock can be deactivated', function () {
    $domain = 'item-deactivate-zero-stock.tenant-test';
    $tenant = provisionItemStatusTestTenant($domain);

    $itemId = null;
    $owner = null;

    $tenant->run(function () use (&$itemId, &$owner) {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Empty Box',
            'unit' => 'pcs',
            'is_stockable' => true,
            'is_active' => true,
        ]);
        $itemId = $item->id;
    });

    $this->actingAs($owner, 'web')
        ->patch("http://{$domain}/items/{$itemId}/toggle-active")
        ->assertSessionHas('status', '"Empty Box" marked as inactive.');

    $tenant->run(function () use ($itemId) {
        expect(Item::findOrFail($itemId)->is_active)->toBeFalse();
    });

    $tenant->delete();
});

test('inactive item can be reactivated', function () {
    $domain = 'item-reactivate.tenant-test';
    $tenant = provisionItemStatusTestTenant($domain);

    $itemId = null;
    $owner = null;

    $tenant->run(function () use (&$itemId, &$owner) {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Seasonal Gift',
            'unit' => 'pcs',
            'is_stockable' => true,
            'is_active' => false,
        ]);
        $itemId = $item->id;
    });

    $this->actingAs($owner, 'web')
        ->patch("http://{$domain}/items/{$itemId}/toggle-active")
        ->assertSessionHas('status', '"Seasonal Gift" reactivated successfully.');

    $tenant->run(function () use ($itemId) {
        expect(Item::findOrFail($itemId)->is_active)->toBeTrue();
    });

    $tenant->delete();
});
