<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\CapitalSale;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

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

    Carbon::setTestNow('2026-08-10 10:00:00');

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
            ->where('totals.taxable_amount', '100.00')
            ->where('totals.vat_amount', '13.00')
            ->where('totals.total', '113.00')
        );

    Carbon::setTestNow();

    $tenant->delete();
});

test('the sales VAT book keeps a cancelled invoice in the month it was issued and takes it back out in the cancel month', function () {
    $domain = 'sales-vat-book.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    Carbon::setTestNow('2026-08-10 10:00:00');

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

    // June was a filing month: both invoices were issued in it, so both stay
    // in it. Dropping the cancelled one rewrote a month that may already have
    // been filed, and it disagreed with the ledger, which reverses only on
    // the cancel date (C5, audit P0-20).
    $this->get("http://{$domain}/reports/sales-vat-book?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/SalesVatBook')
            ->has('rows', 2)
            ->where('rows.0.sn', 1)
            ->where('rows.0.entry', 'issued')
            ->where('rows.1.entry', 'issued')
            ->where('totals.taxable_amount', '300.00')
            ->where('totals.vat_amount', '39.00')
            ->where('totals.total', '339.00')
        );

    // August carries the cancellation as a single negative row.
    $this->get("http://{$domain}/reports/sales-vat-book?from=2026-08-01&to=2026-08-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.entry', 'cancelled')
            ->where('rows.0.taxable_amount', '-200.00')
            ->where('rows.0.vat_amount', '-26.00')
            ->where('totals.total', '-226.00')
        );

    Carbon::setTestNow();

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

    Carbon::setTestNow('2026-08-10 10:00:00');

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
            ->where('totals.taxable_amount', '100.00')
            ->where('totals.vat_amount', '13.00')
            ->where('totals.total', '113.00')
        );

    Carbon::setTestNow();

    $tenant->delete();
});

test('the purchase VAT book shows the supplier bill number and PAN and keeps a cancelled bill in its own month', function () {
    $domain = 'purchase-vat-book.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    Carbon::setTestNow('2026-08-10 10:00:00');

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'pan_number' => '600123456', 'bill_number' => 'SUP-4471', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
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
            ->has('rows', 2)
            // The supplier bill number, never our internal voucher number: an
            // input-VAT claim is checked against the seller invoice (audit P1).
            ->where('rows.0.bill_number', 'SUP-4471')
            ->where('rows.0.supplier_pan', '600123456')
            ->where('rows.0.entry', 'issued')
            ->where('totals.taxable_amount', '300.00')
            ->where('totals.vat_amount', '39.00')
            ->where('totals.total', '339.00')
        );

    $this->get("http://{$domain}/reports/purchase-vat-book?from=2026-08-01&to=2026-08-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.entry', 'cancelled')
            ->where('rows.0.taxable_amount', '-200.00')
            ->where('totals.total', '-226.00')
        );

    Carbon::setTestNow();

    $tenant->delete();
});

test('aged receivables buckets an outstanding credit sale by days elapsed as of a given date', function () {
    $domain = 'aged-receivables.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create(['name' => 'Aging Customer']);
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // 2026-01-01 to 2026-02-15 (as_of below) is 45 days - the 31-60 bucket.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-01-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/aged-receivables?as_of=2026-02-15")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/AgedReceivables')
            ->has('rows', 1)
            ->where('rows.0.party', 'Aging Customer')
            ->where('rows.0.opening', '0.00')
            ->where('rows.0.current', '0.00')
            ->where('rows.0.days31_60', '113.00')
            ->where('rows.0.days61_90', '0.00')
            ->where('rows.0.days90Plus', '0.00')
            ->where('rows.0.total', '113.00')
            ->where('totals.total', '113.00')
        );

    $tenant->delete();
});

