<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\CapitalPurchaseSettlement;
use App\Models\FiscalYear;
use App\Models\PrintLog;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Flags G-01 and G-11 (print part): a capital or service bill can be printed,
 * live or cancelled, with its settlements, and every print is logged as a
 * numbered copy (CONTRACTS C9).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionCapitalPrintTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * A credit bill of 1000 with one 400 payment, and a second bill cancelled by
 * "Asha Admin" with the reason "Wrong supplier".
 *
 * @return array{liveId: int, cancelledId: int}
 */
function seedCapitalPrintBills(): array
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    $admin = User::factory()->create(['email' => 'owner@example.com', 'name' => 'Asha Admin', 'role_id' => Role::where('slug', 'admin')->value('id')]);
    $supplier = Supplier::factory()->create(['name' => 'Himal Traders']);
    $account = Account::factory()->create(['name' => 'Office Equipment']);

    $live = CapitalPurchase::post(
        ['type' => 'capital', 'supplier_id' => $supplier->id, 'bill_number' => 'HT-77', 'date' => '2026-06-01', 'payment_mode' => 'credit', 'vat_rate' => '0'],
        [['account_id' => $account->id, 'amount' => '1000', 'narration' => 'Laptop']],
        $admin,
    );
    CapitalPurchaseSettlement::settle($live, ['date' => '2026-06-10', 'amount' => '400', 'payment_mode' => 'cash'], $admin);

    $cancelled = CapitalPurchase::post(
        ['type' => 'service', 'date' => '2026-06-02', 'payment_mode' => 'cash', 'vat_rate' => '0'],
        [['account_id' => $account->id, 'amount' => '250']],
        $admin,
    );
    $cancelled->cancel($admin, 'Wrong supplier');

    return ['liveId' => $live->id, 'cancelledId' => $cancelled->id];
}

/**
 * Captures what the controller hands the PDF, then renders the same view as
 * HTML so the test can read it.
 */
function renderCapitalPrint(string $domain, int $id): string
{
    $captured = null;
    $fakePdf = Mockery::mock(Barryvdh\DomPDF\PDF::class);
    $fakePdf->shouldReceive('stream')->once()->andReturn(response('pdf', 200, ['Content-Type' => 'application/pdf']));

    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(function (string $view, array $data) use (&$captured) {
            $captured = [$view, $data];

            return $view === 'pdf.capital-purchase';
        })
        ->andReturn($fakePdf);

    test()->get("http://{$domain}/capital-purchases/{$id}/print")->assertOk();

    return view($captured[0], $captured[1])->render();
}

test('a live capital bill prints its lines, settlement and outstanding balance, and each print is a numbered copy', function () {
    $domain = 'capital-print-live.tenant-test';
    $tenant = provisionCapitalPrintTenant($domain);

    $ids = null;
    $tenant->run(function () use (&$ids) {
        $ids = seedCapitalPrintBills();
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $html = renderCapitalPrint($domain, $ids['liveId']);

    expect($html)->toContain('Capital Purchase')
        ->toContain('HT-77')
        ->toContain('Himal Traders')
        ->toContain('Office Equipment')
        ->toContain('Laptop')
        ->toContain('Payments made against this bill')
        ->toContain('400.00')
        // 1000 billed, 400 paid.
        ->toContain('600.00')
        ->toContain('Original');

    // The second print is a copy.
    expect(renderCapitalPrint($domain, $ids['liveId']))->toContain('Copy of Original - 1');

    $tenant->run(function () use ($ids) {
        expect(PrintLog::where('printable_type', (new CapitalPurchase)->getMorphClass())
            ->where('printable_id', $ids['liveId'])
            ->count())->toBe(2);
    });

    $tenant->delete();
});

test('a cancelled capital bill still prints, with who cancelled it, when and why', function () {
    $domain = 'capital-print-cancelled.tenant-test';
    $tenant = provisionCapitalPrintTenant($domain);

    $ids = null;
    $tenant->run(function () use (&$ids) {
        $ids = seedCapitalPrintBills();
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    expect(renderCapitalPrint($domain, $ids['cancelledId']))
        ->toContain('Service Purchase')
        ->toContain('Cancelled')
        ->toContain('by Asha Admin')
        ->toContain('Wrong supplier');

    $tenant->delete();
});

test('the capital print route returns a real PDF', function () {
    $domain = 'capital-print-pdf.tenant-test';
    $tenant = provisionCapitalPrintTenant($domain);

    $ids = null;
    $tenant->run(function () use (&$ids) {
        $ids = seedCapitalPrintBills();
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/capital-purchases/{$ids['liveId']}/print")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $tenant->delete();
});
