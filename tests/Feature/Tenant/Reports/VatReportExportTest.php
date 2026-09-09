<?php

use App\Enums\FiscalYearStatus;
use App\Exports\VatSummaryExport;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionVatExportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginVatExportTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

function vatExportTestAdmin(): User
{
    return User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
}

function vatExportTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

/**
 * Seeds one posted sale and one posted purchase in June 2026, both
 * vatable, so all three export endpoints below have real rows/figures to
 * stream rather than an empty sheet.
 */
function seedVatExportTestData(): void
{
    vatExportTestOpenFiscalYear();
    $admin = vatExportTestAdmin();
    $customer = Customer::factory()->create();
    $supplier = Supplier::factory()->create();
    $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

    Sale::post(
        ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
        [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000, 'discount' => 0]],
        $admin,
    );

    Purchase::post(
        ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
        [['item_id' => $item->id, 'quantity' => 1, 'rate' => 200, 'discount' => 0]],
        $admin,
    );
}

test('the VAT summary export route streams a downloadable xlsx file with the expected headers', function () {
    $domain = 'vat-summary-export-http.tenant-test';
    $tenant = provisionVatExportTestTenant($domain);

    $tenant->run(function () {
        seedVatExportTestData();
    });

    loginVatExportTestUser($domain);

    $response = $this->get("http://{$domain}/reports/vat-summary/export?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('vat-summary-2026-06-01-to-2026-06-30.xlsx');

    $tenant->delete();
});

test('the sales VAT book export route streams a downloadable xlsx file with the expected headers', function () {
    $domain = 'sales-vat-book-export-http.tenant-test';
    $tenant = provisionVatExportTestTenant($domain);

    $tenant->run(function () {
        seedVatExportTestData();
    });

    loginVatExportTestUser($domain);

    $response = $this->get("http://{$domain}/reports/sales-vat-book/export?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('sales-vat-book-2026-06-01-to-2026-06-30.xlsx');

    $tenant->delete();
});

test('the purchase VAT book export route streams a downloadable xlsx file with the expected headers', function () {
    $domain = 'purchase-vat-book-export-http.tenant-test';
    $tenant = provisionVatExportTestTenant($domain);

    $tenant->run(function () {
        seedVatExportTestData();
    });

    loginVatExportTestUser($domain);

    $response = $this->get("http://{$domain}/reports/purchase-vat-book/export?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('purchase-vat-book-2026-06-01-to-2026-06-30.xlsx');

    $tenant->delete();
});

test('all three VAT export routes are rejected for an unauthenticated request', function () {
    $domain = 'vat-export-guest.tenant-test';
    $tenant = provisionVatExportTestTenant($domain);

    $tenant->run(function () {
        seedVatExportTestData();
    });

    $this->get("http://{$domain}/reports/vat-summary/export")->assertRedirect("http://{$domain}/login");
    $this->get("http://{$domain}/reports/sales-vat-book/export")->assertRedirect("http://{$domain}/login");
    $this->get("http://{$domain}/reports/purchase-vat-book/export")->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});

test('the VAT summary export honors the from/to/store_id filters, matching what the on-screen report would show', function () {
    $domain = 'vat-summary-export-filters.tenant-test';
    $tenant = provisionVatExportTestTenant($domain);

    $storeAId = null;
    $tenant->run(function () use (&$storeAId) {
        vatExportTestOpenFiscalYear();
        $admin = vatExportTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
        $storeA = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $storeB = Store::factory()->create(['name' => 'Branch Store']);

        // Store A: 1000 taxable => 130 VAT, inside the exported period.
        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $storeA->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000, 'discount' => 0]],
            $admin,
        );

        // Store B: a much larger sale that must not leak into Store A's
        // exported figures, and a sale outside the date range that must
        // not leak in either.
        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $storeB->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 5000, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $storeA->id, 'invoice_type' => 'full', 'date' => '2026-03-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 9000, 'discount' => 0]],
            $admin,
        );

        $storeAId = $storeA->id;
    });

    loginVatExportTestUser($domain);

    Excel::fake();

    $this->get("http://{$domain}/reports/vat-summary/export?from=2026-06-01&to=2026-06-30&store_id={$storeAId}")
        ->assertOk();

    Excel::assertDownloaded(
        'vat-summary-2026-06-01-to-2026-06-30.xlsx',
        function (VatSummaryExport $export) {
            // Reflect into the readonly constructor-injected property - the
            // export has no public accessor of its own, only the
            // WithMapping-shaped rows Excel itself reads.
            $property = new ReflectionProperty($export, 'outputVat');
            $property->setAccessible(true);
            $outputVat = $property->getValue($export);

            // Only Store A's 130 VAT should show up - Store B's 5000@13%=650
            // and the out-of-range 9000@13%=1170 sale must both be excluded.
            return $outputVat['gross'] === 130.0;
        },
    );

    $tenant->delete();
});