test('aged receivables shows the reduced outstanding amount after a partial sales return', function () {
    $domain = 'aged-receivables-partial-return.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-01-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // Sale total: 200 taxable + 26 VAT = 226. Returning 1 of 2 units
        // reverses half: 100 taxable + 13 VAT = 113. Outstanding: 226 - 113 = 113.
        SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-01-05'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/aged-receivables?as_of=2026-01-10")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.total', '113.00')
        );

    $tenant->delete();
});

test('aged receivables excludes cash-settled sales and fully-returned credit sales', function () {
    $domain = 'aged-receivables-exclusions.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-01-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $fullyReturned = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-01-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 50, 'discount' => 0]],
            $admin,
        );
        SalesReturn::post(
            ['sale_id' => $fullyReturned->id, 'date' => '2026-01-02'],
            [['sale_line_id' => $fullyReturned->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/aged-receivables?as_of=2026-01-10")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('rows', 0));

    $tenant->delete();
});

test('aged payables buckets an outstanding credit purchase by days elapsed as of a given date', function () {
    $domain = 'aged-payables.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $supplier = Supplier::factory()->create(['name' => 'Aging Supplier']);
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-01-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/aged-payables?as_of=2026-02-15")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/AgedPayables')
            ->has('rows', 1)
            ->where('rows.0.party', 'Aging Supplier')
            ->where('rows.0.days31_60', '113.00')
            ->where('rows.0.total', '113.00')
        );

    $tenant->delete();
});

test('aged payables excludes cash-settled purchases and fully-returned credit purchases', function () {
    $domain = 'aged-payables-exclusions.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-01-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $fullyReturned = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-01-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 50, 'discount' => 0]],
            $admin,
        );
        PurchaseReturn::post(
            ['purchase_id' => $fullyReturned->id, 'date' => '2026-01-02'],
            [['purchase_line_id' => $fullyReturned->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/aged-payables?as_of=2026-01-10")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('rows', 0));

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

test('the sales register can be narrowed to a single store', function () {
    $domain = 'sales-register-store-filter.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $storeAId = null;
    $tenant->run(function () use (&$storeAId) {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $storeA = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $storeB = Store::factory()->create(['name' => 'Branch Store']);

        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $storeA->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $storeB->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 250, 'discount' => 0]],
            $admin,
        );

        $storeAId = $storeA->id;
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/sales-register?from=2026-06-01&to=2026-06-30&store_id={$storeAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 1)
            ->where('sales.0.total', '100.00')
            ->where('totals.total', '100.00')
        );

    $tenant->delete();
});

test('the purchase register can be narrowed to a single store', function () {
    $domain = 'purchase-register-store-filter.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $storeAId = null;
    $tenant->run(function () use (&$storeAId) {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $storeA = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $storeB = Store::factory()->create(['name' => 'Branch Store']);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'store_id' => $storeA->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'store_id' => $storeB->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 250, 'discount' => 0]],
            $admin,
        );

        $storeAId = $storeA->id;
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/purchase-register?from=2026-06-01&to=2026-06-30&store_id={$storeAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('purchases', 1)
            ->where('purchases.0.total', '100.00')
            ->where('totals.total', '100.00')
        );

    $tenant->delete();
});

test('the sales VAT book can be narrowed to a single store', function () {
    $domain = 'sales-vat-book-store-filter.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $storeAId = null;
    $tenant->run(function () use (&$storeAId) {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
        $storeA = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $storeB = Store::factory()->create(['name' => 'Branch Store']);

        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $storeA->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $storeB->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 200, 'discount' => 0]],
            $admin,
        );

        $storeAId = $storeA->id;
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/sales-vat-book?from=2026-06-01&to=2026-06-30&store_id={$storeAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('totals.taxable_amount', '100.00')
            ->where('totals.total', '113.00')
        );

    $tenant->delete();
});

test('the purchase VAT book can be narrowed to a single store', function () {
    $domain = 'purchase-vat-book-store-filter.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $storeAId = null;
    $tenant->run(function () use (&$storeAId) {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
        $storeA = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $storeB = Store::factory()->create(['name' => 'Branch Store']);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'store_id' => $storeA->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'store_id' => $storeB->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 200, 'discount' => 0]],
            $admin,
        );

        $storeAId = $storeA->id;
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/purchase-vat-book?from=2026-06-01&to=2026-06-30&store_id={$storeAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('totals.taxable_amount', '100.00')
            ->where('totals.total', '113.00')
        );

    $tenant->delete();
});

