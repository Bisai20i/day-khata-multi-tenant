<?php

use App\Enums\FiscalYearStatus;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Covers the server-side date-range/customer filtering added to
 * SaleController::index() (Part 1 of the "restore server-side list
 * filtering" task) - confirms the `from`/`to`/`customer_id` query params
 * actually narrow the paginated `sales` prop, not just that the page renders.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSaleListFilterTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginSaleListFilterTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the date range filter narrows the sales list', function () {
    $domain = 'sale-filter-date.tenant-test';
    $tenant = provisionSaleListFilterTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-15', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        // Outside the filtered range below - must not show up.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-07-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginSaleListFilterTestUser($domain);

    $query = http_build_query(['from' => '2026-06-01', 'to' => '2026-06-30']);

    $this->get("http://{$domain}/sales?{$query}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sales.total', 2)
            ->where('filters.from', '2026-06-01')
            ->where('filters.to', '2026-06-30'));

    $tenant->delete();
});

test('the customer filter narrows the sales list', function () {
    $domain = 'sale-filter-customer.tenant-test';
    $tenant = provisionSaleListFilterTestTenant($domain);

    $customerAId = null;
    $tenant->run(function () use (&$customerAId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create();
        $customerA = Customer::factory()->create(['name' => 'Customer A']);
        $customerB = Customer::factory()->create(['name' => 'Customer B']);
        $customerAId = $customerA->id;
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customerA->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customerA->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        // Different customer - must not show up when filtering by A.
        Sale::post(
            ['customer_id' => $customerB->id, 'invoice_type' => 'full', 'date' => '2026-06-03', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginSaleListFilterTestUser($domain);

    $this->get("http://{$domain}/sales?".http_build_query(['customer_id' => $customerAId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sales.total', 2)
            ->where('sales.data.0.customer.name', 'Customer A')
            ->where('sales.data.1.customer.name', 'Customer A'));

    $tenant->delete();
});

test('with no filters applied every sale is returned', function () {
    $domain = 'sale-filter-none.tenant-test';
    $tenant = provisionSaleListFilterTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-07-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginSaleListFilterTestUser($domain);

    $this->get("http://{$domain}/sales")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sales.total', 2)
            ->where('filters.from', null)
            ->where('filters.customer_id', null));

    $tenant->delete();
});
