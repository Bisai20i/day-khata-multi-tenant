<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSalePrintTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginSalePrintTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the sale print route returns a streamed PDF for an authenticated user', function () {
    $domain = 'sale-print-http.tenant-test';
    $tenant = provisionSalePrintTestTenant($domain);

    $saleId = null;
    $tenant->run(function () use (&$saleId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $saleId = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        )->id;
    });

    loginSalePrintTestUser($domain);

    $this->get("http://{$domain}/sales/{$saleId}/print")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $tenant->delete();
});

test('the sale print route renders the thermal receipt layout when the paper size is 58mm', function () {
    $domain = 'sale-print-thermal.tenant-test';
    $tenant = provisionSalePrintTestTenant($domain);

    $saleId = null;
    $tenant->run(function () use (&$saleId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        CompanySetting::current()->update(['print_paper_size' => '58mm']);

        $saleId = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        )->id;
    });

    loginSalePrintTestUser($domain);

    $this->get("http://{$domain}/sales/{$saleId}/print")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $tenant->delete();
});

test('the sale print route is rejected for an unauthenticated request', function () {
    $domain = 'sale-print-guest.tenant-test';
    $tenant = provisionSalePrintTestTenant($domain);

    $saleId = null;
    $tenant->run(function () use (&$saleId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $saleId = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 50, 'discount' => 0]],
            $admin,
        )->id;
    });

    $this->get("http://{$domain}/sales/{$saleId}/print")
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});

/**
 * @return array{sale: Sale, company: CompanySetting}
 */
function buildSalePrintTestSale(string $invoiceType, string $paymentMode): array
{
    $admin = User::factory()->create(['email' => 'owner@example.com']);
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    $customer = Customer::factory()->create(['name' => 'Rebate Test Customer']);
    $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
    $bankAccountId = $paymentMode === 'bank' ? Account::factory()->create()->id : null;

    $sale = Sale::post(
        [
            'customer_id' => $customer->id,
            'invoice_type' => $invoiceType,
            'date' => '2026-06-01',
            'payment_mode' => $paymentMode,
            'bank_account_id' => $bankAccountId,
        ],
        [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000, 'discount' => 0]],
        $admin,
    );

    $company = CompanySetting::current();
    $company->update(['pan_vat_number' => '123456789']);

    return ['sale' => $sale->fresh(['customer', 'agent', 'bankAccount', 'lines.item', 'lines.itemUnit']), 'company' => $company];
}

test('an abbreviated invoice hides buyer info and the VAT breakdown, and prints the mandatory note and boxed PAN digits', function () {
    $domain = 'sale-print-abbreviated.tenant-test';
    $tenant = provisionSalePrintTestTenant($domain);

    $tenant->run(function () {
        ['sale' => $sale, 'company' => $company] = buildSalePrintTestSale('abbreviated', 'cash');

        $html = view('pdf.sale', [
            'sale' => $sale,
            'company' => $company,
            'documentNumber' => 'SLA-1',
            'documentDate' => '2026-06-01',
        ])->render();

        expect($html)
            ->not->toContain('Bill To')
            ->not->toContain('Taxable Amount')
            ->not->toContain('VAT (')
            ->toContain('This invoice shall not be issued for the sale of goods or services where the taxable value exceeds NPR 10,000.')
            ->toContain('<div class="pan-box-wrapper">');
    });

    $tenant->delete();
});

test('a pan invoice shows buyer info but hides the VAT breakdown', function () {
    $domain = 'sale-print-pan.tenant-test';
    $tenant = provisionSalePrintTestTenant($domain);

    $tenant->run(function () {
        ['sale' => $sale, 'company' => $company] = buildSalePrintTestSale('pan', 'cash');

        $html = view('pdf.sale', [
            'sale' => $sale,
            'company' => $company,
            'documentNumber' => 'SLP-1',
            'documentDate' => '2026-06-01',
        ])->render();

        // Audit P0-10: a PAN bill used to charge a hidden 13% VAT that this
        // very view then hid from the customer, so the printed grand total was
        // 1130 on a 1000 line with no VAT row explaining it.
        expect($sale->vat_amount)->toBe('0.00')
            ->and($sale->vat_rate)->toBe('0.00')
            ->and($sale->total)->toBe('1000.00')
            ->and($sale->nontaxable_amount)->toBe('1000.00');

        expect($html)
            ->toContain('Bill To')
            ->toContain('Rebate Test Customer')
            ->not->toContain('Taxable Amount')
            ->not->toContain('VAT (')
            ->not->toContain('<div class="pan-box-wrapper">')
            ->not->toContain('This invoice shall not be issued');
    });

    $tenant->delete();
});