test('aged receivables can be narrowed to a single store', function () {
    $domain = 'aged-receivables-store-filter.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $storeAId = null;
    $tenant->run(function () use (&$storeAId) {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customerA = Customer::factory()->create(['name' => 'Store A Customer']);
        $customerB = Customer::factory()->create(['name' => 'Store B Customer']);
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $storeA = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $storeB = Store::factory()->create(['name' => 'Branch Store']);

        Sale::post(
            ['customer_id' => $customerA->id, 'store_id' => $storeA->id, 'invoice_type' => 'full', 'date' => '2026-01-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customerB->id, 'store_id' => $storeB->id, 'invoice_type' => 'full', 'date' => '2026-01-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 200, 'discount' => 0]],
            $admin,
        );

        $storeAId = $storeA->id;
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/aged-receivables?as_of=2026-01-10&store_id={$storeAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.party', 'Store A Customer')
            ->where('totals.total', '100.00')
        );

    $tenant->delete();
});

test('aged payables can be narrowed to a single store', function () {
    $domain = 'aged-payables-store-filter.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $storeAId = null;
    $tenant->run(function () use (&$storeAId) {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $supplierA = Supplier::factory()->create(['name' => 'Store A Supplier']);
        $supplierB = Supplier::factory()->create(['name' => 'Store B Supplier']);
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $storeA = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $storeB = Store::factory()->create(['name' => 'Branch Store']);

        Purchase::post(
            ['supplier_id' => $supplierA->id, 'store_id' => $storeA->id, 'date' => '2026-01-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplierB->id, 'store_id' => $storeB->id, 'date' => '2026-01-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 200, 'discount' => 0]],
            $admin,
        );

        $storeAId = $storeA->id;
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/aged-payables?as_of=2026-01-10&store_id={$storeAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.party', 'Store A Supplier')
            ->where('totals.total', '100.00')
        );

    $tenant->delete();
});

test('the sales VAT book prints the stored invoice number and the buyer PAN snapshot', function () {
    $domain = 'sales-vat-book-buyer.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create(['name' => 'Himalaya Traders', 'tpin' => '301234567']);
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // Renaming the customer afterwards must not rewrite a filed book: the
        // buyer snapshot taken at posting is what gets printed (C7).
        $customer->update(['name' => 'Renamed Later']);

        expect($sale->fresh()->invoice_number)->not->toBeNull();
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/sales-vat-book?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.buyer_name', 'Himalaya Traders')
            ->where('rows.0.buyer_pan', '301234567')
            ->where('rows.0.invoice_number', fn ($number) => is_string($number) && $number !== '')
        );

    $tenant->delete();
});

test('the sales VAT book includes capital sales in their own capital column', function () {
    $domain = 'sales-vat-book-capital.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $account = Account::factory()->create();

        // 1000 vatable => 130 output VAT, the same LIA20 credit an ordinary
        // invoice posts, so it belongs in the book (audit P0-20).
        CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => '1000', 'vatable' => true]],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/sales-vat-book?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.capital', true)
            ->where('rows.0.capital_amount', '1000.00')
            ->where('rows.0.vat_amount', '130.00')
            ->where('totals.capital_amount', '1000.00')
            ->where('totals.total', '1130.00')
        );

    $tenant->delete();
});

