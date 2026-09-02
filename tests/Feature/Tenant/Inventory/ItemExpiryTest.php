<?php

use App\Models\Item;
use App\Models\ItemCategory;
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

function provisionItemExpiryTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('creating an item with an expiry date persists it', function () {
    $domain = 'item-expiry-store.tenant-test';
    $tenant = provisionItemExpiryTestTenant($domain);

    $categoryId = null;
    $tenant->run(function () use (&$categoryId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Groceries'])->id;
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'Milk Powder',
        'unit' => 'kg',
        'expiry_date' => '2027-01-15',
    ]);

    $response->assertRedirect("http://{$domain}/items");

    $tenant->run(function () {
        $item = Item::query()->where('name', 'Milk Powder')->firstOrFail();
        expect($item->expiry_date->toDateString())->toBe('2027-01-15');
    });

    $tenant->delete();
});

test('an item can be created without an expiry date', function () {
    $domain = 'item-expiry-optional.tenant-test';
    $tenant = provisionItemExpiryTestTenant($domain);

    $categoryId = null;
    $tenant->run(function () use (&$categoryId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Groceries'])->id;
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'Plain Rice',
        'unit' => 'kg',
    ]);

    $response->assertRedirect("http://{$domain}/items");

    $tenant->run(function () {
        $item = Item::query()->where('name', 'Plain Rice')->firstOrFail();
        expect($item->expiry_date)->toBeNull();
    });

    $tenant->delete();
});

test('an invalid expiry date is rejected', function () {
    $domain = 'item-expiry-invalid.tenant-test';
    $tenant = provisionItemExpiryTestTenant($domain);

    $categoryId = null;
    $tenant->run(function () use (&$categoryId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Groceries'])->id;
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'Bad Date Item',
        'unit' => 'pcs',
        'expiry_date' => 'not-a-date',
    ]);

    $response->assertSessionHasErrors('expiry_date');

    $tenant->delete();
});

test('scopeExpired and scopeExpiringSoon apply the documented boundary rule', function () {
    $domain = 'item-expiry-scopes.tenant-test';
    $tenant = provisionItemExpiryTestTenant($domain);

    $tenant->run(function () {
        $category = ItemCategory::factory()->create(['name' => 'Groceries']);

        // An item expiring exactly today is treated as already expired, not
        // "expiring soon" - the boundary rule scopeExpired()/scopeExpiringSoon()
        // both document.
        $expiringToday = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Expires Today',
            'expiry_date' => now()->toDateString(),
        ]);

        $expiredYesterday = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Expired Yesterday',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        $expiringInTenDays = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Expiring In Ten Days',
            'expiry_date' => now()->addDays(10)->toDateString(),
        ]);

        $expiringAtThirtyDayBoundary = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Expiring At Thirty Day Boundary',
            'expiry_date' => now()->addDays(30)->toDateString(),
        ]);

        $expiringBeyondThirtyDays = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Expiring Beyond Thirty Days',
            'expiry_date' => now()->addDays(31)->toDateString(),
        ]);

        $noExpiry = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'No Expiry',
            'expiry_date' => null,
        ]);

        $expiredIds = Item::expired()->pluck('id')->all();
        expect($expiredIds)->toContain($expiringToday->id, $expiredYesterday->id);
        expect($expiredIds)->not->toContain(
            $expiringInTenDays->id,
            $expiringAtThirtyDayBoundary->id,
            $expiringBeyondThirtyDays->id,
            $noExpiry->id,
        );

        $expiringSoonIds = Item::expiringSoon()->pluck('id')->all();
        expect($expiringSoonIds)->toContain($expiringInTenDays->id, $expiringAtThirtyDayBoundary->id);
        expect($expiringSoonIds)->not->toContain(
            $expiringToday->id,
            $expiredYesterday->id,
            $expiringBeyondThirtyDays->id,
            $noExpiry->id,
        );
    });

    $tenant->delete();
});

test('scopeExpiringSoon respects a custom window', function () {
    $domain = 'item-expiry-custom-window.tenant-test';
    $tenant = provisionItemExpiryTestTenant($domain);

    $tenant->run(function () {
        $category = ItemCategory::factory()->create(['name' => 'Groceries']);

        $expiringInFiveDays = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Expiring In Five Days',
            'expiry_date' => now()->addDays(5)->toDateString(),
        ]);

        $expiringInFifteenDays = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Expiring In Fifteen Days',
            'expiry_date' => now()->addDays(15)->toDateString(),
        ]);

        $withinSevenDaysIds = Item::expiringSoon(7)->pluck('id')->all();
        expect($withinSevenDaysIds)->toContain($expiringInFiveDays->id);
        expect($withinSevenDaysIds)->not->toContain($expiringInFifteenDays->id);
    });

    $tenant->delete();
});

test('the items index page renders correctly when items have a null expiry date', function () {
    $domain = 'item-expiry-index-null.tenant-test';
    $tenant = provisionItemExpiryTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        $category = ItemCategory::factory()->create(['name' => 'Groceries']);

        Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'No Expiry Item',
            'expiry_date' => null,
        ]);

        Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Has Expiry Item',
            'expiry_date' => now()->addDays(5)->toDateString(),
        ]);
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $response = $this->get("http://{$domain}/items");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Tenant/Inventory/Items/Index')
        ->has('items', 2)
    );

    $tenant->delete();
});
