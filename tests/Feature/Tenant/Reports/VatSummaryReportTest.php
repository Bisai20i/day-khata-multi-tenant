<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\CapitalPurchase;
use App\Models\CapitalSale;
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
            ->where('outputVat.gross', '130.00')
            ->where('outputVat.returns', '0.00')
            ->where('outputVat.net', '130.00')
            ->where('inputVat.gross', '26.00')
            ->where('inputVat.returns', '0.00')
            ->where('inputVat.net', '26.00')
            ->where('netVatPayable', '104.00')
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
            ->where('outputVat.net', '130.00')
            ->where('inputVat.net', '13.00')
            ->where('netVatPayable', '117.00')
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
            ->where('outputVat.net', '13.00')
            ->where('inputVat.net', '130.00')
            ->where('netVatPayable', '-117.00')
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
            ->where('outputVat.gross', '130.00')
            ->where('outputVat.returns', '65.00')
            ->where('outputVat.net', '65.00')
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
            ->where('inputVat.gross', '26.00')
            ->where('inputVat.returns', '13.00')
            ->where('inputVat.net', '13.00')
        );

    $tenant->delete();
});

test('a cancelled sales return stays in the month it was issued and is added back in the month it was cancelled', function () {
    $domain = 'vat-summary-cancelled-return.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    // This test used to assert that a cancelled credit note vanished from
    // June entirely. That silently rewrote a month the tenant may already
    // have filed, and it disagreed with the ledger: the credit note's own
    // voucher really did debit LIA20 on 2026-06-10, and the cancellation
    // really did credit it back on 2026-08-20. The report now says the same
    // thing the ledger says (C5, audit P0-20).
    Carbon::setTestNow('2026-08-20 10:00:00');

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

        $return->cancel($admin, 'Recorded in error');
    });

    loginVatSummaryTestUser($domain);

    // June is untouched by the August cancellation.
    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.gross', '130.00')
            ->where('outputVat.returns', '65.00')
            ->where('outputVat.net', '65.00')
            ->where('reconciliation.difference', '0.00')
        );

    // August carries the add-back, and nothing else.
    $this->get("http://{$domain}/reports/vat-summary?from=2026-08-01&to=2026-08-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.gross', '0.00')
            ->where('outputVat.returns', '-65.00')
            ->where('outputVat.net', '65.00')
            ->where('reconciliation.difference', '0.00')
        );

    Carbon::setTestNow();

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
            ->where('outputVat.gross', '130.00')
            ->where('outputVat.returns', '0.00')
            ->where('outputVat.net', '130.00')
        );

    // July: no sale falls in this period, but the return does, so it nets
    // against July's own output VAT liability.
    $this->get("http://{$domain}/reports/vat-summary?from=2026-07-01&to=2026-07-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.gross', '0.00')
            ->where('outputVat.returns', '65.00')
            ->where('outputVat.net', '-65.00')
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
            ->where('outputVat.gross', '0.00')
            ->where('outputVat.returns', '0.00')
            ->where('outputVat.net', '0.00')
            ->where('inputVat.gross', '0.00')
            ->where('inputVat.returns', '0.00')
            ->where('inputVat.net', '0.00')
            ->where('netVatPayable', '0.00')
        );

    $tenant->delete();
});

