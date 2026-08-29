<?php

use App\Enums\FiscalYearStatus;
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

function provisionVatSummaryTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginVatSummaryTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

function vatSummaryTestAdmin(): User
{
    return User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
}

function vatSummaryTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('a posted sale contributes its VAT to output VAT and a posted purchase contributes its VAT to input VAT', function () {
    $domain = 'vat-summary-basic.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    $tenant->run(function () {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // Taxable 1000 @ 13% = 130 VAT.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000, 'discount' => 0]],
            $admin,
        );

        // Taxable 200 @ 13% = 26 VAT.
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 200, 'discount' => 0]],
            $admin,
        );
    });

    loginVatSummaryTestUser($domain);

    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/VatSummary')
            ->where('outputVat.gross', 130)
            ->where('outputVat.returns', 0)
            ->where('outputVat.net', 130)
            ->where('inputVat.gross', 26)
            ->where('inputVat.returns', 0)
            ->where('inputVat.net', 26)
            ->where('netVatPayable', 104)
        );

    $tenant->delete();
});

test('net VAT payable is positive (owed) when output VAT exceeds input VAT', function () {
    $domain = 'vat-summary-payable.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    $tenant->run(function () {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // 1000 taxable => 130 output VAT.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000, 'discount' => 0]],
            $admin,
        );

        // 100 taxable => 13 input VAT.
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginVatSummaryTestUser($domain);

    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.net', 130)
            ->where('inputVat.net', 13)
            ->where('netVatPayable', 117)
        );

    $tenant->delete();
});

test('net VAT payable is negative (refundable) when input VAT exceeds output VAT', function () {
    $domain = 'vat-summary-refundable.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    $tenant->run(function () {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // 100 taxable => 13 output VAT.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // 1000 taxable => 130 input VAT.
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000, 'discount' => 0]],
            $admin,
        );
    });

    loginVatSummaryTestUser($domain);

    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.net', 13)
            ->where('inputVat.net', 130)
            ->where('netVatPayable', -117)
        );

    $tenant->delete();
});

test('a sales return in the period reduces net output VAT by exactly its own VAT amount', function () {
    $domain = 'vat-summary-sales-return.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    $tenant->run(function () {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // 2 units @ 500 = 1000 taxable => 130 VAT.
        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 500, 'discount' => 0]],
            $admin,
        );

        // Returning 1 of 2 units reverses half: 500 taxable => 65 VAT.
        SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-10'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );
    });

    loginVatSummaryTestUser($domain);

    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.gross', 130)
            ->where('outputVat.returns', 65)
            ->where('outputVat.net', 65)
        );

    $tenant->delete();
});

test('a purchase return in the period reduces net input VAT by exactly its own VAT amount', function () {
    $domain = 'vat-summary-purchase-return.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    $tenant->run(function () {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // 2 units @ 100 = 200 taxable => 26 VAT.
        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // Returning 1 of 2 units reverses half: 100 taxable => 13 VAT.
        PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-10'],
            [['purchase_line_id' => $purchase->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );
    });

    loginVatSummaryTestUser($domain);

    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('inputVat.gross', 26)
            ->where('inputVat.returns', 13)
            ->where('inputVat.net', 13)
        );

    $tenant->delete();
});

test('a cancelled sales return does not reduce output VAT', function () {
    $domain = 'vat-summary-cancelled-return.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    $tenant->run(function () {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // 2 units @ 500 = 1000 taxable => 130 VAT.
        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 500, 'discount' => 0]],
            $admin,
        );

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-10'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );

        // The return's own reversal was itself un-done - its vat_amount
        // must not be netted out a second time.
        $return->cancel($admin, 'Recorded in error');
    });

    loginVatSummaryTestUser($domain);

    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.gross', 130)
            ->where('outputVat.returns', 0)
            ->where('outputVat.net', 130)
        );

    $tenant->delete();
});

test('a sales return nets against the period its own date falls in, not the original sale\'s period', function () {
    $domain = 'vat-summary-return-own-period.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    $saleId = null;
    $tenant->run(function () use (&$saleId) {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // Sale posted in June (2 units @ 500 = 1000 taxable => 130 VAT).
        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-15', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 500, 'discount' => 0]],
            $admin,
        );
        $saleId = $sale->id;

        // Return posted in July (1 of 2 units => 500 taxable => 65 VAT).
        SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-07-05'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );
    });

    loginVatSummaryTestUser($domain);

    // June: the sale's gross VAT counts, but the return (dated in July)
    // does not reduce it.
    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.gross', 130)
            ->where('outputVat.returns', 0)
            ->where('outputVat.net', 130)
        );

    // July: no sale falls in this period, but the return does, so it nets
    // against July's own output VAT liability.
    $this->get("http://{$domain}/reports/vat-summary?from=2026-07-01&to=2026-07-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.gross', 0)
            ->where('outputVat.returns', 65)
            ->where('outputVat.net', -65)
        );

    $tenant->delete();
});

test('a sale, purchase, and return outside the date range are all excluded', function () {
    $domain = 'vat-summary-daterange.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    $tenant->run(function () {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-03-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000, 'discount' => 0]],
            $admin,
        );
        SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-03-05'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-03-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 500, 'discount' => 0]],
            $admin,
        );
    });

    loginVatSummaryTestUser($domain);

    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.gross', 0)
            ->where('outputVat.returns', 0)
            ->where('outputVat.net', 0)
            ->where('inputVat.gross', 0)
            ->where('inputVat.returns', 0)
            ->where('inputVat.net', 0)
            ->where('netVatPayable', 0)
        );

    $tenant->delete();
});
