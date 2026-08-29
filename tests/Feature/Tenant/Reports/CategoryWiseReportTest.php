<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemSubcategory;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionCategoryWiseReportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginCategoryWiseReportTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

function categoryWiseReportTestAdmin(): User
{
    return User::factory()->create(['email' => 'owner@example.com']);
}

function categoryWiseReportTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('sales by category aggregates per category, rolls subcategory totals up into the parent, and excludes cancelled sales and out-of-range dates', function () {
    $domain = 'sales-by-category.tenant-test';
    $tenant = provisionCategoryWiseReportTestTenant($domain);

    $tenant->run(function () {
        categoryWiseReportTestOpenFiscalYear();
        $admin = categoryWiseReportTestAdmin();
        $customer = Customer::factory()->create();

        $beverages = ItemCategory::factory()->create(['name' => 'Beverages']);
        $snacks = ItemCategory::factory()->create(['name' => 'Snacks']);
        $softDrinks = ItemSubcategory::factory()->create(['item_category_id' => $beverages->id, 'name' => 'Soft Drinks']);

        $cola = Item::factory()->create(['item_category_id' => $beverages->id, 'item_subcategory_id' => null, 'is_vatable' => false, 'is_stockable' => false]);
        $soda = Item::factory()->create(['item_category_id' => $beverages->id, 'item_subcategory_id' => $softDrinks->id, 'is_vatable' => false, 'is_stockable' => false]);
        $chips = Item::factory()->create(['item_category_id' => $snacks->id, 'item_subcategory_id' => null, 'is_vatable' => false, 'is_stockable' => false]);

        // Beverages: cola (uncategorized) 2*50=100, soda (Soft Drinks) 4*25=100.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $cola->id, 'quantity' => 2, 'rate' => 50, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $soda->id, 'quantity' => 4, 'rate' => 25, 'discount' => 0]],
            $admin,
        );

        // Snacks: chips 3*20=60.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-03', 'payment_mode' => 'cash'],
            [['item_id' => $chips->id, 'quantity' => 3, 'rate' => 20, 'discount' => 0]],
            $admin,
        );

        // Cancelled - must not contribute anywhere.
        $cancelled = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-05', 'payment_mode' => 'cash'],
            [['item_id' => $chips->id, 'quantity' => 10, 'rate' => 1000, 'discount' => 0]],
            $admin,
        );
        $cancelled->cancel($admin, 'Recorded in error');

        // Out of the [2026-06-01, 2026-06-30] filter range - must be excluded.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-07-15', 'payment_mode' => 'cash'],
            [['item_id' => $soda->id, 'quantity' => 1, 'rate' => 999, 'discount' => 0]],
            $admin,
        );
    });

    loginCategoryWiseReportTestUser($domain);

    $this->get("http://{$domain}/reports/sales-by-category?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/SalesByCategory')
            ->has('rows', 2)
            ->where('rows.0.categoryName', 'Beverages')
            ->where('rows.0.quantity', 6)
            ->where('rows.0.value', 200)
            ->has('rows.0.subcategories', 2)
            ->where('rows.0.subcategories.0.subcategoryName', 'Soft Drinks')
            ->where('rows.0.subcategories.0.quantity', 4)
            ->where('rows.0.subcategories.0.value', 100)
            ->where('rows.0.subcategories.1.subcategoryName', 'Uncategorized')
            ->where('rows.0.subcategories.1.quantity', 2)
            ->where('rows.0.subcategories.1.value', 100)
            ->where('rows.1.categoryName', 'Snacks')
            ->where('rows.1.quantity', 3)
            ->where('rows.1.value', 60)
            ->has('rows.1.subcategories', 1)
            ->where('rows.1.subcategories.0.subcategoryName', 'Uncategorized')
            ->where('rows.1.subcategories.0.quantity', 3)
            ->where('rows.1.subcategories.0.value', 60)
            ->where('grandTotal.quantity', 9)
            ->where('grandTotal.value', 260)
        );

    $tenant->delete();
});

