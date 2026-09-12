<?php

use App\Enums\StockMovementType;
use App\Models\Brand;
use App\Models\Item;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Brand-wise stock report
|--------------------------------------------------------------------------
|
| Mirrors CategoryWiseReportTest's "stock by category" coverage exactly,
| grouped by Brand instead of ItemCategory - see BrandWiseReportController's
| docblock for why it's structurally identical minus the subcategory
| nesting level.
|
| Payload rules, same as the category report: `value` is a 2dp Money string,
| quantities are a per-base-unit list of `{unit, quantity}` 4dp strings
| rather than one scalar added across units, and `grandTotalValuation` is
| the same string as `grandTotal.value`. Value comes from
| App\Support\Inventory\StockCosting, which averages the recorded movement
| `value`, so every priced movement below records one.
|
*/

afterEach(function () {
    tenancy()->end();
});

function provisionBrandWiseReportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginBrandWiseReportTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

function brandWiseReportTestAdmin(): User
{
    return User::factory()->create(['email' => 'owner@example.com']);
}

test('stock by brand sums weighted-average valuation per brand as of a cutoff date, and groups two items of the same brand together', function () {
    $domain = 'stock-by-brand.tenant-test';
    $tenant = provisionBrandWiseReportTestTenant($domain);

    $tenant->run(function () {
        brandWiseReportTestAdmin();

        $unilever = Brand::factory()->create(['name' => 'Unilever']);
        $nestle = Brand::factory()->create(['name' => 'Nestle']);

        // purchase_rate is StockCosting's fallback for an item with no priced
        // history. Every item here has one, so pinning it to a figure no
        // expectation below uses makes an accidental fallback fail loudly
        // instead of passing on the factory's random rate.
        //
        // Two items, same brand (Unilever) - must be summed into one row.
        $soap = Item::factory()->create(['brand_id' => $unilever->id, 'name' => 'Soap', 'unit' => 'Piece', 'purchase_rate' => 7, 'is_stockable' => true]);
        $shampoo = Item::factory()->create(['brand_id' => $unilever->id, 'name' => 'Shampoo', 'unit' => 'Piece', 'purchase_rate' => 7, 'is_stockable' => true]);
        // A different brand, in a different base unit - must appear as its
        // own separate row and never merge quantities with the Pieces above.
        $coffee = Item::factory()->create(['brand_id' => $nestle->id, 'name' => 'Coffee', 'unit' => 'Packet', 'purchase_rate' => 7, 'is_stockable' => true]);
        // No brand at all - must roll into the trailing "Unbranded" row.
        $unbranded = Item::factory()->create(['brand_id' => null, 'name' => 'Mystery Item', 'unit' => 'Piece', 'purchase_rate' => 7, 'is_stockable' => true]);

        $storeId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;

        // Unilever: soap closing 10 at 1000/10 = 100 -> 1000; shampoo closing
        // 5 at 1000/5 = 200 -> 1000. Brand total: 15 Pieces worth 2000.
        $soap->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-01', $storeId, value: 1000);
        $shampoo->recordStockMovement(StockMovementType::Purchase, 5, '2026-01-01', $storeId, value: 1000);

        // Nestle: coffee closing 4 at 200/4 = 50 -> 200.
        $coffee->recordStockMovement(StockMovementType::Purchase, 4, '2026-01-15', $storeId, value: 200);

        // Unbranded: closing 2 at 600/2 = 300 -> 600.
        $unbranded->recordStockMovement(StockMovementType::Purchase, 2, '2026-01-01', $storeId, value: 600);

        // After the as_of cutoff - must not affect closing quantity or the
        // average it would otherwise drag up to 849.17.
        $soap->recordStockMovement(StockMovementType::Purchase, 50, '2026-03-01', $storeId, value: 49950);
    });

    loginBrandWiseReportTestUser($domain);

    $this->get("http://{$domain}/reports/stock-by-brand?as_of=2026-02-28")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/StockByBrand')
            ->where('asOf', '2026-02-28')
            // Brands are always listed alphabetically by name, with the
            // "Unbranded" leftover bucket trailing after every real brand.
            ->has('rows', 3)
            ->where('rows.0.brandName', 'Nestle')
            // Was asserted as quantity 4 at avgCost 50; avgCost is gone from
            // the payload, so the 4 x 50 it stood for is pinned as the value.
            ->where('rows.0.value', '200.00')
            ->has('rows.0.quantities', 1)
            ->where('rows.0.quantities.0.unit', 'Packet')
            ->where('rows.0.quantities.0.quantity', '4.0000')
            ->where('rows.1.brandName', 'Unilever')
            ->where('rows.1.value', '2000.00')
            ->has('rows.1.quantities', 1)
            ->where('rows.1.quantities.0.unit', 'Piece')
            ->where('rows.1.quantities.0.quantity', '15.0000')
            ->where('rows.2.brandName', 'Unbranded')
            ->where('rows.2.brandId', null)
            ->where('rows.2.value', '600.00')
            ->has('rows.2.quantities', 1)
            ->where('rows.2.quantities.0.unit', 'Piece')
            ->where('rows.2.quantities.0.quantity', '2.0000')
            ->where('grandTotal.value', '2800.00')
            // Packets and Pieces stay two buckets in the grand total too.
            ->has('grandTotal.quantities', 2)
            ->where('grandTotal.quantities.0.unit', 'Packet')
            ->where('grandTotal.quantities.0.quantity', '4.0000')
            ->where('grandTotal.quantities.1.unit', 'Piece')
            ->where('grandTotal.quantities.1.quantity', '17.0000')
            // grandTotalValuation is now the same string as grandTotal.value.
            ->where('grandTotalValuation', '2800.00')
        );

    $tenant->delete();
});

