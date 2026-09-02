<?php

use App\Models\Item;
use App\Models\ItemVariety;
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

function provisionItemVarietyTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('the item varieties index renders with items and varieties', function () {
    $domain = 'item-variety-index.tenant-test';
    $tenant = provisionItemVarietyTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['name' => 'T-Shirt']);
        ItemVariety::factory()->create(['item_id' => $item->id, 'name' => 'Red / Large']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->get("http://{$domain}/item-varieties");

    $response->assertOk();

    $tenant->delete();
});

test('an authenticated user can create an item variety', function () {
    $domain = 'item-variety-store.tenant-test';
    $tenant = provisionItemVarietyTestTenant($domain);

    $itemId = null;
    $tenant->run(function () use (&$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $itemId = Item::factory()->create(['name' => 'T-Shirt'])->id;
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->post("http://{$domain}/item-varieties", [
        'item_id' => $itemId,
        'name' => 'Red / Large',
        'sku_suffix' => 'RED-L',
        'price_adjustment' => 25.50,
        'is_active' => true,
    ]);

    $response->assertRedirect("http://{$domain}/item-varieties");

    $tenant->run(function () use ($itemId) {
        $variety = ItemVariety::query()->where('name', 'Red / Large')->firstOrFail();
        expect($variety->item_id)->toBe($itemId)
            ->and($variety->sku_suffix)->toBe('RED-L')
            ->and((float) $variety->price_adjustment)->toBe(25.50)
            ->and($variety->is_active)->toBeTrue();
    });

    $tenant->delete();
});

test('creating an item variety requires an item that actually exists', function () {
    $domain = 'item-variety-item-scope.tenant-test';
    $tenant = provisionItemVarietyTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->post("http://{$domain}/item-varieties", [
        'item_id' => 999999,
        'name' => 'Red / Large',
        'price_adjustment' => 0,
    ]);

    $response->assertSessionHasErrors('item_id');

    $tenant->delete();
});

test('creating an item variety without a name is rejected', function () {
    $domain = 'item-variety-validation.tenant-test';
    $tenant = provisionItemVarietyTestTenant($domain);

    $itemId = null;
    $tenant->run(function () use (&$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $itemId = Item::factory()->create()->id;
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->post("http://{$domain}/item-varieties", [
        'item_id' => $itemId,
        'price_adjustment' => 0,
    ]);

    $response->assertSessionHasErrors('name');

    $tenant->delete();
});

test('an authenticated user can update and delete an item variety', function () {
    $domain = 'item-variety-crud.tenant-test';
    $tenant = provisionItemVarietyTestTenant($domain);

    $itemId = null;
    $otherItemId = null;
    $varietyId = null;
    $tenant->run(function () use (&$itemId, &$otherItemId, &$varietyId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $itemId = Item::factory()->create(['name' => 'T-Shirt'])->id;
        $otherItemId = Item::factory()->create(['name' => 'Hoodie'])->id;
        $varietyId = ItemVariety::factory()->create(['item_id' => $itemId, 'name' => 'Blue / Small'])->id;
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $update = $this->put("http://{$domain}/item-varieties/{$varietyId}", [
        'item_id' => $otherItemId,
        'name' => 'Blue / Medium',
        'sku_suffix' => 'BLU-M',
        'price_adjustment' => -10,
        'is_active' => false,
    ]);
    $update->assertRedirect("http://{$domain}/item-varieties");

    $tenant->run(function () use ($varietyId, $otherItemId) {
        $variety = ItemVariety::query()->findOrFail($varietyId);
        expect($variety->name)->toBe('Blue / Medium')
            ->and($variety->item_id)->toBe($otherItemId)
            ->and((float) $variety->price_adjustment)->toBe(-10.0)
            ->and($variety->is_active)->toBeFalse();
    });

    $destroy = $this->delete("http://{$domain}/item-varieties/{$varietyId}");
    $destroy->assertRedirect("http://{$domain}/item-varieties");

    $tenant->run(function () use ($varietyId) {
        expect(ItemVariety::query()->find($varietyId))->toBeNull();
    });

    $tenant->delete();
});