test('purchase by category mirrors the sales-by-category aggregation and rollup, excluding cancelled purchases and out-of-range dates', function () {
    $domain = 'purchase-by-category.tenant-test';
    $tenant = provisionCategoryWiseReportTestTenant($domain);

    $tenant->run(function () {
        categoryWiseReportTestOpenFiscalYear();
        $admin = categoryWiseReportTestAdmin();
        $supplier = Supplier::factory()->create();

        $hardware = ItemCategory::factory()->create(['name' => 'Hardware']);
        $software = ItemCategory::factory()->create(['name' => 'Software']);
        $cables = ItemSubcategory::factory()->create(['item_category_id' => $hardware->id, 'name' => 'Cables']);

        $cable = Item::factory()->create(['item_category_id' => $hardware->id, 'item_subcategory_id' => $cables->id, 'is_vatable' => false, 'is_stockable' => false]);
        $board = Item::factory()->create(['item_category_id' => $hardware->id, 'item_subcategory_id' => null, 'is_vatable' => false, 'is_stockable' => false]);
        $license = Item::factory()->create(['item_category_id' => $software->id, 'item_subcategory_id' => null, 'is_vatable' => false, 'is_stockable' => false]);

        // Hardware: cable (Cables) 4*25=100, board (uncategorized) 2*60=120.
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $cable->id, 'quantity' => 4, 'rate' => 25, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $board->id, 'quantity' => 2, 'rate' => 60, 'discount' => 0]],
            $admin,
        );

        // Software: license 1*500=500.
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-03', 'payment_mode' => 'cash'],
            [['item_id' => $license->id, 'quantity' => 1, 'rate' => 500, 'discount' => 0]],
            $admin,
        );

        // Cancelled - must not contribute anywhere.
        $cancelled = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-05', 'payment_mode' => 'cash'],
            [['item_id' => $board->id, 'quantity' => 10, 'rate' => 1000, 'discount' => 0]],
            $admin,
        );
        $cancelled->cancel($admin, 'Recorded in error');

        // Out of the [2026-06-01, 2026-06-30] filter range - must be excluded.
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-07-15', 'payment_mode' => 'cash'],
            [['item_id' => $cable->id, 'quantity' => 1, 'rate' => 999, 'discount' => 0]],
            $admin,
        );
    });

    loginCategoryWiseReportTestUser($domain);

    $this->get("http://{$domain}/reports/purchase-by-category?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/PurchaseByCategory')
            ->has('rows', 2)
            ->where('rows.0.categoryName', 'Hardware')
            ->where('rows.0.quantity', 6)
            ->where('rows.0.value', 220)
            ->has('rows.0.subcategories', 2)
            ->where('rows.0.subcategories.0.subcategoryName', 'Cables')
            ->where('rows.0.subcategories.0.quantity', 4)
            ->where('rows.0.subcategories.0.value', 100)
            ->where('rows.0.subcategories.1.subcategoryName', 'Uncategorized')
            ->where('rows.0.subcategories.1.quantity', 2)
            ->where('rows.0.subcategories.1.value', 120)
            ->where('rows.1.categoryName', 'Software')
            ->where('rows.1.quantity', 1)
            ->where('rows.1.value', 500)
            ->where('grandTotal.quantity', 7)
            ->where('grandTotal.value', 720)
        );

    $tenant->delete();
});

