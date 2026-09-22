<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
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

function provisionTdsReportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginTdsReportTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

function tdsReportTestAdmin(): User
{
    return User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
}

function tdsReportTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('a sale with TDS withheld shows up on the report with the right net TDS amount', function () {
    $domain = 'tds-report-sale.tenant-test';
    $tenant = provisionTdsReportTestTenant($domain);

    $tenant->run(function () {
        tdsReportTestOpenFiscalYear();
        $admin = tdsReportTestAdmin();
        $customer = Customer::factory()->create(['name' => 'Sale Customer']);
        $tdsAccount = Account::factory()->create(['name' => 'TDS Receivable']);
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 10,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginTdsReportTestUser($domain);

    $this->get("http://{$domain}/reports/tds?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/TdsReport')
            ->has('sales', 1)
            ->where('sales.0.entry', 'invoice')
            ->where('sales.0.party', 'Sale Customer')
            ->where('sales.0.base_total', '100.00')
            // Sales carry no stored TDS rate: the amount is typed directly,
            // never derived from a rate x base calculation.
            ->where('sales.0.tds_rate', null)
            ->where('sales.0.tds_amount', '10.00')
            ->where('sales.0.tds_account', 'TDS Receivable')
            ->has('purchases', 0)
            ->where('salesTotal', '10.00')
            ->where('purchasesTotal', '0.00')
            ->where('grandTotal', '10.00')
        );

    $tenant->delete();
});

test('a purchase with TDS withheld shows up on the report with the right net TDS amount', function () {
    $domain = 'tds-report-purchase.tenant-test';
    $tenant = provisionTdsReportTestTenant($domain);

    $tenant->run(function () {
        tdsReportTestOpenFiscalYear();
        $admin = tdsReportTestAdmin();
        $supplier = Supplier::factory()->create(['name' => 'Purchase Supplier']);
        $tdsAccount = Account::factory()->create(['name' => 'TDS Payable']);
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_rate' => 15,
                'tds_amount' => 15,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginTdsReportTestUser($domain);

    $this->get("http://{$domain}/reports/tds?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('purchases', 1)
            ->where('purchases.0.entry', 'invoice')
            ->where('purchases.0.party', 'Purchase Supplier')
            ->where('purchases.0.base_total', '100.00')
            // Unlike a sale, a purchase stores the rate that was applied
            // (`purchases.tds_rate`), so the report can show it directly.
            ->where('purchases.0.tds_rate', '15.00')
            ->where('purchases.0.tds_amount', '15.00')
            ->where('purchases.0.tds_account', 'TDS Payable')
            ->has('sales', 0)
            ->where('salesTotal', '0.00')
            ->where('purchasesTotal', '15.00')
            ->where('grandTotal', '15.00')
        );

    $tenant->delete();
});

test('a posted credit note reverses TDS as its own dated row, not by rewriting the invoice', function () {
    $domain = 'tds-report-partial-sales-return.tenant-test';
    $tenant = provisionTdsReportTestTenant($domain);

    $tenant->run(function () {
        tdsReportTestOpenFiscalYear();
        $admin = tdsReportTestAdmin();
        $customer = Customer::factory()->create();
        $tdsAccount = Account::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        // Sale total: 2 x 100 = 200, TDS withheld = 20 (10%).
        $sale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 20,
            ],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // Return 1 of 2 units. The credit note stores the TDS its own voucher
        // reversed (C6); this report reads that column rather than
        // re-deriving `tds x returned / total`, which drifts by a paisa once
        // a note has several lines and a header discount.
        SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-05'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );
    });

    loginTdsReportTestUser($domain);

    $this->get("http://{$domain}/reports/tds?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 2)
            ->where('sales.0.entry', 'invoice')
            ->where('sales.0.tds_amount', '20.00')
            ->where('sales.1.entry', 'credit_note')
            ->where('sales.1.tds_amount', '-10.00')
            ->where('salesTotal', '10.00')
            ->where('grandTotal', '10.00')
        );

    $tenant->delete();
});

test('a posted debit note reverses TDS as its own dated row, not by rewriting the bill', function () {
    $domain = 'tds-report-partial-purchase-return.tenant-test';
    $tenant = provisionTdsReportTestTenant($domain);

    $tenant->run(function () {
        tdsReportTestOpenFiscalYear();
        $admin = tdsReportTestAdmin();
        $supplier = Supplier::factory()->create();
        $tdsAccount = Account::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        // Purchase total: 4 x 100 = 400, TDS withheld = 40 (10%).
        $purchase = Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 40,
            ],
            [['item_id' => $item->id, 'quantity' => 4, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // Return 1 of 4 units. The debit note stores the TDS its own voucher
        // reversed (C6) and this report reads that column directly.
        PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $purchase->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );
    });

    loginTdsReportTestUser($domain);

    $this->get("http://{$domain}/reports/tds?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('purchases', 2)
            ->where('purchases.0.entry', 'invoice')
            ->where('purchases.0.tds_amount', '40.00')
            ->where('purchases.1.entry', 'debit_note')
            ->where('purchases.1.tds_amount', '-10.00')
            ->where('purchasesTotal', '30.00')
            ->where('grandTotal', '30.00')
        );

    $tenant->delete();
});

test('a cancelled sale or purchase stays in its own month and reverses in the month it was cancelled', function () {
    $domain = 'tds-report-cancelled.tenant-test';
    $tenant = provisionTdsReportTestTenant($domain);

    // This test used to assert a cancelled document vanished from the report
    // entirely, which rewrote a month the tenant may already have filed and
    // disagreed with the ledger (the TDS voucher really did post in June and
    // the Reversal really did post in July). C5, audit P0-20.
    Carbon::setTestNow('2026-07-15 10:00:00');

    $tenant->run(function () {
        tdsReportTestOpenFiscalYear();
        $admin = tdsReportTestAdmin();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $tdsAccount = Account::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $cancelledSale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 10,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        $cancelledSale->cancel($admin, 'Recorded in error');

        $cancelledPurchase = Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 15,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        $cancelledPurchase->cancel($admin, 'Recorded in error');
    });

    loginTdsReportTestUser($domain);

    // June: both documents were issued and withheld against here.
    $this->get("http://{$domain}/reports/tds?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 1)
            ->where('sales.0.entry', 'invoice')
            ->where('sales.0.tds_amount', '10.00')
            ->has('purchases', 1)
            ->where('purchases.0.entry', 'invoice')
            ->where('purchases.0.tds_amount', '15.00')
            ->where('salesTotal', '10.00')
            ->where('purchasesTotal', '15.00')
            ->where('grandTotal', '25.00')
        );

    // July: the two cancellations, and nothing else.
    $this->get("http://{$domain}/reports/tds?from=2026-07-01&to=2026-07-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 1)
            ->where('sales.0.entry', 'cancelled')
            ->where('sales.0.tds_amount', '-10.00')
            ->has('purchases', 1)
            ->where('purchases.0.entry', 'cancelled')
            ->where('purchases.0.tds_amount', '-15.00')
            ->where('salesTotal', '-10.00')
            ->where('purchasesTotal', '-15.00')
            ->where('grandTotal', '-25.00')
        );

    Carbon::setTestNow();

    $tenant->delete();
});

test('a credit note nets against the period its own date falls in, not the invoice period', function () {
    $domain = 'tds-report-return-own-period.tenant-test';
    $tenant = provisionTdsReportTestTenant($domain);

    $tenant->run(function () {
        tdsReportTestOpenFiscalYear();
        $admin = tdsReportTestAdmin();
        $customer = Customer::factory()->create();
        $tdsAccount = Account::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        // June invoice: 2 x 100 = 200, TDS 20.
        $sale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 20,
            ],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // Credit note raised in July, reversing half the TDS.
        SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-07-05'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );
    });

    loginTdsReportTestUser($domain);

    // June is filed with the full 20 and must stay that way.
    $this->get("http://{$domain}/reports/tds?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 1)
            ->where('sales.0.entry', 'invoice')
            ->where('sales.0.tds_amount', '20.00')
            ->where('salesTotal', '20.00')
        );

    // July carries the reversal on its own.
    $this->get("http://{$domain}/reports/tds?from=2026-07-01&to=2026-07-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 1)
            ->where('sales.0.entry', 'credit_note')
            ->where('sales.0.tds_amount', '-10.00')
            ->where('salesTotal', '-10.00')
            ->where('grandTotal', '-10.00')
        );

    $tenant->delete();
});

test('the combined grand total sums TDS on sales and TDS on purchases across multiple transactions', function () {
    $domain = 'tds-report-grand-total.tenant-test';
    $tenant = provisionTdsReportTestTenant($domain);

    $tenant->run(function () {
        tdsReportTestOpenFiscalYear();
        $admin = tdsReportTestAdmin();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $tdsAccount = Account::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 10,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-02',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 5,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 50, 'discount' => 0]],
            $admin,
        );

        Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 20,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 200, 'discount' => 0]],
            $admin,
        );
    });

    loginTdsReportTestUser($domain);

    // Sales TDS: 10 + 5 = 15. Purchases TDS: 20. Grand total: 35.
    $this->get("http://{$domain}/reports/tds?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 2)
            ->has('purchases', 1)
            ->where('salesTotal', '15.00')
            ->where('purchasesTotal', '20.00')
            ->where('grandTotal', '35.00')
        );

    $tenant->delete();
});

