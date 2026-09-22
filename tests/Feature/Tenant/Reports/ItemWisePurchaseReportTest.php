<?php

use App\Enums\FiscalYearStatus;
use App\Models\Brand;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionItemWisePurchaseReportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginItemWisePurchaseReportTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

function itemWisePurchaseReportTestAdmin(): User
{
    return User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
}

function itemWisePurchaseReportTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('the item-wise purchase report aggregates quantity, value, and transaction count across multiple purchases of the same item, sorted by value descending', function () {
    $domain = 'item-wise-purchase.tenant-test';
    $tenant = provisionItemWisePurchaseReportTestTenant($domain);

    $tenant->run(function () {
        itemWisePurchaseReportTestOpenFiscalYear();
        $admin = itemWisePurchaseReportTestAdmin();
        $supplier = Supplier::factory()->create();
        $widget = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);
        $gadget = Item::factory()->create(['name' => 'Gadget', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);

        // Widget: two purchases, 2*100 + 3*50 = 200 + 150 = 350 across 5 units.
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $widget->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-15', 'payment_mode' => 'cash'],
            [['item_id' => $widget->id, 'quantity' => 3, 'rate' => 50, 'discount' => 0]],
            $admin,
        );

        // Gadget: one purchase, 1*500 = 500 - worth more than Widget's 350
        // even though it moved fewer units, so it must sort first.
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-10', 'payment_mode' => 'cash'],
            [['item_id' => $gadget->id, 'quantity' => 1, 'rate' => 500, 'discount' => 0]],
            $admin,
        );
    });

    loginItemWisePurchaseReportTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-purchase?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/ItemWisePurchase')
            ->has('items', 2)
            ->where('items.0.name', 'Gadget')
            // Exact decimal strings now: base quantities at 4 places, money
            // at 2. Every line here is entered in the item's base unit, so
            // the base quantity equals the entered quantity.
            ->where('items.0.total_quantity', '1.0000')
            ->where('items.0.total_value', '500.00')
            ->where('items.0.transaction_count', 1)
            ->where('items.1.name', 'Widget')
            ->where('items.1.total_quantity', '5.0000')
            ->where('items.1.total_value', '350.00')
            ->where('items.1.transaction_count', 2)
            // The unit-blind totals.total_quantity scalar is gone; 1 + 5 = 6
            // is now reported under the shared "pcs" unit.
            ->has('totals.quantities', 1)
            ->where('totals.quantities.0.unit', 'pcs')
            ->where('totals.quantities.0.quantity', '6.0000')
            ->where('totals.total_value', '850.00')
        );

    $tenant->delete();
});

test('the item-wise purchase report excludes a cancelled purchase from the item totals', function () {
    $domain = 'item-wise-purchase-cancelled.tenant-test';
    $tenant = provisionItemWisePurchaseReportTestTenant($domain);

    $tenant->run(function () {
        itemWisePurchaseReportTestOpenFiscalYear();
        $admin = itemWisePurchaseReportTestAdmin();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $cancelled = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 10, 'discount' => 0]],
            $admin,
        );
        $cancelled->cancel($admin, 'Recorded in error');
    });

    loginItemWisePurchaseReportTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-purchase?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items', 1)
            ->where('items.0.total_quantity', '1.0000')
            ->where('items.0.total_value', '100.00')
            ->where('items.0.transaction_count', 1)
            ->has('totals.quantities', 1)
            ->where('totals.quantities.0.unit', 'pcs')
            ->where('totals.quantities.0.quantity', '1.0000')
            ->where('totals.total_value', '100.00')
        );

    $tenant->delete();
});

test('the item-wise purchase report excludes a purchase outside the requested date range', function () {
    $domain = 'item-wise-purchase-daterange.tenant-test';
    $tenant = provisionItemWisePurchaseReportTestTenant($domain);

    $tenant->run(function () {
        itemWisePurchaseReportTestOpenFiscalYear();
        $admin = itemWisePurchaseReportTestAdmin();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // Outside the [2026-06-01, 2026-06-30] filter range used below.
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-07-15', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 999, 'discount' => 0]],
            $admin,
        );
    });

    loginItemWisePurchaseReportTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-purchase?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items', 1)
            ->where('items.0.total_value', '100.00')
            ->where('totals.total_value', '100.00')
        );

    $tenant->delete();
});

