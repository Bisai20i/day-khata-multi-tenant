<?php

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

function provisionItemImageTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('creating an item with an image and rates persists them', function () {
    Storage::fake('public');

    $domain = 'item-image-rate-store.tenant-test';
    $tenant = provisionItemImageTestTenant($domain);

    $categoryId = null;
    $tenant->run(function () use (&$categoryId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create(['name' => 'Groceries'])->id;
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $image = UploadedFile::fake()->image('item.jpg');

    $response = $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'Instant Noodles',
        'unit' => 'pcs',
        'purchase_rate' => '45.50',
        'sale_rate' => '60.00',
        'image' => $image,
    ]);

    $response->assertRedirect("http://{$domain}/items");

    $tenant->run(function () use ($tenant) {
        $item = Item::query()->where('name', 'Instant Noodles')->firstOrFail();

        expect((float) $item->purchase_rate)->toBe(45.50);
        expect((float) $item->sale_rate)->toBe(60.00);
        expect($item->image_path)->not->toBeNull();
        expect($item->image_path)->toStartWith('items/'.$tenant->id.'/');

        Storage::disk('public')->assertExists($item->image_path);
    });

    $tenant->delete();
});

test('an item can be created without an image or rates', function () {
    $domain = 'item-image-rate-optional.tenant-test';
    $tenant = provisionItemImageTestTenant($domain);

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
        'name' => 'Plain Item',
        'unit' => 'pcs',
    ]);

    $response->assertRedirect("http://{$domain}/items");

    $tenant->run(function () {
        $item = Item::query()->where('name', 'Plain Item')->firstOrFail();

        expect($item->purchase_rate)->toBeNull();
        expect($item->sale_rate)->toBeNull();
        expect($item->image_path)->toBeNull();
    });

    $tenant->delete();
});

test('a non-image file is rejected', function () {
    $domain = 'item-image-rejects-non-image.tenant-test';
    $tenant = provisionItemImageTestTenant($domain);

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
        'name' => 'Bad File Item',
        'unit' => 'pcs',
        'image' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
    ]);

    $response->assertSessionHasErrors('image');

    $tenant->delete();
});

test('an oversized image is rejected', function () {
    $domain = 'item-image-rejects-oversized.tenant-test';
    $tenant = provisionItemImageTestTenant($domain);

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
        'name' => 'Too Big Item',
        'unit' => 'pcs',
        'image' => UploadedFile::fake()->image('big.jpg')->size(3000),
    ]);

    $response->assertSessionHasErrors('image');

    $tenant->delete();
});

test('editing an item replaces its rates and image and removes the old file', function () {
    Storage::fake('public');

    $domain = 'item-image-rate-update.tenant-test';
    $tenant = provisionItemImageTestTenant($domain);

    $itemId = null;
    $categoryId = null;
    $originalPath = null;
    $tenant->run(function () use (&$itemId, &$categoryId, &$originalPath) {
        User::factory()->create(['email' => 'owner@example.com']);
        $category = ItemCategory::factory()->create(['name' => 'Groceries']);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Cooking Oil',
            'purchase_rate' => 100,
            'sale_rate' => 120,
            'image_path' => 'items/1/old.jpg',
        ]);
        $itemId = $item->id;
        $categoryId = $category->id;
        $originalPath = $item->image_path;

        Storage::disk('public')->put($originalPath, 'fake-image-contents');
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $newImage = UploadedFile::fake()->image('new.jpg');

    $response = $this->post("http://{$domain}/items/{$itemId}", [
        '_method' => 'PUT',
        'item_category_id' => $categoryId,
        'name' => 'Cooking Oil',
        'unit' => 'ltr',
        'purchase_rate' => '150.75',
        'sale_rate' => '180.25',
        'image' => $newImage,
    ]);

    $response->assertRedirect("http://{$domain}/items");

    $tenant->run(function () use ($itemId, $originalPath) {
        $item = Item::query()->findOrFail($itemId);

        expect((float) $item->purchase_rate)->toBe(150.75);
        expect((float) $item->sale_rate)->toBe(180.25);
        expect($item->image_path)->not->toBeNull();
        expect($item->image_path)->not->toBe($originalPath);

        Storage::disk('public')->assertExists($item->image_path);
        Storage::disk('public')->assertMissing($originalPath);
    });

    $tenant->delete();
});
