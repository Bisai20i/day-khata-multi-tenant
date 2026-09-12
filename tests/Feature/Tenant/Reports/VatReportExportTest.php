<?php

use App\Enums\FiscalYearStatus;
use App\Exports\PurchaseVatBookExport;
use App\Exports\SalesVatBookExport;
use App\Exports\VatSummaryExport;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\CapitalSale;
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
use Illuminate\Support\Carbon;
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

            // Each side of the return is now an array keyed
            // gross/capital/cancelled/returns/net, holding exact 2-decimal
            // strings rather than floats.
            expect($outputVat)->toHaveKeys(['gross', 'capital', 'cancelled', 'returns', 'net']);

            // A store filter has no ledger counterpart, so the three
            // reconciliation rows are deliberately absent here and the sheet
            // stops at Net VAT Payable.
            expect($export->collection()->pluck('metric')->all())->toBe([
                'Output VAT on sales',
                'Output VAT on capital sales',
                'Less: cancelled sales VAT',
                'Less: credit note VAT',
                'Net Output VAT',
                'Input VAT on purchases',
                'Input VAT on capital purchases',
                'Less: cancelled purchases VAT',
                'Less: debit note VAT',
                'Net Input VAT',
                'Net VAT Payable',
            ]);

            // Only Store A's 130 VAT should show up - Store B's 5000@13%=650
            // and the out-of-range 9000@13%=1170 sale must both be excluded.
            return $outputVat['gross'] === '130.00';
        },
    );

    $tenant->delete();
});

test('the VAT summary export lists every return line in filing order and ties back to the ledger', function () {
    $domain = 'vat-summary-export-rows.tenant-test';
    $tenant = provisionVatExportTestTenant($domain);

    $tenant->run(function () {
        seedVatExportTestData();
    });

    loginVatExportTestUser($domain);

    Excel::fake();

    $this->get("http://{$domain}/reports/vat-summary/export?from=2026-06-01&to=2026-06-30")->assertOk();

    Excel::assertDownloaded(
        'vat-summary-2026-06-01-to-2026-06-30.xlsx',
        function (VatSummaryExport $export) {
            expect($export->headings())->toBe(['Metric', 'Amount']);

            $rows = $export->collection();

            // Capital and cancellation lines are part of the return now, and
            // with no store filter the ledger reconciliation block is
            // appended as three further rows.
            expect($rows->pluck('metric')->all())->toBe([
                'Output VAT on sales',
                'Output VAT on capital sales',
                'Less: cancelled sales VAT',
                'Less: credit note VAT',
                'Net Output VAT',
                'Input VAT on purchases',
                'Input VAT on capital purchases',
                'Less: cancelled purchases VAT',
                'Less: debit note VAT',
                'Net Input VAT',
                'Net VAT Payable',
                'Ledger VAT payable for the period',
                'Report total',
                'Difference (must be 0.00)',
            ]);

            // 1000 @ 13% out, 200 @ 13% in, so 130 - 26 = 104 payable, and
            // the ledger movement on LIA20/ASA23 must agree to the paisa.
            expect($rows->pluck('amount', 'metric')->all())->toMatchArray([
                'Output VAT on sales' => '130.00',
                'Output VAT on capital sales' => '0.00',
                'Less: cancelled sales VAT' => '0.00',
                'Less: credit note VAT' => '0.00',
                'Net Output VAT' => '130.00',
                'Input VAT on purchases' => '26.00',
                'Net Input VAT' => '26.00',
                'Net VAT Payable' => '104.00',
                'Report total' => '104.00',
                'Difference (must be 0.00)' => '0.00',
            ]);

            // The amount cell stays a real number - the 2-decimal display
            // comes from WithColumnFormatting, not from a formatted string.
            expect($export->map($rows->first())[1])->toBeFloat()->toBe(130.0);

            return true;
        },
    );

    $tenant->delete();
});

