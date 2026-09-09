<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Covers the server-side date-range/supplier filtering added to
 * PurchaseController::index() (Part 1 of the "restore server-side list
 * filtering" task) - confirms the `from`/`to`/`supplier_id` query params
 * actually narrow the paginated `purchases` prop, not just that the page
 * renders.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseListFilterTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginPurchaseListFilterTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the date range filter narrows the purchases list', function () {
    $domain = 'purchase-filter-date.tenant-test';
    $tenant = provisionPurchaseListFilterTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-15', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        // Outside the filtered range below - must not show up.
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-07-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginPurchaseListFilterTestUser($domain);

    $query = http_build_query(['from' => '2026-06-01', 'to' => '2026-06-30']);

    $this->get("http://{$domain}/purchases?{$query}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('purchases.total', 2)
            ->where('filters.from', '2026-06-01')
            ->where('filters.to', '2026-06-30'));

    $tenant->delete();
});

test('the supplier filter narrows the purchases list', function () {
    $domain = 'purchase-filter-supplier.tenant-test';
    $tenant = provisionPurchaseListFilterTestTenant($domain);

    $supplierAId = null;
    $tenant->run(function () use (&$supplierAId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create();
        $supplierA = Supplier::factory()->create(['name' => 'Supplier A']);
        $supplierB = Supplier::factory()->create(['name' => 'Supplier B']);
        $supplierAId = $supplierA->id;
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplierA->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplierA->id, 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        // Different supplier - must not show up when filtering by A.
        Purchase::post(
            ['supplier_id' => $supplierB->id, 'date' => '2026-06-03', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginPurchaseListFilterTestUser($domain);

    $this->get("http://{$domain}/purchases?".http_build_query(['supplier_id' => $supplierAId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('purchases.total', 2)
            ->where('purchases.data.0.supplier.name', 'Supplier A')
            ->where('purchases.data.1.supplier.name', 'Supplier A'));

    $tenant->delete();
});

test('with no filters applied every purchase is returned', function () {
    $domain = 'purchase-filter-none.tenant-test';
    $tenant = provisionPurchaseListFilterTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-07-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginPurchaseListFilterTestUser($domain);

    $this->get("http://{$domain}/purchases")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('purchases.total', 2)
            ->where('filters.from', null)
            ->where('filters.supplier_id', null));

    $tenant->delete();
});
