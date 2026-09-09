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

        // Two items, same brand (Unilever) - must be summed into one row.
        $soap = Item::factory()->create(['brand_id' => $unilever->id, 'name' => 'Soap', 'is_stockable' => true]);
        $shampoo = Item::factory()->create(['brand_id' => $unilever->id, 'name' => 'Shampoo', 'is_stockable' => true]);
        // A different brand - must appear as its own separate row.
        $coffee = Item::factory()->create(['brand_id' => $nestle->id, 'name' => 'Coffee', 'is_stockable' => true]);
        // No brand at all - must roll into the trailing "Unbranded" row.
        $unbranded = Item::factory()->create(['brand_id' => null, 'name' => 'Mystery Item', 'is_stockable' => true]);

        $storeId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;

        // Unilever: soap closing 10 @ 100 = 1000; shampoo closing 5 @ 200 = 1000. Brand total: qty 15, valuation 2000.
        $soap->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-01', $storeId, unitCostRate: 100);
        $shampoo->recordStockMovement(StockMovementType::Purchase, 5, '2026-01-01', $storeId, unitCostRate: 200);

        // Nestle: coffee closing 4 @ 50 = 200.
        $coffee->recordStockMovement(StockMovementType::Purchase, 4, '2026-01-15', $storeId, unitCostRate: 50);

        // Unbranded: closing 2 @ 300 = 600.
        $unbranded->recordStockMovement(StockMovementType::Purchase, 2, '2026-01-01', $storeId, unitCostRate: 300);

        // After the as_of cutoff - must not affect closing or valuation.
        $soap->recordStockMovement(StockMovementType::Purchase, 50, '2026-03-01', $storeId, unitCostRate: 999);
    });

    loginBrandWiseReportTestUser($domain);

    $this->get("http://{$domain}/reports/stock-by-brand?as_of=2026-02-28")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/StockByBrand')
            // Brands are always listed alphabetically by name, with the
            // "Unbranded" leftover bucket trailing after every real brand.
            ->has('rows', 3)
            ->where('rows.0.brandName', 'Nestle')
            ->where('rows.0.quantity', 4)
            ->where('rows.0.valuation', 200)
            ->where('rows.0.avgCost', 50)
            ->where('rows.1.brandName', 'Unilever')
            ->where('rows.1.quantity', 15)
            ->where('rows.1.valuation', 2000)
            ->where('rows.2.brandName', 'Unbranded')
            ->where('rows.2.brandId', null)
            ->where('rows.2.quantity', 2)
            ->where('rows.2.valuation', 600)
            ->where('grandTotalValuation', 2800)
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
            ->where('rows.0.quantity', 0)
            ->where('rows.0.valuation', 0)
            ->where('grandTotalValuation', 0)
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
        $widget = Item::factory()->create(['brand_id' => $brand->id, 'name' => 'Widget', 'is_stockable' => true]);

        $mainStoreId = Store::where('is_active', true)->orderBy('id')->firstOrFail()->id;
        $branchStoreId = Store::factory()->create(['name' => 'Branch Store'])->id;

        // Main store: 10 @ 100 = 1000.
        $widget->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-01', $mainStoreId, unitCostRate: 100);

        // Branch store: 10 @ 300 = 3000.
        $widget->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-01', $branchStoreId, unitCostRate: 300);
    });

    loginBrandWiseReportTestUser($domain);

    // Filtered to the branch store only: qty 10, valuation 3000.
    $this->get("http://{$domain}/reports/stock-by-brand?as_of=2026-01-31&store_id={$branchStoreId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.quantity', 10)
            ->where('rows.0.valuation', 3000)
            ->where('grandTotalValuation', 3000)
            ->where('storeId', $branchStoreId)
        );

    // Unfiltered: cross-store qty 20, valuation 4000 (avgCost = 4000/20 = 200 exactly).
    $this->get("http://{$domain}/reports/stock-by-brand?as_of=2026-01-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.quantity', 20)
            ->where('rows.0.valuation', 4000)
            ->where('grandTotalValuation', 4000)
            ->where('storeId', null)
        );

    $tenant->delete();
});
