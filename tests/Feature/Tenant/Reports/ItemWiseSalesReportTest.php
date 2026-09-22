<?php

use App\Enums\FiscalYearStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionItemWiseSalesTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginItemWiseSalesTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the item-wise sales report aggregates quantity and value across multiple sales of the same item', function () {
    $domain = 'item-wise-sales.tenant-test';
    $tenant = provisionItemWiseSalesTestTenant($domain);

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);

        // Sale 1: 2 units @ 100 = 200 line total.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // Sale 2: 3 units @ 100, with a 10 line discount => 290 line total.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-15', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 3, 'rate' => 100, 'discount' => 10]],
            $admin,
        );
    });

    loginItemWiseSalesTestUser($domain);

    // Hand-computed: base quantity 2 + 3 = 5 (both lines are entered in the
    // item's base unit, so the conversion factor is 1); value 200 + 290 =
    // 490; across 2 distinct sales.
    $this->get("http://{$domain}/reports/item-wise-sales?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/ItemWiseSales')
            ->has('items', 1)
            ->where('items.0.name', 'Widget')
            ->where('items.0.unit', 'pcs')
            // Exact decimal strings now: quantities at 4 places, money at 2.
            ->where('items.0.total_quantity', '5.0000')
            ->where('items.0.total_value', '490.00')
            ->where('items.0.transaction_count', 2)
            // The unit-blind totals.total_quantity scalar is gone; quantities
            // are grouped per unit because adding kg to pcs means nothing.
            ->has('totals.quantities', 1)
            ->where('totals.quantities.0.unit', 'pcs')
            ->where('totals.quantities.0.quantity', '5.0000')
            ->where('totals.total_value', '490.00')
        );

    $tenant->delete();
});

test('a cancelled sale is excluded from the item-wise sales report', function () {
    $domain = 'item-wise-sales-cancelled.tenant-test';
    $tenant = provisionItemWiseSalesTestTenant($domain);

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget', 'is_vatable' => false, 'is_stockable' => false]);

        $kept = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $cancelled = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 200, 'discount' => 0]],
            $admin,
        );
        $cancelled->cancel($admin, 'Recorded in error');

        expect($kept)->not->toBeNull();
    });

    loginItemWiseSalesTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-sales?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items', 1)
            ->where('items.0.total_quantity', '1.0000')
            ->where('items.0.total_value', '100.00')
            ->where('totals.total_value', '100.00')
        );

    $tenant->delete();
});

test('a sale outside the date range is excluded from the item-wise sales report', function () {
    $domain = 'item-wise-sales-daterange.tenant-test';
    $tenant = provisionItemWiseSalesTestTenant($domain);

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget', 'is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-07-15', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 999, 'discount' => 0]],
            $admin,
        );
    });

    loginItemWiseSalesTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-sales?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items', 0)
            // No item rows means no per-unit quantity groups at all.
            ->has('totals.quantities', 0)
            ->where('totals.total_value', '0.00')
        );

    $tenant->delete();
});

test('the item-wise sales report sorts by total value descending and grand-totals correctly across items', function () {
    $domain = 'item-wise-sales-sort.tenant-test';
    $tenant = provisionItemWiseSalesTestTenant($domain);

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        // Both items share one unit on purpose: the grand total is now a
        // per-unit breakdown, so a random factory unit would make it
        // non-deterministic.
        $cheapItem = Item::factory()->create(['name' => 'Cheap Item', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);
        $pricyItem = Item::factory()->create(['name' => 'Pricy Item', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);

        // Cheap item: 10 units @ 10 = 100 total value.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $cheapItem->id, 'quantity' => 10, 'rate' => 10, 'discount' => 0]],
            $admin,
        );

        // Pricy item: 1 unit @ 500 = 500 total value - should sort first.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $pricyItem->id, 'quantity' => 1, 'rate' => 500, 'discount' => 0]],
            $admin,
        );
    });

    loginItemWiseSalesTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-sales?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items', 2)
            ->where('items.0.name', 'Pricy Item')
            ->where('items.0.total_value', '500.00')
            ->where('items.1.name', 'Cheap Item')
            ->where('items.1.total_value', '100.00')
            // 10 + 1 = 11, now reported under the shared "pcs" unit instead
            // of as one unit-blind scalar.
            ->has('totals.quantities', 1)
            ->where('totals.quantities.0.unit', 'pcs')
            ->where('totals.quantities.0.quantity', '11.0000')
            ->where('totals.total_value', '600.00')
        );

    $tenant->delete();
});

