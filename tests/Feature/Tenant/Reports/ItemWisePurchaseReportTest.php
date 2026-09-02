<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Item;
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
            ->where('items.0.total_quantity', 1)
            ->where('items.0.total_value', 500)
            ->where('items.0.transaction_count', 1)
            ->where('items.1.name', 'Widget')
            ->where('items.1.total_quantity', 5)
            ->where('items.1.total_value', 350)
            ->where('items.1.transaction_count', 2)
            ->where('totals.total_quantity', 6)
            ->where('totals.total_value', 850)
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
            ->where('items.0.total_quantity', 1)
            ->where('items.0.total_value', 100)
            ->where('items.0.transaction_count', 1)
            ->where('totals.total_quantity', 1)
            ->where('totals.total_value', 100)
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
            ->where('items.0.total_value', 100)
            ->where('totals.total_value', 100)
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
            ->where('items.0.total_quantity', 1)
            ->where('items.0.total_value', 100)
            ->where('totals.total_value', 100)
        );

    $tenant->delete();
});