test('a full invoice paid by bank does not include a digital payment VAT rebate', function () {
    $domain = 'sale-print-full-digital.tenant-test';
    $tenant = provisionSalePrintTestTenant($domain);

    $tenant->run(function () {
        ['sale' => $sale, 'company' => $company] = buildSalePrintTestSale('full', 'bank');

        // taxable 1000, vat 13% = 130, total = 1130. The 10% "digital
        // payment rebate" was a Phase B removal (dead, unbacked-by-any-
        // settings-toggle print-time assumption - see pdf/sale.blade.php);
        // this pins that it stays gone rather than accidentally reappearing.
        expect((float) $sale->vat_amount)->toBe(130.0);
        expect((float) $sale->total)->toBe(1130.0);

        $html = view('pdf.sale', [
            'sale' => $sale,
            'company' => $company,
            'documentNumber' => 'SL-1',
            'documentDate' => '2026-06-01',
        ])->render();

        expect($html)
            ->toContain('Taxable Amount')
            ->toContain('1,130.00')
            ->not->toContain('Digital Payment Rebate')
            ->not->toContain('Net Payable');
    });

    $tenant->delete();
});

test('a full invoice paid by cash does not include a digital payment VAT rebate', function () {
    $domain = 'sale-print-full-cash.tenant-test';
    $tenant = provisionSalePrintTestTenant($domain);

    $tenant->run(function () {
        ['sale' => $sale, 'company' => $company] = buildSalePrintTestSale('full', 'cash');

        $html = view('pdf.sale', [
            'sale' => $sale,
            'company' => $company,
            'documentNumber' => 'SL-1',
            'documentDate' => '2026-06-01',
        ])->render();

        expect($html)
            ->toContain('Taxable Amount')
            ->not->toContain('Digital Payment Rebate')
            ->not->toContain('Net Payable');
    });

    $tenant->delete();
});

test('the printed invoice includes the company logo when one is set', function () {
    $domain = 'sale-print-logo-present.tenant-test';
    $tenant = provisionSalePrintTestTenant($domain);

    $tenant->run(function () {
        Storage::fake('public');

        ['sale' => $sale, 'company' => $company] = buildSalePrintTestSale('full', 'cash');
        $company->update(['logo_path' => 'tenant-logos/1/logo.png']);
        Storage::disk('public')->put($company->logo_path, 'fake-logo-contents');

        $html = view('pdf.sale', [
            'sale' => $sale,
            'company' => $company->fresh(),
            'documentNumber' => 'SL-1',
            'documentDate' => '2026-06-01',
        ])->render();

        // Asserts the actual rendered <img class="company-logo"> tag, not
        // just the always-present .company-logo CSS rule in the <style>
        // block (which would make this assertion trivially true either way).
        expect($html)->toContain('class="company-logo"');
    });

    $tenant->delete();
});

test('the printed invoice omits the logo image when none is set', function () {
    $domain = 'sale-print-logo-absent.tenant-test';
    $tenant = provisionSalePrintTestTenant($domain);

    $tenant->run(function () {
        ['sale' => $sale, 'company' => $company] = buildSalePrintTestSale('full', 'cash');

        $html = view('pdf.sale', [
            'sale' => $sale,
            'company' => $company,
            'documentNumber' => 'SL-1',
            'documentDate' => '2026-06-01',
        ])->render();

        expect($html)->not->toContain('class="company-logo"');
    });

    $tenant->delete();
});