test('the item-wise sales report can be narrowed to a single store', function () {
    $domain = 'item-wise-sales-store-filter.tenant-test';
    $tenant = provisionItemWiseSalesTestTenant($domain);

    $storeAId = null;
    $tenant->run(function () use (&$storeAId) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);
        $storeA = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $storeB = Store::factory()->create(['name' => 'Branch Store']);

        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $storeA->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $storeB->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $storeAId = $storeA->id;
    });

    loginItemWiseSalesTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-sales?from=2026-06-01&to=2026-06-30&store_id={$storeAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items', 1)
            ->where('items.0.total_quantity', '2.0000')
            ->where('items.0.total_value', '200.00')
            ->where('totals.total_value', '200.00')
        );

    $tenant->delete();
});

test('picking an item_id keeps the all-items aggregate and adds that item\'s raw per-line transaction ledger (audit T15-6)', function () {
    $domain = 'item-wise-sales-drilldown.tenant-test';
    $tenant = provisionItemWiseSalesTestTenant($domain);

    $widgetId = null;
    $invoiceNumber = null;
    $customerName = null;
    $tenant->run(function () use (&$widgetId, &$invoiceNumber, &$customerName) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create(['name' => 'Ram Shrestha']);
        $widget = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);
        $gadget = Item::factory()->create(['name' => 'Gadget', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);

        // Two Widget sales at different rates, plus an unrelated Gadget
        // sale that must show in the aggregate but never in Widget's lines.
        $sale1 = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $widget->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-10', 'payment_mode' => 'cash'],
            [['item_id' => $widget->id, 'quantity' => 3, 'rate' => 120, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-05', 'payment_mode' => 'cash'],
            [['item_id' => $gadget->id, 'quantity' => 1, 'rate' => 500, 'discount' => 0]],
            $admin,
        );

        $widgetId = $widget->id;
        $invoiceNumber = $sale1->invoice_number;
        $customerName = $customer->name;
    });

    loginItemWiseSalesTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-sales?from=2026-06-01&to=2026-06-30&item_id={$widgetId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // The aggregate is untouched by the item_id filter: both items
            // still appear, exactly as without any item_id at all.
            ->has('items', 2)
            ->where('itemId', $widgetId)
            // Alongside it, the raw per-line ledger for just the picked
            // item: two Widget lines, in date order, never the Gadget line.
            ->has('lines', 2)
            ->where('lines.0.document_number', $invoiceNumber)
            ->where('lines.0.party_name', $customerName)
            ->where('lines.0.rate', '100.0000')
            ->where('lines.0.quantity', '2.0000')
            ->where('lines.0.line_total', '200.00')
            ->where('lines.0.vatable', false)
            ->where('lines.1.rate', '120.0000')
            ->where('lines.1.quantity', '3.0000')
            ->where('lines.1.line_total', '360.00')
        );

    $tenant->delete();
});

