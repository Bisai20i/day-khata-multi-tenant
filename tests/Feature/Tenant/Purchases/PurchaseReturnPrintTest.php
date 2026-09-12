<?php

use App\Enums\FiscalYearStatus;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AmountInWords;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseReturnPrintTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginPurchaseReturnPrintTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the purchase return print route returns a streamed PDF for an authenticated user', function () {
    $domain = 'purchase-return-print-http.tenant-test';
    $tenant = provisionPurchaseReturnPrintTestTenant($domain);

    $returnId = null;
    $tenant->run(function () use (&$returnId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100]],
            $admin,
        );

        $line = PurchaseLine::where('purchase_id', $purchase->id)->firstOrFail();

        $returnId = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05', 'reason' => 'Damaged goods'],
            [['purchase_line_id' => $line->id, 'quantity' => 4]],
            $admin,
        )->id;
    });

    loginPurchaseReturnPrintTestUser($domain);

    $this->get("http://{$domain}/purchase-returns/{$returnId}/print")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $tenant->delete();
});

test('the purchase return print route is rejected for an unauthenticated request', function () {
    $domain = 'purchase-return-print-guest.tenant-test';
    $tenant = provisionPurchaseReturnPrintTestTenant($domain);

    $returnId = null;
    $tenant->run(function () use (&$returnId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 100]],
            $admin,
        );

        $line = PurchaseLine::where('purchase_id', $purchase->id)->firstOrFail();

        $returnId = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $line->id, 'quantity' => 2]],
            $admin,
        )->id;
    });

    $this->get("http://{$domain}/purchase-returns/{$returnId}/print")
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});

test('the debit note print carries the stored number, the C9 variables and a copy stamp', function () {
    $domain = 'purchase-return-print-compliance.tenant-test';
    $tenant = provisionPurchaseReturnPrintTestTenant($domain);

    $returnId = null;
    $debitNoteNumber = null;
    $returnTotal = null;
    $tenant->run(function () use (&$returnId, &$debitNoteNumber, &$returnTotal) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        // The tenant's own debit-note prefix, not a hardcoded PR: the Settings
        // screen's prefix-distinctness check is meaningless if posting ignores
        // the setting.
        CompanySetting::current()->update(['purchase_return_prefix' => 'DN']);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100]],
            $admin,
        );

        $line = PurchaseLine::where('purchase_id', $purchase->id)->firstOrFail();

        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $line->id, 'quantity' => 3]],
            $admin,
        );

        $returnId = $return->id;
        $debitNoteNumber = $return->debit_note_number;
        $returnTotal = $return->total;

        expect($debitNoteNumber)->toBe('DN-'.$return->journalVoucher->voucher_number);
    });

    loginPurchaseReturnPrintTestUser($domain);

    $fakePdf = Mockery::mock();
    $fakePdf->shouldReceive('stream')->once()->andReturn(response('pdf', 200, ['Content-Type' => 'application/pdf']));

    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(fn (string $view, array $data) => $view === 'pdf.purchase-return'
            && $data['documentNumber'] === $debitNoteNumber
            && $data['copyNumber'] === 1
            && $data['dateAd'] === '2026-06-05'
            && $data['dateBs'] === NepaliCalendar::formatBs('2026-06-05')
            && $data['fiscalYearName'] === 'FY1'
            && $data['amountInWords'] === AmountInWords::rupees(Money::of($returnTotal)))
        ->andReturn($fakePdf);

    $this->get("http://{$domain}/purchase-returns/{$returnId}/print")->assertOk();

    $secondPdf = Mockery::mock();
    $secondPdf->shouldReceive('stream')->once()->andReturn(response('pdf', 200, ['Content-Type' => 'application/pdf']));

    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(fn (string $view, array $data) => $data['copyNumber'] === 2)
        ->andReturn($secondPdf);

    $this->get("http://{$domain}/purchase-returns/{$returnId}/print")->assertOk();

    $tenant->delete();
});