test('the item-wise purchase report can be narrowed to a single store', function () {
    $domain = 'item-wise-purchase-store-filter.tenant-test';
    $tenant = provisionItemWisePurchaseReportTestTenant($domain);

    $storeAId = null;
    $tenant->run(function () use (&$storeAId) {
        itemWisePurchaseReportTestOpenFiscalYear();
        $admin = itemWisePurchaseReportTestAdmin();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);
        $storeA = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $storeB = Store::factory()->create(['name' => 'Branch Store']);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'store_id' => $storeA->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'store_id' => $storeB->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 10, 'discount' => 0]],
            $admin,
        );

        $storeAId = $storeA->id;
    });

    loginItemWisePurchaseReportTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-purchase?from=2026-06-01&to=2026-06-30&store_id={$storeAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items', 1)
            ->where('items.0.total_quantity', '1.0000')
            ->where('items.0.total_value', '100.00')
            ->where('totals.total_value', '100.00')
        );

    $tenant->delete();
});

test('picking an item_id keeps the all-items aggregate and adds that item\'s raw per-line transaction ledger (audit T15-6)', function () {
    $domain = 'item-wise-purchase-drilldown.tenant-test';
    $tenant = provisionItemWisePurchaseReportTestTenant($domain);

    $widgetId = null;
    $billNumber = null;
    $supplierName = null;
    $tenant->run(function () use (&$widgetId, &$billNumber, &$supplierName) {
        itemWisePurchaseReportTestOpenFiscalYear();
        $admin = itemWisePurchaseReportTestAdmin();
        $supplier = Supplier::factory()->create(['name' => 'Himal Traders']);
        $widget = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);
        $gadget = Item::factory()->create(['name' => 'Gadget', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);

        // Two Widget purchases at different rates, plus an unrelated Gadget
        // purchase that must show in the aggregate but never in Widget's lines.
        $purchase1 = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $widget->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-10', 'payment_mode' => 'cash'],
            [['item_id' => $widget->id, 'quantity' => 3, 'rate' => 80, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-05', 'payment_mode' => 'cash'],
            [['item_id' => $gadget->id, 'quantity' => 1, 'rate' => 500, 'discount' => 0]],
            $admin,
        );

        $widgetId = $widget->id;
        $billNumber = $purchase1->bill_number;
        $supplierName = $supplier->name;
    });

    loginItemWisePurchaseReportTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-purchase?from=2026-06-01&to=2026-06-30&item_id={$widgetId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // The aggregate is untouched by the item_id filter: both items
            // still appear, exactly as without any item_id at all.
            ->has('items', 2)
            ->where('itemId', $widgetId)
            // Alongside it, the raw per-line ledger for just the picked
            // item: two Widget lines, in date order, never the Gadget line.
            ->has('lines', 2)
            ->where('lines.0.document_number', $billNumber)
            ->where('lines.0.party_name', $supplierName)
            ->where('lines.0.rate', '100.0000')
            ->where('lines.0.quantity', '2.0000')
            ->where('lines.0.line_total', '200.00')
            ->where('lines.0.vatable', false)
            ->where('lines.1.rate', '80.0000')
            ->where('lines.1.quantity', '3.0000')
            ->where('lines.1.line_total', '240.00')
        );

    $tenant->delete();
});

test('the item-wise purchase report can be narrowed to a single category (audit T15-11)', function () {
    $domain = 'item-wise-purchase-category-filter.tenant-test';
    $tenant = provisionItemWisePurchaseReportTestTenant($domain);

    $categoryAId = null;
    $tenant->run(function () use (&$categoryAId) {
        itemWisePurchaseReportTestOpenFiscalYear();
        $admin = itemWisePurchaseReportTestAdmin();
        $supplier = Supplier::factory()->create();
        $categoryA = ItemCategory::factory()->create(['name' => 'Category A']);
        $categoryB = ItemCategory::factory()->create(['name' => 'Category B']);
        $itemA = Item::factory()->create(['name' => 'Item A', 'unit' => 'pcs', 'item_category_id' => $categoryA->id, 'is_vatable' => false, 'is_stockable' => false]);
        $itemB = Item::factory()->create(['name' => 'Item B', 'unit' => 'pcs', 'item_category_id' => $categoryB->id, 'is_vatable' => false, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemA->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemB->id, 'quantity' => 5, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $categoryAId = $categoryA->id;
    });

    loginItemWisePurchaseReportTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-purchase?from=2026-06-01&to=2026-06-30&category_id={$categoryAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items', 1)
            ->where('items.0.name', 'Item A')
            ->where('totals.total_value', '200.00')
            ->has('itemsList', 1)
            ->where('itemsList.0.name', 'Item A')
            ->where('categoryId', $categoryAId)
        );

    $tenant->delete();
});

test('the item-wise purchase report can be narrowed to a single brand (audit T15-11)', function () {
    $domain = 'item-wise-purchase-brand-filter.tenant-test';
    $tenant = provisionItemWisePurchaseReportTestTenant($domain);

    $brandAId = null;
    $tenant->run(function () use (&$brandAId) {
        itemWisePurchaseReportTestOpenFiscalYear();
        $admin = itemWisePurchaseReportTestAdmin();
        $supplier = Supplier::factory()->create();
        $brandA = Brand::factory()->create(['name' => 'Brand A']);
        $itemA = Item::factory()->create(['name' => 'Item A', 'unit' => 'pcs', 'brand_id' => $brandA->id, 'is_vatable' => false, 'is_stockable' => false]);
        $itemB = Item::factory()->create(['name' => 'Item B', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemA->id, 'quantity' => 1, 'rate' => 300, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemB->id, 'quantity' => 1, 'rate' => 999, 'discount' => 0]],
            $admin,
        );

        $brandAId = $brandA->id;
    });

    loginItemWisePurchaseReportTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-purchase?from=2026-06-01&to=2026-06-30&brand_id={$brandAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items', 1)
            ->where('items.0.name', 'Item A')
            ->where('totals.total_value', '300.00')
            ->where('brandId', $brandAId)
        );

    $tenant->delete();
});

test('omitting the category/subcategory/brand filters preserves the unfiltered purchase report exactly (no regression, audit T15-11)', function () {
    $domain = 'item-wise-purchase-no-group-filter.tenant-test';
    $tenant = provisionItemWisePurchaseReportTestTenant($domain);

    $tenant->run(function () {
        itemWisePurchaseReportTestOpenFiscalYear();
        $admin = itemWisePurchaseReportTestAdmin();
        $supplier = Supplier::factory()->create();
        $itemA = Item::factory()->create(['name' => 'Item A', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);
        $itemB = Item::factory()->create(['name' => 'Item B', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemA->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemB->id, 'quantity' => 5, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginItemWisePurchaseReportTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-purchase?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items', 2)
            ->where('totals.total_value', '700.00')
            ->where('categoryId', null)
            ->where('subcategoryId', null)
            ->where('brandId', null)
            ->has('itemsList', 2)
        );

    $tenant->delete();
});

test('without an item_id the purchase report returns no per-line detail at all', function () {
    $domain = 'item-wise-purchase-no-drilldown.tenant-test';
    $tenant = provisionItemWisePurchaseReportTestTenant($domain);

    $tenant->run(function () {
        itemWisePurchaseReportTestOpenFiscalYear();
        $admin = itemWisePurchaseReportTestAdmin();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget', 'is_vatable' => false, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginItemWisePurchaseReportTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-purchase?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('itemId', null)
            ->has('lines', 0)
        );

    $tenant->delete();
});