test('a brand with zero stockable items still shows as a zero row rather than vanishing', function () {
    $domain = 'stock-by-brand-zero-row.tenant-test';
    $tenant = provisionBrandWiseReportTestTenant($domain);

    $tenant->run(function () {
        brandWiseReportTestAdmin();
        Brand::factory()->create(['name' => 'Empty Brand']);
    });

    loginBrandWiseReportTestUser($domain);

    $this->get("http://{$domain}/reports/stock-by-brand?as_of=2026-02-28")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.brandName', 'Empty Brand')
            ->where('rows.0.value', '0.00')
            // No stock in any unit means no unit buckets at all, rather than
            // a zero bucket against an arbitrary unit.
            ->has('rows.0.quantities', 0)
            ->where('grandTotal.value', '0.00')
            ->has('grandTotal.quantities', 0)
            ->where('grandTotalValuation', '0.00')
        );

    $tenant->delete();
});

test('stock by brand is scoped to a single store when store_id is given, and sums across all stores when omitted', function () {
    $domain = 'stock-by-brand-store-scope.tenant-test';
    $tenant = provisionBrandWiseReportTestTenant($domain);

    $branchStoreId = null;
    $tenant->run(function () use (&$branchStoreId) {
        brandWiseReportTestAdmin();

        $brand = Brand::factory()->create(['name' => 'Acme Brand']);
        $widget = Item::factory()->create(['brand_id' => $brand->id, 'name' => 'Widget', 'unit' => 'Piece', 'purchase_rate' => 7, 'is_stockable' => true]);

        $mainStoreId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;
        $branchStoreId = Store::factory()->create(['name' => 'Branch Store'])->id;

        // Main store: 10 bought in for 1000.
        $widget->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-01', $mainStoreId, value: 1000);

        // Branch store: 10 bought in for 3000.
        $widget->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-01', $branchStoreId, value: 3000);
    });

    loginBrandWiseReportTestUser($domain);

    // Filtered to the branch store only: qty 10, value 2000.
    //
    // The quantity is store-scoped but the cost never is: StockCosting values
    // one item identically in every store, at the all-stores weighted average
    // of (1000 + 3000) / 20 = 200. So the branch's 10 units are worth 2000,
    // not the 3000 they were bought for, and the two stores' values add back
    // up to the 4000 total below. The old per-store average reported 3000
    // here and so made the per-store figures disagree with the whole.
    $this->get("http://{$domain}/reports/stock-by-brand?as_of=2026-01-31&store_id={$branchStoreId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.value', '2000.00')
            ->where('rows.0.quantities.0.unit', 'Piece')
            ->where('rows.0.quantities.0.quantity', '10.0000')
            ->where('grandTotal.value', '2000.00')
            ->where('grandTotalValuation', '2000.00')
            ->where('storeId', $branchStoreId)
        );

    // Unfiltered: cross-store qty 20, value 4000 (average = 4000/20 = 200 exactly).
    $this->get("http://{$domain}/reports/stock-by-brand?as_of=2026-01-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.value', '4000.00')
            ->where('rows.0.quantities.0.unit', 'Piece')
            ->where('rows.0.quantities.0.quantity', '20.0000')
            ->where('grandTotal.value', '4000.00')
            ->where('grandTotalValuation', '4000.00')
            ->where('storeId', null)
        );

    $tenant->delete();
});
