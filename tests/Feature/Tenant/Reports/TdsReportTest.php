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
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
            ->where('sales.0.party', 'Sale Customer')
            ->where('sales.0.total', 100)
            ->where('sales.0.net_tds_amount', 10)
            ->where('sales.0.tds_account', 'TDS Receivable')
            ->has('purchases', 0)
            ->where('salesTotal', 10)
            ->where('purchasesTotal', 0)
            ->where('grandTotal', 10)
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
            ->where('purchases.0.party', 'Purchase Supplier')
            ->where('purchases.0.total', 100)
            ->where('purchases.0.net_tds_amount', 15)
            ->where('purchases.0.tds_account', 'TDS Payable')
            ->has('sales', 0)
            ->where('salesTotal', 0)
            ->where('purchasesTotal', 15)
            ->where('grandTotal', 15)
        );

    $tenant->delete();
});

test('a partial sales return reduces the net TDS shown by the proportional share reversed', function () {
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

        // Return 1 of 2 units - return total 100, tdsShare = round(20 * (100/200), 2) = 10.
        // Net TDS remaining on the sale: 20 - 10 = 10.
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
            ->has('sales', 1)
            ->where('sales.0.net_tds_amount', 10)
            ->where('salesTotal', 10)
            ->where('grandTotal', 10)
        );

    $tenant->delete();
});

test('a partial purchase return reduces the net TDS shown by the proportional share reversed', function () {
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

        // Return 1 of 4 units - return total 100, tdsShare = round(40 * (100/400), 2) = 10.
        // Net TDS remaining on the purchase: 40 - 10 = 30.
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
            ->has('purchases', 1)
            ->where('purchases.0.net_tds_amount', 30)
            ->where('purchasesTotal', 30)
            ->where('grandTotal', 30)
        );

    $tenant->delete();
});

test('a cancelled sale or purchase is excluded from the TDS report entirely', function () {
    $domain = 'tds-report-cancelled.tenant-test';
    $tenant = provisionTdsReportTestTenant($domain);

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

    $this->get("http://{$domain}/reports/tds?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 0)
            ->has('purchases', 0)
            ->where('salesTotal', 0)
            ->where('purchasesTotal', 0)
            ->where('grandTotal', 0)
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
            ->where('salesTotal', 15)
            ->where('purchasesTotal', 20)
            ->where('grandTotal', 35)
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
            ->where('grandTotal', 0)
        );

    $tenant->delete();
});