test('the sales VAT book export carries the IRD sales book columns and writes money as real numbers', function () {
    $domain = 'sales-vat-book-export-columns.tenant-test';
    $tenant = provisionVatExportTestTenant($domain);

    $tenant->run(function () {
        seedVatExportTestData();
    });

    loginVatExportTestUser($domain);

    Excel::fake();

    $this->get("http://{$domain}/reports/sales-vat-book/export?from=2026-06-01&to=2026-06-30")->assertOk();

    Excel::assertDownloaded(
        'sales-vat-book-2026-06-01-to-2026-06-30.xlsx',
        function (SalesVatBookExport $export) {
            // Entry and Capital are new columns: a book row is now either an
            // issue or a cancellation, and the capital split is reported
            // separately the way the IRD sales book asks for it.
            expect($export->headings())->toBe([
                'SN', 'Date (BS)', 'Date (AD)', 'Invoice #', 'Buyer', 'Buyer PAN', 'Type', 'Entry', 'Taxable', 'Exempt', 'VAT', 'Capital', 'Total',
            ]);

            $rows = $export->collection();

            // The single invoice, plus the trailing Total row the export adds.
            expect($rows)->toHaveCount(2);

            $invoice = $export->map($rows->first());

            expect($invoice[2])->toBe('2026-06-01')
                ->and($invoice[6])->toBe('full')
                ->and($invoice[7])->toBe('issued')
                ->and($invoice[8])->toBe(1000.0)
                ->and($invoice[9])->toBe(0.0)
                ->and($invoice[10])->toBe(130.0)
                ->and($invoice[11])->toBe(0.0)
                ->and($invoice[12])->toBe(1130.0);

            foreach ([8, 9, 10, 11, 12] as $moneyColumn) {
                expect($invoice[$moneyColumn])->toBeFloat();
            }

            return true;
        },
    );

    $tenant->delete();
});

test('the purchase VAT book export carries the IRD purchase book columns and the supplier bill number', function () {
    $domain = 'purchase-vat-book-export-columns.tenant-test';
    $tenant = provisionVatExportTestTenant($domain);

    $tenant->run(function () {
        vatExportTestOpenFiscalYear();
        $admin = vatExportTestAdmin();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        Purchase::post(
            ['supplier_id' => $supplier->id, 'bill_number' => 'SUP-4471', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 200, 'discount' => 0]],
            $admin,
        );
    });

    loginVatExportTestUser($domain);

    Excel::fake();

    $this->get("http://{$domain}/reports/purchase-vat-book/export?from=2026-06-01&to=2026-06-30")->assertOk();

    Excel::assertDownloaded(
        'purchase-vat-book-2026-06-01-to-2026-06-30.xlsx',
        function (PurchaseVatBookExport $export) {
            expect($export->headings())->toBe([
                'SN', 'Date (BS)', 'Date (AD)', 'Bill #', 'Supplier', 'Supplier PAN', 'Entry', 'Taxable', 'Exempt', 'VAT', 'Capital', 'Total',
            ]);

            $rows = $export->collection();
            expect($rows)->toHaveCount(2);

            $bill = $export->map($rows->first());

            // Column 3 is the SUPPLIER's own bill number, never our internal
            // voucher number: an input-VAT claim is checked against the
            // seller's invoice.
            expect($bill[3])->toBe('SUP-4471')
                ->and($bill[6])->toBe('issued')
                ->and($bill[7])->toBe(200.0)
                ->and($bill[9])->toBe(26.0)
                ->and($bill[10])->toBe(0.0)
                ->and($bill[11])->toBe(226.0);

            foreach ([7, 8, 9, 10, 11] as $moneyColumn) {
                expect($bill[$moneyColumn])->toBeFloat();
            }

            return true;
        },
    );

    $tenant->delete();
});

