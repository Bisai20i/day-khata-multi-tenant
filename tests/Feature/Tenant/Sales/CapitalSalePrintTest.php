<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\CapitalSale;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\PrintLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AmountInWords;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionCapitalSalePrintTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function capitalSalePrintTestActor(): User
{
    return User::factory()->create([
        'email' => 'owner@example.com',
        'role_id' => Role::where('slug', 'admin')->value('id'),
    ]);
}

function capitalSalePrintFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('the capital sale print route returns a streamed PDF and records the print', function () {
    $domain = 'capital-sale-print-http.tenant-test';
    $tenant = provisionCapitalSalePrintTestTenant($domain);

    $saleId = null;
    $tenant->run(function () use (&$saleId) {
        $actor = capitalSalePrintTestActor();
        capitalSalePrintFiscalYear();
        $customer = Customer::factory()->create();
        $account = Account::factory()->create();

        $saleId = CapitalSale::post(
            ['customer_id' => $customer->id, 'date' => '2026-06-01', 'payment_mode' => 'credit', 'vat_rate' => '13'],
            [['account_id' => $account->id, 'amount' => '1000', 'vatable' => true]],
            $actor,
        )->id;
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/capital-sales/{$saleId}/print")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $tenant->run(function () use ($saleId) {
        $sale = CapitalSale::findOrFail($saleId);
        // First print is the original; C9's PrintLog is what makes a reprint
        // announce itself as a copy.
        expect(PrintLog::where('printable_type', $sale->getMorphClass())
            ->where('printable_id', $sale->id)
            ->value('copy_number'))->toBe(1);
    });

    $tenant->delete();
});

test('the capital sale invoice shows the stored invoice number, the buyer snapshot and the amount in words', function () {
    $domain = 'capital-sale-print-content.tenant-test';
    $tenant = provisionCapitalSalePrintTestTenant($domain);

    $tenant->run(function () {
        $actor = capitalSalePrintTestActor();
        capitalSalePrintFiscalYear();
        $customer = Customer::factory()->create(['name' => 'Ram Traders', 'tpin' => '301234567', 'address' => 'Lalitpur']);
        $account = Account::factory()->create(['name' => 'Machinery Sale']);

        $sale = CapitalSale::post(
            ['customer_id' => $customer->id, 'date' => '2026-06-01', 'payment_mode' => 'credit', 'vat_rate' => '13'],
            [['account_id' => $account->id, 'amount' => '1000', 'vatable' => true]],
            $actor,
        );

        $sale->load(['customer', 'bankAccount', 'lines.account', 'journalVoucher', 'fiscalYear']);
        $total = Money::of($sale->total);

        $html = view('pdf.capital-sale', [
            'capitalSale' => $sale,
            'company' => CompanySetting::current(),
            'documentNumber' => $sale->documentNumber(),
            'documentDate' => '2026-06-01',
            'taxable' => Money::of($sale->taxable_amount),
            'nontaxable' => Money::of($sale->nontaxable_amount),
            'vat' => Money::of($sale->vat_amount),
            'total' => $total,
            'copyNumber' => 1,
            'dateAd' => '2026-06-01',
            'dateBs' => '2083-02-18',
            'fiscalYearName' => 'FY1',
            'amountInWords' => AmountInWords::rupees($total),
        ])->render();

        expect($html)->toContain($sale->invoice_number)
            ->and($html)->toContain('Ram Traders')
            ->and($html)->toContain('301234567')
            ->and($html)->toContain('Machinery Sale')
            ->and($html)->toContain('1,130.00')
            ->and($html)->toContain(AmountInWords::rupees($total));
    });

    $tenant->delete();
});

test('the capital sale print route is rejected for an unauthenticated request', function () {
    $domain = 'capital-sale-print-guest.tenant-test';
    $tenant = provisionCapitalSalePrintTestTenant($domain);

    $saleId = null;
    $tenant->run(function () use (&$saleId) {
        $actor = capitalSalePrintTestActor();
        capitalSalePrintFiscalYear();
        $account = Account::factory()->create();

        $saleId = CapitalSale::post(
            ['date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['account_id' => $account->id, 'amount' => '100']],
            $actor,
        )->id;
    });

    $this->get("http://{$domain}/capital-sales/{$saleId}/print")
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});