test('the VAT summary can be narrowed to a single store, netting only that store\'s sales, purchases, and returns', function () {
    $domain = 'vat-summary-store-filter.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    $storeAId = null;
    $tenant->run(function () use (&$storeAId) {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
        $storeA = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $storeB = Store::factory()->create(['name' => 'Branch Store']);

        // Store A: 2 units @ 500 = 1000 taxable => 130 VAT, then a partial
        // return of 1 unit reverses half (500 taxable => 65 VAT).
        $saleA = Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $storeA->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 500, 'discount' => 0]],
            $admin,
        );
        SalesReturn::post(
            ['sale_id' => $saleA->id, 'store_id' => $storeA->id, 'date' => '2026-06-10'],
            [['sale_line_id' => $saleA->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );

        Purchase::post(
            ['supplier_id' => $supplier->id, 'store_id' => $storeA->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // Store B: a much larger sale and purchase that must not leak into
        // Store A's totals below.
        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $storeB->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 5000, 'discount' => 0]],
            $admin,
        );
        Purchase::post(
            ['supplier_id' => $supplier->id, 'store_id' => $storeB->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 2000, 'discount' => 0]],
            $admin,
        );

        $storeAId = $storeA->id;
    });

    loginVatSummaryTestUser($domain);

    // Store A only: output gross 130, returns 65, net 65; input gross 13, net 13.
    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30&store_id={$storeAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.gross', '130.00')
            ->where('outputVat.returns', '65.00')
            ->where('outputVat.net', '65.00')
            ->where('inputVat.gross', '13.00')
            ->where('inputVat.net', '13.00')
        );

    $tenant->delete();
});

test('the VAT summary ties exactly to the ledger movement with a capital purchase, a cancelled sale and a posted return', function () {
    $domain = 'vat-summary-ledger-tie.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    // The whole point of the 2026-09-11 audit finding P0-20: whatever the
    // report says for a period must equal what really moved on Output VAT
    // (LIA20) and Input VAT (ASA23) in that period. Capital documents post
    // there too, and a cancellation lands in the month it was cancelled in,
    // never back in the month that was already filed.
    Carbon::setTestNow('2026-07-20 10:00:00');

    $tenant->run(function () {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();
        $account = Account::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // June: a kept sale (1000 taxable => 130 VAT).
        $keptSale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 500, 'discount' => 0]],
            $admin,
        );

        // June: a sale that gets cancelled in July (400 taxable => 52 VAT).
        $doomedSale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 400, 'discount' => 0]],
            $admin,
        );

        // June: a credit note against the kept sale (500 taxable => 65 VAT).
        SalesReturn::post(
            ['sale_id' => $keptSale->id, 'date' => '2026-06-10'],
            [['sale_line_id' => $keptSale->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );

        // June: an ordinary purchase (200 taxable => 26 VAT).
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-05', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // June: a capital purchase, which claims input VAT on ASA23 exactly
        // like an ordinary bill (1000 taxable => 130 VAT).
        CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-07', 'payment_mode' => 'cash', 'bill_number' => 'SUP-88'],
            [['account_id' => $account->id, 'amount' => '1000', 'vatable' => true]],
            $admin,
        );

        $doomedSale->cancel($admin, 'Issued to the wrong customer');
    });

    loginVatSummaryTestUser($domain);

    // June: output 130 + 52 issued, less the 65 credit note. Input 26 + 130.
    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.gross', '182.00')
            ->where('outputVat.capital', '0.00')
            ->where('outputVat.cancelled', '0.00')
            ->where('outputVat.returns', '65.00')
            ->where('outputVat.net', '117.00')
            ->where('inputVat.gross', '26.00')
            ->where('inputVat.capital', '130.00')
            ->where('inputVat.cancelled', '0.00')
            ->where('inputVat.returns', '0.00')
            ->where('inputVat.net', '156.00')
            ->where('netVatPayable', '-39.00')
            ->where('reconciliation.applicable', true)
            ->where('reconciliation.ledgerOutputVat', '117.00')
            ->where('reconciliation.ledgerInputVat', '156.00')
            ->where('reconciliation.difference', '0.00')
        );

    // July carries only the cancellation, and it ties to the ledger there too.
    $this->get("http://{$domain}/reports/vat-summary?from=2026-07-01&to=2026-07-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.gross', '0.00')
            ->where('outputVat.cancelled', '52.00')
            ->where('outputVat.net', '-52.00')
            ->where('inputVat.net', '0.00')
            ->where('netVatPayable', '-52.00')
            ->where('reconciliation.ledgerOutputVat', '-52.00')
            ->where('reconciliation.difference', '0.00')
        );

    Carbon::setTestNow();

    $tenant->delete();
});

