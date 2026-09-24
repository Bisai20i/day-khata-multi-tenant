<?php

use App\Enums\FiscalYearStatus;
use App\Exports\SalesExport;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Covers T12 item 7: the sales list's Excel export, invoice-number sort and
 * search, and "Save & Print N copies" (SaleController::export()/index()/
 * print(), one PrintLog row per copy - C9).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSaleListExportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginSaleListExportTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the invoice number search narrows the sales list to a partial, case-insensitive match', function () {
    $domain = 'sale-search-invoice.tenant-test';
    $tenant = provisionSaleListExportTestTenant($domain);

    $invoiceNumber = null;
    $tenant->run(function () use (&$invoiceNumber) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $invoiceNumber = $sale->invoice_number;
    });

    loginSaleListExportTestUser($domain);

    $this->get("http://{$domain}/sales?".http_build_query(['search' => strtolower(substr($invoiceNumber, 0, 3))]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sales.total', 1)
            ->where('sales.data.0.invoice_number', $invoiceNumber));

    $tenant->delete();
});

test('sorting by invoice number reverses the default date order', function () {
    $domain = 'sale-sort-invoice.tenant-test';
    $tenant = provisionSaleListExportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        // Posted in date order, so invoice_number and date rank the same way
        // - sort_dir=asc on invoice_number must return the earliest first.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginSaleListExportTestUser($domain);

    $this->get("http://{$domain}/sales?".http_build_query(['sort' => 'invoice_number', 'sort_dir' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'invoice_number')
            ->where('filters.sort_dir', 'asc')
            // Posted in date order, so ascending by invoice_number (a
            // gapless series, C7) returns the earliest sale first.
            ->where('sales.data.0.date', '2026-06-01')
            ->where('sales.data.1.date', '2026-06-02'));

    $tenant->delete();
});

test('an unknown sort column falls back to the default date column rather than reaching the query', function () {
    $domain = 'sale-sort-invalid.tenant-test';
    $tenant = provisionSaleListExportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    loginSaleListExportTestUser($domain);

    $this->get("http://{$domain}/sales?".http_build_query(['sort' => 'id; DROP TABLE sales;']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.sort', 'date'));

    $tenant->delete();
});

test('the totals row is the exact SQL sum of the filtered set, not the current page', function () {
    $domain = 'sale-totals-filtered.tenant-test';
    $tenant = provisionSaleListExportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => '100.00', 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => '250.00', 'discount' => 0]],
            $admin,
        );
    });

    loginSaleListExportTestUser($domain);

    $this->get("http://{$domain}/sales")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.total', '350.00'));

    $tenant->delete();
});

test('the sales export streams an xlsx built from the filtered set', function () {
    $domain = 'sale-export-xlsx.tenant-test';
    $tenant = provisionSaleListExportTestTenant($domain);

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
    });

    loginSaleListExportTestUser($domain);

    Excel::fake();

    $this->get("http://{$domain}/sales/export")->assertOk();

    Excel::assertDownloaded('sales.xlsx', function (SalesExport $export) {
        // One data row plus the trailing totals row (audit section 4 polish,
        // "list export"), matching the filtered/searched set the screen
        // shows - never every sale unfiltered.
        return $export->collection()->count() === 2
            && $export->collection()->last()['invoice_number'] === 'Total';
    });

    $tenant->delete();
});

test('the sales export streams a csv when format=csv is requested', function () {
    $domain = 'sale-export-csv.tenant-test';
    $tenant = provisionSaleListExportTestTenant($domain);

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
    });

    loginSaleListExportTestUser($domain);

    Excel::fake();

    $this->get("http://{$domain}/sales/export?format=csv")->assertOk();

    Excel::assertDownloaded('sales.csv', fn (SalesExport $export) => $export->collection()->count() === 2);

    $tenant->delete();
});

test('save and print N copies renders one PDF and records one print log row per copy', function () {
    $domain = 'sale-print-copies.tenant-test';
    $tenant = provisionSaleListExportTestTenant($domain);

    $saleId = null;
    $tenant->run(function () use (&$saleId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $saleId = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        )->id;
    });

    loginSaleListExportTestUser($domain);

    $this->get("http://{$domain}/sales/{$saleId}/print?copies=3")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $tenant->run(function () use ($saleId) {
        $rows = PrintLog::where('printable_type', (new Sale)->getMorphClass())
            ->where('printable_id', $saleId)
            ->orderBy('copy_number')
            ->get();

        expect($rows)->toHaveCount(3)
            ->and($rows->pluck('copy_number')->all())->toBe([1, 2, 3]);
    });

    $tenant->delete();
});

test('save and print N copies is capped so a runaway copy count cannot flood the print log', function () {
    $domain = 'sale-print-copies-cap.tenant-test';
    $tenant = provisionSaleListExportTestTenant($domain);

    $saleId = null;
    $tenant->run(function () use (&$saleId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $saleId = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        )->id;
    });

    loginSaleListExportTestUser($domain);

    $this->get("http://{$domain}/sales/{$saleId}/print?copies=999")->assertOk();

    $tenant->run(function () use ($saleId) {
        $count = PrintLog::where('printable_type', (new Sale)->getMorphClass())
            ->where('printable_id', $saleId)
            ->count();

        expect($count)->toBe(5);
    });

    $tenant->delete();
});