test('the purchase VAT book includes capital purchases in their own capital column', function () {
    $domain = 'purchase-vat-book-capital.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $supplier = Supplier::factory()->create(['name' => 'Capital Supplier', 'tpin' => '609999111']);
        $account = Account::factory()->create();

        CapitalPurchase::post(
            [
                'type' => 'capital',
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'bill_number' => 'CAP-9',
            ],
            [['account_id' => $account->id, 'amount' => '1000', 'vatable' => true]],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/purchase-vat-book?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.capital', true)
            ->where('rows.0.bill_number', 'CAP-9')
            ->where('rows.0.supplier_pan', '609999111')
            ->where('rows.0.capital_amount', '1000.00')
            ->where('rows.0.vat_amount', '130.00')
            ->where('totals.capital_amount', '1000.00')
        );

    $tenant->delete();
});

test('the sales return register lists posted credit notes with their stored number and the invoice they credit', function () {
    $domain = 'sales-return-register.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create(['name' => 'Note Customer', 'tpin' => '302222333']);
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $note = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-10'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );

        // A pending request reserves quantity and nothing else, so it must
        // never be listed or numbered as a credit note (C6, C7).
        SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-12'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );

        expect($note->fresh()->credit_note_number)->not->toBeNull();
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/sales-return-register?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/SalesReturnRegister')
            ->has('rows', 1)
            ->where('rows.0.entry', 'issued')
            ->where('rows.0.buyer_name', 'Note Customer')
            ->where('rows.0.buyer_pan', '302222333')
            ->where('rows.0.credit_note_number', fn ($number) => is_string($number) && $number !== '')
            ->where('rows.0.invoice_number', fn ($number) => is_string($number) && $number !== '')
            ->where('rows.0.taxable_amount', '100.00')
            ->where('rows.0.vat_amount', '13.00')
            ->where('totals.total', '113.00')
        );

    $tenant->delete();
});

test('the purchase return register lists posted debit notes with their stored number and the supplier bill', function () {
    $domain = 'purchase-return-register.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $supplier = Supplier::factory()->create(['name' => 'Note Supplier', 'tpin' => '604444555']);
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'bill_number' => 'SUP-777', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $note = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-10'],
            [['purchase_line_id' => $purchase->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );

        expect($note->fresh()->debit_note_number)->not->toBeNull();
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/purchase-return-register?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/PurchaseReturnRegister')
            ->has('rows', 1)
            ->where('rows.0.entry', 'issued')
            ->where('rows.0.supplier', 'Note Supplier')
            ->where('rows.0.supplier_pan', '604444555')
            ->where('rows.0.bill_number', 'SUP-777')
            ->where('rows.0.debit_note_number', fn ($number) => is_string($number) && $number !== '')
            ->where('rows.0.taxable_amount', '100.00')
            ->where('rows.0.vat_amount', '13.00')
            ->where('totals.total', '113.00')
        );

    $tenant->delete();
});

test('aged receivables leaves no residue once a credit sale with TDS is settled to the paisa', function () {
    $domain = 'aged-receivables-exact-settlement.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create();
        $tdsAccount = Account::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // 100 taxable + 13 VAT = 113, less 10 withheld at source, so the
        // customer only ever pays 103. Before the rewrite the report kept a
        // 10.00 stub here forever, and the old `<= 0.01` filter would not have
        // hidden a one paisa residue either - it would have hidden a real one
        // paisa debt.
        $sale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-01-01',
                'payment_mode' => 'credit',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 10,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-01-05',
            'amount' => 103,
            'payment_mode' => 'cash',
            'allocations' => [['sale_id' => $sale->id, 'amount' => 103]],
        ], $admin);

        expect($sale->fresh()->outstandingAmount()->toString())->toBe('0.00');
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/aged-receivables?as_of=2026-01-10")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 0)
            ->where('totals.total', '0.00')
        );

    $tenant->delete();
});