test('a sale or purchase without TDS withheld does not appear on the report', function () {
    $domain = 'tds-report-no-tds.tenant-test';
    $tenant = provisionTdsReportTestTenant($domain);

    $tenant->run(function () {
        tdsReportTestOpenFiscalYear();
        $admin = tdsReportTestAdmin();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginTdsReportTestUser($domain);

    $this->get("http://{$domain}/reports/tds?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 0)
            ->has('purchases', 0)
            ->where('grandTotal', '0.00')
        );

    $tenant->delete();
});

test('the TDS report can be narrowed to a single store', function () {
    $domain = 'tds-report-store-filter.tenant-test';
    $tenant = provisionTdsReportTestTenant($domain);

    $storeAId = null;
    $tenant->run(function () use (&$storeAId) {
        tdsReportTestOpenFiscalYear();
        $admin = tdsReportTestAdmin();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $tdsAccount = Account::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $storeA = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $storeB = Store::factory()->create(['name' => 'Branch Store']);

        Sale::post(
            [
                'customer_id' => $customer->id,
                'store_id' => $storeA->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 10,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        Purchase::post(
            [
                'supplier_id' => $supplier->id,
                'store_id' => $storeB->id,
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 15,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $storeAId = $storeA->id;
    });

    loginTdsReportTestUser($domain);

    $this->get("http://{$domain}/reports/tds?from=2026-06-01&to=2026-06-30&store_id={$storeAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 1)
            ->where('sales.0.tds_amount', '10.00')
            ->has('purchases', 0)
            ->where('salesTotal', '10.00')
            ->where('purchasesTotal', '0.00')
            ->where('grandTotal', '10.00')
        );

    $tenant->delete();
});