test('the VAT book exports include capital sales and capital purchases as their own rows', function () {
    $domain = 'vat-book-export-capital.tenant-test';
    $tenant = provisionVatExportTestTenant($domain);

    $tenant->run(function () {
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
        CapitalSale::post(
            ['date' => '2026-06-02', 'payment_mode' => 'cash', 'vat_rate' => '13'],
            [['account_id' => Account::factory()->create()->id, 'amount' => '2000', 'vatable' => true]],
            $admin,
        );

        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 200, 'discount' => 0]],
            $admin,
        );
        CapitalPurchase::post(
            ['type' => 'capital', 'date' => '2026-06-02', 'payment_mode' => 'cash', 'vat_rate' => '13'],
            [['account_id' => Account::factory()->create()->id, 'amount' => '500', 'vatable' => true]],
            $admin,
        );
    });

    loginVatExportTestUser($domain);

    Excel::fake();

    $this->get("http://{$domain}/reports/sales-vat-book/export?from=2026-06-01&to=2026-06-30")->assertOk();
    $this->get("http://{$domain}/reports/purchase-vat-book/export?from=2026-06-01&to=2026-06-30")->assertOk();

    Excel::assertDownloaded(
        'sales-vat-book-2026-06-01-to-2026-06-30.xlsx',
        function (SalesVatBookExport $export) {
            $rows = $export->collection();

            // Ordinary invoice, capital invoice, Total row.
            expect($rows)->toHaveCount(3);

            $capital = $export->map($rows[1]);

            expect($capital[6])->toBe('capital')
                ->and($capital[8])->toBe(2000.0)
                // The capital column repeats the taxable amount so the
                // taxable total splits the way the IRD book asks for.
                ->and($capital[11])->toBe(2000.0)
                ->and($capital[10])->toBe(260.0);

            $total = $export->map($rows->last());

            expect($total[8])->toBe(3000.0)
                ->and($total[10])->toBe(390.0)
                ->and($total[11])->toBe(2000.0);

            return true;
        },
    );

    Excel::assertDownloaded(
        'purchase-vat-book-2026-06-01-to-2026-06-30.xlsx',
        function (PurchaseVatBookExport $export) {
            $rows = $export->collection();

            expect($rows)->toHaveCount(3);

            $capital = $export->map($rows[1]);

            expect($capital[7])->toBe(500.0)
                ->and($capital[10])->toBe(500.0)
                ->and($capital[9])->toBe(65.0);

            $total = $export->map($rows->last());

            expect($total[7])->toBe(700.0)
                ->and($total[9])->toBe(91.0)
                ->and($total[10])->toBe(500.0);

            return true;
        },
    );

    $tenant->delete();
});

test('a cancelled invoice appears positive in the month it was issued and negative in the month its reversal is dated', function () {
    $domain = 'sales-vat-book-export-cancellation.tenant-test';
    $tenant = provisionVatExportTestTenant($domain);

    $tenant->run(function () {
        vatExportTestOpenFiscalYear();
        $admin = vatExportTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000, 'discount' => 0]],
            $admin,
        );

        // The Reversal voucher is dated when the cancellation happens, so
        // the take-back belongs to August, not to June's filed return.
        Carbon::setTestNow('2026-08-15 06:00:00');

        try {
            $sale->cancel($admin, 'Recorded in error');
        } finally {
            Carbon::setTestNow();
        }
    });

    loginVatExportTestUser($domain);

    Excel::fake();

    $this->get("http://{$domain}/reports/sales-vat-book/export?from=2026-06-01&to=2026-06-30")->assertOk();
    $this->get("http://{$domain}/reports/sales-vat-book/export?from=2026-06-01&to=2026-08-31")->assertOk();

    // June alone still carries the invoice at its full positive value: it was
    // genuinely issued and filed that month.
    Excel::assertDownloaded(
        'sales-vat-book-2026-06-01-to-2026-06-30.xlsx',
        function (SalesVatBookExport $export) {
            $rows = $export->collection();

            expect($rows)->toHaveCount(2);

            $invoice = $export->map($rows->first());

            expect($invoice[7])->toBe('issued')
                ->and($invoice[10])->toBe(130.0);

            expect($export->map($rows->last())[10])->toBe(130.0);

            return true;
        },
    );

    // Over a range spanning both months the same invoice appears twice, and
    // the two rows cancel each other out exactly.
    Excel::assertDownloaded(
        'sales-vat-book-2026-06-01-to-2026-08-31.xlsx',
        function (SalesVatBookExport $export) {
            $rows = $export->collection();

            expect($rows)->toHaveCount(3);

            $issued = $export->map($rows[0]);
            $cancelled = $export->map($rows[1]);

            expect($issued[7])->toBe('issued')
                ->and($issued[10])->toBe(130.0)
                ->and($cancelled[2])->toBe('2026-08-15')
                ->and($cancelled[7])->toBe('cancelled')
                ->and($cancelled[8])->toBe(-1000.0)
                ->and($cancelled[10])->toBe(-130.0)
                ->and($cancelled[12])->toBe(-1130.0);

            expect($export->map($rows->last())[10])->toBe(0.0);

            return true;
        },
    );

    $tenant->delete();
});