test('aged receivables shows a migrated opening due that no invoice explains', function () {
    $domain = 'aged-receivables-opening.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create(['name' => 'Migrated Customer']);
        $capital = Account::factory()->create();

        // Exactly what an opening balance import looks like in the ledger:
        // the party is owed for, with no invoice in this system behind it.
        // The old report only ever looked at credit invoices, so a tenant who
        // migrated mid-year saw an empty ageing page while the balance sheet
        // showed lakhs of debtors (audit P1).
        JournalVoucher::post(
            ['date' => '2026-01-01', 'narration' => 'Opening balance import'],
            [
                ['account_id' => $customer->account_id, 'debit' => 5000, 'credit' => 0],
                ['account_id' => $capital->id, 'debit' => 0, 'credit' => 5000],
            ],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/aged-receivables?as_of=2026-02-15")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.party', 'Migrated Customer')
            ->where('rows.0.opening', '5000.00')
            ->where('rows.0.current', '0.00')
            ->where('rows.0.days31_60', '0.00')
            ->where('rows.0.total', '5000.00')
            ->where('totals.opening', '5000.00')
            ->where('totals.total', '5000.00')
        );

    $tenant->delete();
});

test('the debtors list carries every customer with a ledger balance for the selected fiscal year', function () {
    $domain = 'debtors-list.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $owing = Customer::factory()->create(['name' => 'Owing Customer']);
        $settled = Customer::factory()->create(['name' => 'Settled Customer']);
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $owing->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 400, 'discount' => 0]],
            $admin,
        );

        // A cash sale never touches the customer ledger, so this customer must
        // not appear at all.
        Sale::post(
            ['customer_id' => $settled->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 900, 'discount' => 0]],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/debtors")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/Debtors')
            ->has('rows', 1)
            ->where('rows.0.name', 'Owing Customer')
            ->where('rows.0.balance', '400.00')
            ->where('total', '400.00')
        );

    $tenant->delete();
});

test('the creditors list carries every supplier with a ledger balance for the selected fiscal year', function () {
    $domain = 'creditors-list.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $owed = Supplier::factory()->create(['name' => 'Owed Supplier']);
        $settled = Supplier::factory()->create(['name' => 'Settled Supplier']);
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $owed->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 700, 'discount' => 0]],
            $admin,
        );

        Purchase::post(
            ['supplier_id' => $settled->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 900, 'discount' => 0]],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/creditors")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/Creditors')
            ->has('rows', 1)
            ->where('rows.0.name', 'Owed Supplier')
            ->where('rows.0.balance', '700.00')
            ->where('total', '700.00')
        );

    $tenant->delete();
});

test('the sales register can be narrowed to one payment mode', function () {
    $domain = 'sales-register-payment-mode.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 250, 'discount' => 0]],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/sales-register?from=2026-06-01&to=2026-06-30&payment_mode=credit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 1)
            ->where('sales.0.payment_mode', 'credit')
            ->where('sales.0.total', '250.00')
            ->where('paymentMode', 'credit')
            ->where('totals.total', '250.00')
        );

    $tenant->delete();
});

test('the purchase register can be narrowed to one payment mode', function () {
    $domain = 'purchase-register-payment-mode.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 250, 'discount' => 0]],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/purchase-register?from=2026-06-01&to=2026-06-30&payment_mode=cash")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('purchases', 1)
            ->where('purchases.0.payment_mode', 'cash')
            ->where('purchases.0.total', '100.00')
            ->where('paymentMode', 'cash')
            ->where('totals.total', '100.00')
        );

    $tenant->delete();
});

test('a date filter is inclusive of both end days', function () {
    $domain = 'sales-register-inclusive-range.tenant-test';
    $tenant = provisionReportTestTenant($domain);

    $tenant->run(function () {
        reportTestOpenFiscalYear();
        $admin = reportTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        // The first and the last day of the window: a `whereBetween` against a
        // datetime column silently drops the closing day, which is how a
        // month-end sale went missing from a filing.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-30', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 200, 'discount' => 0]],
            $admin,
        );
    });

    loginReportTestUser($domain);

    $this->get("http://{$domain}/reports/sales-register?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 2)
            ->where('totals.total', '300.00')
        );

    $tenant->delete();
});
