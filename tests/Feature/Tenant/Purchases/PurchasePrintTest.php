<?php

use App\Enums\FiscalYearStatus;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\Purchase;
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

function provisionPurchasePrintTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginPurchasePrintTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the purchase print route returns a streamed PDF for an authenticated user', function () {
    $domain = 'purchase-print-http.tenant-test';
    $tenant = provisionPurchasePrintTestTenant($domain);

    $purchaseId = null;
    $tenant->run(function () use (&$purchaseId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $purchaseId = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        )->id;
    });

    loginPurchasePrintTestUser($domain);

    $this->get("http://{$domain}/purchases/{$purchaseId}/print")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $tenant->delete();
});

test('the purchase print route is rejected for an unauthenticated request', function () {
    $domain = 'purchase-print-guest.tenant-test';
    $tenant = provisionPurchasePrintTestTenant($domain);

    $purchaseId = null;
    $tenant->run(function () use (&$purchaseId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $purchaseId = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 50, 'discount' => 0]],
            $admin,
        )->id;
    });

    $this->get("http://{$domain}/purchases/{$purchaseId}/print")
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});

test('the purchase print route resolves the document number prefix from configured invoicing settings', function () {
    $domain = 'purchase-print-custom-prefix.tenant-test';
    $tenant = provisionPurchasePrintTestTenant($domain);

    $purchaseId = null;
    $voucherNumber = null;
    $tenant->run(function () use (&$purchaseId, &$voucherNumber) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        CompanySetting::current()->update(['purchase_prefix' => 'CUSTOMPU']);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $purchaseId = $purchase->id;
        $voucherNumber = $purchase->journalVoucher->voucher_number;
    });

    loginPurchasePrintTestUser($domain);

    $fakePdf = Mockery::mock();
    $fakePdf->shouldReceive('stream')->once()->andReturn(response('fake-pdf-bytes', 200, ['Content-Type' => 'application/pdf']));

    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(fn (string $view, array $data) => $view === 'pdf.purchase' && $data['documentNumber'] === "CUSTOMPU-{$voucherNumber}")
        ->andReturn($fakePdf);

    $this->get("http://{$domain}/purchases/{$purchaseId}/print")->assertOk();

    $tenant->delete();
});

test('the purchase print passes the C9 compliance variables and logs every copy', function () {
    $domain = 'purchase-print-compliance.tenant-test';
    $tenant = provisionPurchasePrintTestTenant($domain);

    $purchaseId = null;
    $tenant->run(function () use (&$purchaseId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $purchaseId = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 1000]],
            User::where('email', 'owner@example.com')->firstOrFail(),
        )->id;
    });

    loginPurchasePrintTestUser($domain);

    // First print: the original. The BS date, the fiscal year and the amount
    // in words all have to reach the view (CONTRACTS C9).
    $fakePdf = Mockery::mock();
    $fakePdf->shouldReceive('stream')->once()->andReturn(response('pdf', 200, ['Content-Type' => 'application/pdf']));

    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(fn (string $view, array $data) => $view === 'pdf.purchase'
            && $data['copyNumber'] === 1
            && $data['dateAd'] === '2026-06-01'
            && $data['dateBs'] === NepaliCalendar::formatBs('2026-06-01')
            && $data['fiscalYearName'] === 'FY1'
            && $data['amountInWords'] === AmountInWords::rupees(Money::of('1000.00')))
        ->andReturn($fakePdf);

    $this->get("http://{$domain}/purchases/{$purchaseId}/print")->assertOk();

    // Every reprint is a numbered copy, which is what stamps "Copy of
    // Original" on the face of the paper.
    $secondPdf = Mockery::mock();
    $secondPdf->shouldReceive('stream')->once()->andReturn(response('pdf', 200, ['Content-Type' => 'application/pdf']));

    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(fn (string $view, array $data) => $data['copyNumber'] === 2)
        ->andReturn($secondPdf);

    $this->get("http://{$domain}/purchases/{$purchaseId}/print")->assertOk();

    $tenant->run(function () use ($purchaseId) {
        expect(PrintLog::where('printable_type', (new Purchase)->getMorphClass())
            ->where('printable_id', $purchaseId)
            ->count())->toBe(2);
    });

    $tenant->delete();
});