test('the sale print route resolves the document number prefix from configured invoicing settings', function () {
    $domain = 'sale-print-custom-prefix.tenant-test';
    $tenant = provisionSalePrintTestTenant($domain);

    $saleId = null;
    $voucherNumber = null;
    $tenant->run(function () use (&$saleId, &$voucherNumber) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        CompanySetting::current()->update(['sale_full_prefix' => 'CUSTOM']);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $saleId = $sale->id;
        $voucherNumber = $sale->journalVoucher->voucher_number;
    });

    loginSalePrintTestUser($domain);

    $fakePdf = Mockery::mock();
    $fakePdf->shouldReceive('stream')->once()->andReturn(response('fake-pdf-bytes', 200, ['Content-Type' => 'application/pdf']));

    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(fn (string $view, array $data) => $view === 'pdf.sale' && $data['documentNumber'] === "CUSTOM-{$voucherNumber}")
        ->andReturn($fakePdf);

    $this->get("http://{$domain}/sales/{$saleId}/print")->assertOk();

    $tenant->delete();
});

test('the printed invoice orders its totals subtotal, discount, taxable, VAT, grand total and shows net receivable', function () {
    $domain = 'sale-print-totals-order.tenant-test';
    $tenant = provisionSalePrintTestTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create(['name' => 'Order Test Customer']);
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false, 'hs_code' => '1234.56.78']);
        $tdsAccount = Account::factory()->create();

        $sale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'discount' => '100',
                'discount_type' => 'flat',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => '90',
            ],
            [['item_id' => $item->id, 'quantity' => '1.5', 'rate' => '1000.00']],
            $admin,
        );

        $html = view('pdf.sale', [
            'sale' => $sale->fresh(['customer', 'agent', 'bankAccount', 'lines.item', 'lines.itemUnit']),
            'company' => CompanySetting::current(),
            'documentNumber' => $sale->invoice_number,
            'documentDate' => '2026-06-01',
        ])->render();

        // The discount has to be visibly taken off before the taxable amount
        // the VAT is charged on (audit P1: "taxable prints before discount").
        // Scoped to the totals table, since "Discount" is also a column header
        // in the items table above it.
        $totalsBlock = substr($html, (int) strpos($html, 'totals-table'));
        $order = ['Subtotal', 'Discount', 'Taxable Amount', 'VAT (', 'Grand Total', 'TDS Withheld', 'Net Receivable'];
        $positions = array_map(fn (string $label) => strpos($totalsBlock, $label), $order);

        expect($positions)->not->toContain(false)
            ->and($positions)->toBe(array_values(collect($positions)->sort()->all()));

        // 1.5 x 1000 = 1500, less a 100 discount = 1400 taxable, VAT 182,
        // grand total 1582, less 90 TDS withheld = 1492 receivable.
        expect($html)
            ->toContain('1,500.00')
            ->toContain('1,400.00')
            ->toContain('182.00')
            ->toContain('1,582.00')
            ->toContain('1,492.00')
            // 4dp-capable quantity and rate, so qty x rate visibly equals the line.
            ->toContain('1.5')
            ->toContain('HS Code')
            ->toContain('1234.56.78');
    });

    $tenant->delete();
});

test('the printed invoice shows the buyer as the bill was issued, not the renamed customer', function () {
    $domain = 'sale-print-buyer-snapshot.tenant-test';
    $tenant = provisionSalePrintTestTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create(['name' => 'Original Buyer', 'tpin' => '600123456']);
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        );

        expect($sale->buyer_name)->toBe('Original Buyer')->and($sale->buyer_pan)->toBe('600123456');

        // Editing the customer must not rewrite an already-issued tax invoice.
        $customer->update(['name' => 'Renamed Buyer']);

        $html = view('pdf.sale', [
            'sale' => $sale->fresh(['customer', 'agent', 'bankAccount', 'lines.item', 'lines.itemUnit']),
            'company' => CompanySetting::current(),
            'documentNumber' => $sale->invoice_number,
            'documentDate' => '2026-06-01',
        ])->render();

        expect($html)->toContain('Original Buyer')->not->toContain('Renamed Buyer');
    });

    $tenant->delete();
});
