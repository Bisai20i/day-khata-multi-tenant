<?php

use App\Enums\FiscalYearStatus;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionReportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginReportTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

function reportTestAdmin(): User
{
    return User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
}

function reportTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('the sales register includes posted and cancelled sales, but totals only the posted ones', function () {
    $domain = 'sales-register.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $saleIds = [];
    $tenant->run(function () use (&$saleIds) {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $keep = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $cancelled = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 50, 'discount' => 0]],
            $admin,
        );
        $cancelled->cancel($admin, 'Recorded in error');

        // Out of the [2026-06-01, 2026-06-30] filter range used below.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-07-15', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 999, 'discount' => 0]],
            $admin,
        );

        $saleIds = ['keep' => $keep->id, 'cancelled' => $cancelled->id];
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/sales-register?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/SalesRegister')
            ->has('sales', 2)
            ->where('totals.taxable_amount', 100)
            ->where('totals.vat_amount', 13)
            ->where('totals.total', 113)
        );

    $tenant->delete();
});

test('the sales VAT book excludes cancelled sales entirely and its grand totals match the posted-only sum', function () {
    $domain = 'sales-vat-book.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $cancelled = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 200, 'discount' => 0]],
            $admin,
        );
        $cancelled->cancel($admin, 'Recorded in error');
    });

    loginReportTestUser($domain);

    // Hand-computed: only the 100 (taxable) sale survives; the cancelled
    // 200 sale must not contribute to either the row count or the totals.
    $this->get("http://{$domain}/reports/sales-vat-book?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/SalesVatBook')
            ->has('rows', 1)
            ->where('rows.0.sn', 1)
            ->where('totals.taxable_amount', 100)
            ->where('totals.vat_amount', 13)
            ->where('totals.total', 113)
        );

    $tenant->delete();
});

test('the sales register can be narrowed to a single customer', function () {
    $domain = 'sales-register-filter.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $customerAId = null;
    $tenant->run(function () use (&$customerAId) {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customerA = Customer::factory()->create(['name' => 'Customer A']);
        $customerB = Customer::factory()->create(['name' => 'Customer B']);
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customerA->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customerB->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $customerAId = $customerA->id;
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/sales-register?from=2026-06-01&to=2026-06-30&customer_id={$customerAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 1)
            ->where('sales.0.customer', 'Customer A')
        );

    $tenant->delete();
});

test('the purchase register includes posted and cancelled purchases, but totals only the posted ones', function () {
    $domain = 'purchase-register.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $cancelled = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 50, 'discount' => 0]],
            $admin,
        );
        $cancelled->cancel($admin, 'Recorded in error');
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/purchase-register?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/PurchaseRegister')
            ->has('purchases', 2)
            ->where('totals.taxable_amount', 100)
            ->where('totals.vat_amount', 13)
            ->where('totals.total', 113)
        );

    $tenant->delete();
});

test('the purchase VAT book excludes cancelled purchases and includes the supplier PAN', function () {
    $domain = 'purchase-vat-book.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'pan_number' => '600123456', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $cancelled = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 200, 'discount' => 0]],
            $admin,
        );
        $cancelled->cancel($admin, 'Recorded in error');
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/purchase-vat-book?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/PurchaseVatBook')
            ->has('rows', 1)
            ->where('rows.0.pan_number', '600123456')
            ->where('totals.taxable_amount', 100)
            ->where('totals.vat_amount', 13)
            ->where('totals.total', 113)
        );

    $tenant->delete();
});

test('a date range outside a sale excludes it from the register', function () {
    $domain = 'sales-register-daterange.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-03-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/sales-register?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('sales', 0));

    $tenant->delete();
});