test('stock by category sums weighted-average valuation per category as of a cutoff date, excluding post-cutoff and cancelled movements', function () {
    $domain = 'stock-by-category.tenant-test';
    $tenant = provisionCategoryWiseReportTestTenant($domain);

    $tenant->run(function () {
        categoryWiseReportTestAdmin();

        $electronics = ItemCategory::factory()->create(['name' => 'Electronics']);
        $furniture = ItemCategory::factory()->create(['name' => 'Furniture']);
        $smallElectronics = ItemSubcategory::factory()->create(['item_category_id' => $electronics->id, 'name' => 'Small Electronics']);

        $widget = Item::factory()->create(['item_category_id' => $electronics->id, 'item_subcategory_id' => null, 'name' => 'Widget', 'is_stockable' => true]);
        $gadget = Item::factory()->create(['item_category_id' => $electronics->id, 'item_subcategory_id' => $smallElectronics->id, 'name' => 'Gadget', 'is_stockable' => true]);
        $chair = Item::factory()->create(['item_category_id' => $furniture->id, 'item_subcategory_id' => null, 'name' => 'Chair', 'is_stockable' => true]);

        // Widget: closing = 10 + 5 - 3 = 12; avgCost = (10*100 + 5*130) / 15 = 110; valuation = 1320.
        $widget->recordStockMovement(StockMovementType::Purchase, 10, '2026-01-01', null, 100);
        $widget->recordStockMovement(StockMovementType::Purchase, 5, '2026-02-10', null, 130);
        $widget->recordStockMovement(StockMovementType::Sale, 3, '2026-02-15');

        // After the as_of cutoff (2026-02-28) - must not affect closing or valuation.
        $widget->recordStockMovement(StockMovementType::Purchase, 20, '2026-03-01', null, 200);

        // Cancelled - must be excluded from every sum.
        $widget->stockMovements()->create([
            'movement_type' => StockMovementType::AdjustmentIn,
            'quantity' => 100,
            'date' => '2026-02-20',
            'cancelled' => true,
        ]);

        // Gadget (Small Electronics): closing = 4; avgCost = 50; valuation = 200.
        $gadget->recordStockMovement(StockMovementType::Purchase, 4, '2026-01-15', null, 50);

        // Chair (Furniture, uncategorized): closing = 2; avgCost = 300; valuation = 600.
        $chair->recordStockMovement(StockMovementType::Purchase, 2, '2026-01-01', null, 300);
    });

    loginCategoryWiseReportTestUser($domain);

    $this->get("http://{$domain}/reports/stock-by-category?as_of=2026-02-28")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/StockByCategory')
            ->has('rows', 2)
            ->where('rows.0.categoryName', 'Electronics')
            ->where('rows.0.quantity', 16)
            ->where('rows.0.valuation', 1520)
            ->has('rows.0.subcategories', 2)
            ->where('rows.0.subcategories.0.subcategoryName', 'Small Electronics')
            ->where('rows.0.subcategories.0.quantity', 4)
            ->where('rows.0.subcategories.0.valuation', 200)
            ->where('rows.0.subcategories.0.avgCost', 50)
            ->where('rows.0.subcategories.1.subcategoryName', 'Uncategorized')
            ->where('rows.0.subcategories.1.quantity', 12)
            ->where('rows.0.subcategories.1.valuation', 1320)
            ->where('rows.0.subcategories.1.avgCost', 110)
            ->where('rows.1.categoryName', 'Furniture')
            ->where('rows.1.quantity', 2)
            ->where('rows.1.valuation', 600)
            ->has('rows.1.subcategories', 1)
            ->where('rows.1.subcategories.0.subcategoryName', 'Uncategorized')
            ->where('rows.1.subcategories.0.quantity', 2)
            ->where('rows.1.subcategories.0.valuation', 600)
            ->where('grandTotalValuation', 2120)
        );

    $tenant->delete();
});

