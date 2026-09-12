<?php

use App\Enums\FiscalYearStatus;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AmountInWords;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSalesReturnPrintTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginSalesReturnPrintTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the sales return print route returns a streamed PDF for an authenticated user', function () {
    $domain = 'sales-return-print-http.tenant-test';
    $tenant = provisionSalesReturnPrintTestTenant($domain);

    $returnId = null;
    $tenant->run(function () use (&$returnId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        $returnId = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-05', 'reason' => 'Damaged'],
            [['sale_line_id' => $saleLine->id, 'quantity' => 4]],
            $admin,
        )->id;
    });

    loginSalesReturnPrintTestUser($domain);

    $this->get("http://{$domain}/sales-returns/{$returnId}/print")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $tenant->delete();
});

test('the sales return print route is rejected for an unauthenticated request', function () {
    $domain = 'sales-return-print-guest.tenant-test';
    $tenant = provisionSalesReturnPrintTestTenant($domain);

    $returnId = null;
    $tenant->run(function () use (&$returnId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        $returnId = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => 2]],
            $admin,
        )->id;
    });

    $this->get("http://{$domain}/sales-returns/{$returnId}/print")
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});

test('a posted return prints as a numbered credit note with its BS date and amount in words', function () {
    $domain = 'sales-return-print-credit-note.tenant-test';
    $tenant = provisionSalesReturnPrintTestTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY 2082/83', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create(['name' => 'Credit Note Customer']);
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '10', 'rate' => '100', 'discount' => '0']],
            $admin,
        );

        $salesReturn = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-05', 'reason' => 'Damaged'],
            [['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => '4']],
            $admin,
        );

        // The number is stored on the row, not re-derived at display time (C7).
        expect($salesReturn->credit_note_number)->toBe('SR-1')
            ->and($salesReturn->documentNumber())->toBe('SR-1');

        $html = view('pdf.sales-return', [
            'salesReturn' => $salesReturn->fresh(['sale.customer', 'lines.saleLine.item', 'lines.saleLine.itemUnit', 'journalVoucher', 'refundAccount', 'fiscalYear']),
            'company' => CompanySetting::current(),
            'isCreditNote' => true,
            'documentNumber' => $salesReturn->documentNumber(),
            'documentDate' => '2026-06-05',
            'dateAd' => '2026-06-05',
            'dateBs' => NepaliCalendar::formatBs('2026-06-05'),
            'fiscalYearName' => 'FY 2082/83',
            'copyNumber' => 1,
            'amountInWords' => AmountInWords::rupees(Money::of($salesReturn->total)),
        ])->render();

        expect($html)
            ->toContain('Credit Note')
            ->toContain('SR-1')
            // Cites the invoice it credits, by its stored number.
            ->toContain($sale->invoice_number)
            ->toContain('Date (BS)')
            ->toContain(NepaliCalendar::formatBs('2026-06-05'))
            ->toContain('FY 2082/83')
            ->toContain('Original')
            ->toContain('Amount in Words')
            // 4 of 10 units at 100 = 400 taxable, VAT 52, total 452.
            ->toContain('452.00')
            ->toContain('Four Hundred Fifty Two');
    });

    $tenant->delete();
});

test('a pending request prints as a request, never as a numbered credit note', function () {
    $domain = 'sales-return-print-request.tenant-test';
    $tenant = provisionSalesReturnPrintTestTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '5', 'rate' => '10', 'discount' => '0']],
            $admin,
        );

        $request = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => '2']],
            $admin,
        );

        $html = view('pdf.sales-return', [
            'salesReturn' => $request->fresh(['sale.customer', 'lines.saleLine.item', 'lines.saleLine.itemUnit', 'journalVoucher', 'refundAccount', 'fiscalYear']),
            'company' => CompanySetting::current(),
            'isCreditNote' => false,
            'documentNumber' => $request->documentNumber(),
            'documentDate' => '2026-06-02',
            'dateAd' => '2026-06-02',
            'dateBs' => NepaliCalendar::formatBs('2026-06-02'),
            'fiscalYearName' => null,
            'copyNumber' => 1,
            'amountInWords' => AmountInWords::rupees(Money::of($request->total)),
        ])->render();

        expect($request->credit_note_number)->toBeNull()
            ->and($html)
            ->toContain('Return Request')
            ->toContain("Return request #{$request->id}")
            ->toContain('Awaiting approval')
            ->not->toContain('SR-');
    });

    $tenant->delete();
});

test('reprinting a credit note logs a second copy and stamps it as one', function () {
    $domain = 'sales-return-print-copy.tenant-test';
    $tenant = provisionSalesReturnPrintTestTenant($domain);

    $returnId = null;
    $tenant->run(function () use (&$returnId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '5', 'rate' => '10', 'discount' => '0']],
            $admin,
        );

        $returnId = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => '2']],
            $admin,
        )->id;
    });

    loginSalesReturnPrintTestUser($domain);

    $this->get("http://{$domain}/sales-returns/{$returnId}/print")->assertOk();
    $this->get("http://{$domain}/sales-returns/{$returnId}/print")->assertOk();

    $tenant->run(function () use ($returnId) {
        // Every print is logged, and the second one is a copy (C9).
        expect(PrintLog::copiesPrinted(SalesReturn::findOrFail($returnId)))->toBe(2);
    });

    $tenant->delete();
});