test('the item-wise sales report can be narrowed to a single category (audit T15-11)', function () {
    $domain = 'item-wise-sales-category-filter.tenant-test';
    $tenant = provisionItemWiseSalesTestTenant($domain);

    $categoryAId = null;
    $tenant->run(function () use (&$categoryAId) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $categoryA = ItemCategory::factory()->create(['name' => 'Category A']);
        $categoryB = ItemCategory::factory()->create(['name' => 'Category B']);
        $itemA = Item::factory()->create(['name' => 'Item A', 'unit' => 'pcs', 'item_category_id' => $categoryA->id, 'is_vatable' => false, 'is_stockable' => false]);
        $itemB = Item::factory()->create(['name' => 'Item B', 'unit' => 'pcs', 'item_category_id' => $categoryB->id, 'is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemA->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemB->id, 'quantity' => 5, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $categoryAId = $categoryA->id;
    });

    loginItemWiseSalesTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-sales?from=2026-06-01&to=2026-06-30&category_id={$categoryAId}")
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

test('the item-wise sales report can be narrowed to a single brand (audit T15-11)', function () {
    $domain = 'item-wise-sales-brand-filter.tenant-test';
    $tenant = provisionItemWiseSalesTestTenant($domain);

    $brandAId = null;
    $tenant->run(function () use (&$brandAId) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $brandA = Brand::factory()->create(['name' => 'Brand A']);
        $itemA = Item::factory()->create(['name' => 'Item A', 'unit' => 'pcs', 'brand_id' => $brandA->id, 'is_vatable' => false, 'is_stockable' => false]);
        $itemB = Item::factory()->create(['name' => 'Item B', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemA->id, 'quantity' => 1, 'rate' => 300, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemB->id, 'quantity' => 1, 'rate' => 999, 'discount' => 0]],
            $admin,
        );

        $brandAId = $brandA->id;
    });

    loginItemWiseSalesTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-sales?from=2026-06-01&to=2026-06-30&brand_id={$brandAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items', 1)
            ->where('items.0.name', 'Item A')
            ->where('totals.total_value', '300.00')
            ->where('brandId', $brandAId)
        );

    $tenant->delete();
});

test('combining item_id with category_id scopes the aggregate to the category while the drill-down still targets the picked item (audit T15-11)', function () {
    $domain = 'item-wise-sales-category-item-combo.tenant-test';
    $tenant = provisionItemWiseSalesTestTenant($domain);

    $categoryAId = null;
    $itemAId = null;
    $tenant->run(function () use (&$categoryAId, &$itemAId) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $categoryA = ItemCategory::factory()->create(['name' => 'Category A']);
        $categoryB = ItemCategory::factory()->create(['name' => 'Category B']);
        $itemA = Item::factory()->create(['name' => 'Item A', 'unit' => 'pcs', 'item_category_id' => $categoryA->id, 'is_vatable' => false, 'is_stockable' => false]);
        $itemB = Item::factory()->create(['name' => 'Item B', 'unit' => 'pcs', 'item_category_id' => $categoryB->id, 'is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemA->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemB->id, 'quantity' => 5, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $categoryAId = $categoryA->id;
        $itemAId = $itemA->id;
    });

    loginItemWiseSalesTestUser($domain);

    // Aggregate is scoped to category A (only Item A), and item_id (also
    // Item A here) additionally drives the per-line drill-down.
    $this->get("http://{$domain}/reports/item-wise-sales?from=2026-06-01&to=2026-06-30&category_id={$categoryAId}&item_id={$itemAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('items', 1)
            ->where('items.0.name', 'Item A')
            ->has('lines', 1)
            ->where('itemId', $itemAId)
            ->where('categoryId', $categoryAId)
        );

    $tenant->delete();
});

test('omitting the category/subcategory/brand filters preserves the unfiltered report exactly (no regression, audit T15-11)', function () {
    $domain = 'item-wise-sales-no-group-filter.tenant-test';
    $tenant = provisionItemWiseSalesTestTenant($domain);

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $itemA = Item::factory()->create(['name' => 'Item A', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);
        $itemB = Item::factory()->create(['name' => 'Item B', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemA->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $itemB->id, 'quantity' => 5, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginItemWiseSalesTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-sales?from=2026-06-01&to=2026-06-30")
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

test('without an item_id the sales report returns no per-line detail at all', function () {
    $domain = 'item-wise-sales-no-drilldown.tenant-test';
    $tenant = provisionItemWiseSalesTestTenant($domain);

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget', 'is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginItemWiseSalesTestUser($domain);

    $this->get("http://{$domain}/reports/item-wise-sales?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('itemId', null)
            ->has('lines', 0)
        );

    $tenant->delete();
});