test('grand totals sum correctly across all three category-wise reports', function () {
    $domain = 'category-wise-grand-totals.tenant-test';
    $tenant = provisionCategoryWiseReportTestTenant($domain);

    $tenant->run(function () {
        categoryWiseReportTestOpenFiscalYear();
        $admin = categoryWiseReportTestAdmin();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();

        $alpha = ItemCategory::factory()->create(['name' => 'Alpha']);
        $beta = ItemCategory::factory()->create(['name' => 'Beta']);
        $alphaSub = ItemSubcategory::factory()->create(['item_category_id' => $alpha->id, 'name' => 'Alpha-Sub']);

        $alphaNoSub = Item::factory()->create(['item_category_id' => $alpha->id, 'item_subcategory_id' => null, 'is_vatable' => false, 'is_stockable' => true]);
        $alphaWithSub = Item::factory()->create(['item_category_id' => $alpha->id, 'item_subcategory_id' => $alphaSub->id, 'is_vatable' => false, 'is_stockable' => true]);
        $betaItem = Item::factory()->create(['item_category_id' => $beta->id, 'item_subcategory_id' => null, 'is_vatable' => false, 'is_stockable' => true]);

        // Sales: Alpha (uncategorized) 1*100=100, Alpha (Alpha-Sub) 2*50=100, Beta 3*10=30.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-10', 'payment_mode' => 'cash'],
            [['item_id' => $alphaNoSub->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-10', 'payment_mode' => 'cash'],
            [['item_id' => $alphaWithSub->id, 'quantity' => 2, 'rate' => 50, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-10', 'payment_mode' => 'cash'],
            [['item_id' => $betaItem->id, 'quantity' => 3, 'rate' => 10, 'discount' => 0]],
            $admin,
        );

        // Purchases: Alpha (uncategorized) 5*20=100, Alpha (Alpha-Sub) 5*80=400, Beta 6*15=90.
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-10', 'payment_mode' => 'cash'],
            [['item_id' => $alphaNoSub->id, 'quantity' => 5, 'rate' => 20, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-10', 'payment_mode' => 'cash'],
            [['item_id' => $alphaWithSub->id, 'quantity' => 5, 'rate' => 80, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-10', 'payment_mode' => 'cash'],
            [['item_id' => $betaItem->id, 'quantity' => 6, 'rate' => 15, 'discount' => 0]],
            $admin,
        );
    });

    loginCategoryWiseReportTestUser($domain);

    // Sales: Alpha qty 1+2=3, value 100+100=200; Beta qty 3, value 30. Grand: qty 6, value 230.
    $this->get("http://{$domain}/reports/sales-by-category?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.categoryName', 'Alpha')
            ->where('rows.0.quantity', 3)
            ->where('rows.0.value', 200)
            ->where('rows.1.categoryName', 'Beta')
            ->where('rows.1.quantity', 3)
            ->where('rows.1.value', 30)
            ->where('grandTotal.quantity', 6)
            ->where('grandTotal.value', 230)
        );

    // Purchases: Alpha qty 5+5=10, value 100+400=500; Beta qty 6, value 90. Grand: qty 16, value 590.
    $this->get("http://{$domain}/reports/purchase-by-category?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.categoryName', 'Alpha')
            ->where('rows.0.quantity', 10)
            ->where('rows.0.value', 500)
            ->where('rows.1.categoryName', 'Beta')
            ->where('rows.1.quantity', 6)
            ->where('rows.1.value', 90)
            ->where('grandTotal.quantity', 16)
            ->where('grandTotal.value', 590)
        );

    // Stock as of 2026-06-30: alphaNoSub closing 5-1=4 @ avgCost 20 = 80;
    // alphaWithSub closing 5-2=3 @ avgCost 80 = 240; Alpha total 320.
    // betaItem closing 6-3=3 @ avgCost 15 = 45. Grand valuation: 365.
    $this->get("http://{$domain}/reports/stock-by-category?as_of=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.categoryName', 'Alpha')
            ->where('rows.0.quantity', 7)
            ->where('rows.0.valuation', 320)
            ->where('rows.1.categoryName', 'Beta')
            ->where('rows.1.quantity', 3)
            ->where('rows.1.valuation', 45)
            ->where('grandTotalValuation', 365)
        );

    $tenant->delete();
});