test('a capital sale contributes its VAT to output VAT in its own column', function () {
    $domain = 'vat-summary-capital-sale.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    $tenant->run(function () {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $account = Account::factory()->create();

        // 2000 vatable => 260 output VAT credited to LIA20.
        CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => '2000', 'vatable' => true]],
            $admin,
        );
    });

    loginVatSummaryTestUser($domain);

    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.gross', '0.00')
            ->where('outputVat.capital', '260.00')
            ->where('outputVat.net', '260.00')
            ->where('reconciliation.ledgerOutputVat', '260.00')
            ->where('reconciliation.difference', '0.00')
        );

    $tenant->delete();
});

test('a fixed-asset line inside an ordinary purchase surfaces its own input VAT without changing inputVat.gross, capital or net', function () {
    $domain = 'vat-summary-fixed-asset-line.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    $tenant->run(function () {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $supplier = Supplier::factory()->create();

        $ordinaryItem = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
        $fixedAssetsGroup = AccountGroup::where('name', 'Fixed Assets')->firstOrFail();
        $assetAccount = $fixedAssetsGroup->accounts()->create(['name' => 'Office Chair Asset']);
        $assetItem = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false, 'account_id' => $assetAccount->id]);

        // 100 taxable => 13 VAT (ordinary) + 200 taxable => 26 VAT (asset
        // line) = 300 taxable, 39 VAT total, same 2026-09-11 audit as T15-3.
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [
                ['item_id' => $ordinaryItem->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0],
                ['item_id' => $assetItem->id, 'quantity' => 1, 'rate' => 200, 'discount' => 0],
            ],
            $admin,
        );
    });

    loginVatSummaryTestUser($domain);

    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // The new, purely informational breakdown.
            ->where('inputVat.fixedAssetVat', '26.00')
            // Untouched: gross still carries the WHOLE purchase's VAT, capital
            // stays 0 (this is not a CapitalPurchase document), and net/the
            // ledger reconciliation still tie out exactly as before.
            ->where('inputVat.gross', '39.00')
            ->where('inputVat.capital', '0.00')
            ->where('inputVat.net', '39.00')
            ->where('netVatPayable', '-39.00')
            ->where('reconciliation.difference', '0.00')
        );

    $tenant->delete();
});

test('a rejected return request has no VAT effect at all', function () {
    $domain = 'vat-summary-rejected-return.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    $tenant->run(function () {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 500, 'discount' => 0]],
            $admin,
        );

        // A request never posted a voucher, so nothing may net out of the
        // filing period (C6). Rejecting it does not change that.
        $request = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-10'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );

        $request->reject('Goods were not actually returned');
    });

    loginVatSummaryTestUser($domain);

    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.gross', '130.00')
            ->where('outputVat.returns', '0.00')
            ->where('outputVat.net', '130.00')
            ->where('reconciliation.difference', '0.00')
        );

    $tenant->delete();
});

test('a pending return request has no VAT effect until it is approved', function () {
    $domain = 'vat-summary-pending-return.tenant-test';
    $tenant = provisionVatSummaryTestTenant($domain);

    $tenant->run(function () {
        vatSummaryTestOpenFiscalYear();
        $admin = vatSummaryTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 500, 'discount' => 0]],
            $admin,
        );

        SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-10'],
            [['sale_line_id' => $sale->lines()->first()->id, 'quantity' => 1]],
            $admin,
        );
    });

    loginVatSummaryTestUser($domain);

    $this->get("http://{$domain}/reports/vat-summary?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('outputVat.returns', '0.00')
            ->where('outputVat.net', '130.00')
            ->where('reconciliation.difference', '0.00')
        );

    $tenant->delete();
});
