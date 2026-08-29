<?php

use App\Enums\FiscalYearStatus;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Sale;
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

    // Hand-computed: quantity 2 + 3 = 5; value 200 + 290 = 490; across 2
    // distinct sales.
    $this->get("http://{$domain}/reports/item-wise-sales?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/ItemWiseSales')
            ->has('items', 1)
            ->where('items.0.name', 'Widget')
            ->where('items.0.unit', 'pcs')
            ->where('items.0.total_quantity', 5)
            ->where('items.0.total_value', 490)
            ->where('items.0.transaction_count', 2)
            ->where('totals.total_quantity', 5)
            ->where('totals.total_value', 490)
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
            ->where('items.0.total_quantity', 1)
            ->where('items.0.total_value', 100)
            ->where('totals.total_value', 100)
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
            ->where('totals.total_quantity', 0)
            ->where('totals.total_value', 0)
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
        $cheapItem = Item::factory()->create(['name' => 'Cheap Item', 'is_vatable' => false, 'is_stockable' => false]);
        $pricyItem = Item::factory()->create(['name' => 'Pricy Item', 'is_vatable' => false, 'is_stockable' => false]);

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
            ->where('items.0.total_value', 500)
            ->where('items.1.name', 'Cheap Item')
            ->where('items.1.total_value', 100)
            ->where('totals.total_quantity', 11)
            ->where('totals.total_value', 600)
        );

    $tenant->delete();
});
