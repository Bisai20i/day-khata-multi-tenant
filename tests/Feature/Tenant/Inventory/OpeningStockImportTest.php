<?php

use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\StockAdjustment;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

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

function provisionOpeningStockImportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsOpeningStockImportOwner(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('an authenticated user can bulk import opening stock from a csv file', function () {
    $domain = 'opening-stock-import-success.tenant-test';
    $tenant = provisionOpeningStockImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Item::factory()->create(['name' => 'Bottled Water 1L', 'is_stockable' => true]);
        Item::factory()->create(['name' => 'Potato Chips 50g', 'is_stockable' => true]);
    });

    loginAsOpeningStockImportOwner($domain);

    $csv = "item,quantity,unit_cost_rate,remarks\n"
        ."Bottled Water 1L,100,15.00,Opening stock as of setup\n"
        ."Potato Chips 50g,50,,\n";
    $file = UploadedFile::fake()->createWithContent('opening-stock.csv', $csv);

    $response = $this->post("http://{$domain}/stock-adjustments/opening-stock/import", [
        'file' => $file,
        'date' => '2026-01-01',
    ]);

    $response->assertRedirect("http://{$domain}/stock-adjustments");
    $response->assertSessionHas('importResult', function (array $result) {
        return $result['imported'] === 2 && $result['skipped'] === [];
    });

    $tenant->run(function () {
        $water = Item::query()->where('name', 'Bottled Water 1L')->firstOrFail();
        $chips = Item::query()->where('name', 'Potato Chips 50g')->firstOrFail();

        // Same posting mechanism StockAdjustment::post() always uses:
        // exactly one 'opening' movement per item, picked up by
        // Item::currentStock().
        expect($water->currentStock())->toBe(100.0)
            ->and($chips->currentStock())->toBe(50.0);

        $waterMovement = ItemStockMovement::where('item_id', $water->id)->firstOrFail();
        expect($waterMovement->movement_type)->toBe(StockMovementType::Opening)
            ->and((float) $waterMovement->unit_cost_rate)->toBe(15.0);

        expect(StockAdjustment::query()->count())->toBe(1);
        expect(StockAdjustment::query()->first()->lines()->count())->toBe(2);
    });

    $tenant->delete();
});

test('opening stock import scopes to the given store and is picked up by currentStock($storeId)', function () {
    $domain = 'opening-stock-import-store-scope.tenant-test';
    $tenant = provisionOpeningStockImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Item::factory()->create(['name' => 'Bottled Water 1L', 'is_stockable' => true]);
        Store::create(['name' => 'Branch Store', 'is_active' => true]);
    });

    loginAsOpeningStockImportOwner($domain);

    $branchStoreId = $tenant->run(fn () => Store::where('name', 'Branch Store')->value('id'));
    $mainStoreId = $tenant->run(fn () => Store::where('name', 'Main Store')->value('id'));

    $csv = "item,quantity,unit_cost_rate,remarks\nBottled Water 1L,40,10.00,\n";
    $file = UploadedFile::fake()->createWithContent('opening-stock.csv', $csv);

    $response = $this->post("http://{$domain}/stock-adjustments/opening-stock/import", [
        'file' => $file,
        'date' => '2026-01-01',
        'store_id' => $branchStoreId,
    ]);

    $response->assertRedirect("http://{$domain}/stock-adjustments");

    $tenant->run(function () use ($branchStoreId, $mainStoreId) {
        $water = Item::query()->where('name', 'Bottled Water 1L')->firstOrFail();

        expect($water->currentStock($branchStoreId))->toBe(40.0)
            ->and($water->currentStock($mainStoreId))->toBe(0.0)
            ->and($water->currentStock())->toBe(40.0);
    });

    $tenant->delete();
});

test('bulk import skips invalid or unresolvable rows and reports why without importing them', function () {
    $domain = 'opening-stock-import-invalid.tenant-test';
    $tenant = provisionOpeningStockImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Item::factory()->create(['name' => 'Good Item', 'is_stockable' => true]);
        Item::factory()->create(['name' => 'Non Stockable Item', 'is_stockable' => false]);
    });

    loginAsOpeningStockImportOwner($domain);

    $csv = "item,quantity,unit_cost_rate,remarks\n"
        // valid row.
        ."Good Item,25,5.00,\n"
        // unknown item.
        ."No Such Item,10,,\n"
        // not stockable.
        ."Non Stockable Item,10,,\n"
        // zero quantity.
        ."Good Item,0,,\n"
        // repeated item.
        ."Good Item,15,,\n";
    $file = UploadedFile::fake()->createWithContent('opening-stock.csv', $csv);

    $response = $this->post("http://{$domain}/stock-adjustments/opening-stock/import", [
        'file' => $file,
        'date' => '2026-01-01',
    ]);

    $response->assertRedirect("http://{$domain}/stock-adjustments");
    $response->assertSessionHas('importResult', function (array $result) {
        return $result['imported'] === 1 && count($result['skipped']) === 3;
    });

    $tenant->run(function () {
        $item = Item::query()->where('name', 'Good Item')->firstOrFail();
        expect($item->currentStock())->toBe(25.0);

        $nonStockable = Item::query()->where('name', 'Non Stockable Item')->firstOrFail();
        expect($nonStockable->currentStock())->toBe(0.0);
    });

    $tenant->delete();
});

test('guests cannot reach the opening stock import endpoints', function () {
    $domain = 'opening-stock-import-guest.tenant-test';
    $tenant = provisionOpeningStockImportTestTenant($domain);

    $tenant->run(function () {
        Item::factory()->create(['name' => 'Good Item', 'is_stockable' => true]);
    });

    $file = UploadedFile::fake()->createWithContent('opening-stock.csv', "item,quantity,unit_cost_rate,remarks\nGood Item,10,,\n");

    $this->post("http://{$domain}/stock-adjustments/opening-stock/import", ['file' => $file, 'date' => '2026-01-01'])
        ->assertRedirect("http://{$domain}/login");

    $this->get("http://{$domain}/stock-adjustments/opening-stock/template")
        ->assertRedirect("http://{$domain}/login");

    $tenant->run(function () {
        expect(StockAdjustment::query()->count())->toBe(0);
    });

    $tenant->delete();
});
