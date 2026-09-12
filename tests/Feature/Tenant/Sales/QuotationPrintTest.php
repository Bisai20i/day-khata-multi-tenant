<?php

use App\Enums\FiscalYearStatus;
use App\Enums\QuotationStatus;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionQuotationPrintTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginQuotationPrintTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

/**
 * @param  array<int, array{item_id: int, quantity: string, rate: string, discount?: string}>  $lines
 * @param  array{discount?: string, vat_rate?: string}  $header
 */
function makeQuotationForPrint(User $admin, Customer $customer, array $lines, array $header = []): Quotation
{
    $header = ['discount' => '0', 'vat_rate' => '13', ...$header];

    $quotation = Quotation::create([
        'customer_id' => $customer->id,
        'date' => '2026-06-01',
        'discount' => $header['discount'],
        'vat_rate' => $header['vat_rate'],
        ...Quotation::storedTotals(Quotation::calculateTotals($lines, $header)),
        'status' => QuotationStatus::Draft,
        'created_by' => $admin->id,
    ]);

    foreach ($lines as $line) {
        $quotation->lines()->create($line);
    }

    return $quotation;
}

test('the quotation print route returns a streamed PDF for an authenticated user', function () {
    $domain = 'quotation-print-http.tenant-test';
    $tenant = provisionQuotationPrintTestTenant($domain);

    $quotationId = null;
    $tenant->run(function () use (&$quotationId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $quotationId = makeQuotationForPrint($admin, $customer, [
            ['item_id' => $item->id, 'quantity' => '2', 'rate' => '100', 'discount' => '0'],
        ])->id;
    });

    loginQuotationPrintTestUser($domain);

    $this->get("http://{$domain}/quotations/{$quotationId}/print")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $tenant->delete();
});

test('the printed quotation shows the stored totals, not a recomputation', function () {
    $domain = 'quotation-print-totals.tenant-test';
    $tenant = provisionQuotationPrintTestTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $vatableItem = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
        $exemptItem = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $quotation = makeQuotationForPrint(
            $admin,
            $customer,
            [
                ['item_id' => $vatableItem->id, 'quantity' => '1', 'rate' => '1000', 'discount' => '0'],
                ['item_id' => $exemptItem->id, 'quantity' => '1', 'rate' => '500', 'discount' => '0'],
            ],
            ['discount' => '100', 'vat_rate' => '13'],
        );

        $quotation->load(['customer', 'lines.item']);
        $totals = $quotation->totals();

        $html = view('pdf.quotation', [
            'quotation' => $quotation,
            'lines' => $quotation->lines->values()->map(fn ($line, int $index): array => [
                'item' => $line->item,
                'quantity' => $totals->lines[$index]->quantity,
                'rate' => $totals->lines[$index]->rate,
                'discount' => $totals->lines[$index]->discountAmount,
                'line_total' => $totals->lines[$index]->lineTotal,
            ]),
            'subtotal' => $totals->subtotal(),
            'taxable' => Money::of($quotation->taxable_amount),
            'nontaxable' => Money::of($quotation->nontaxable_amount),
            'vat' => Money::of($quotation->vat_amount),
            'total' => Money::of($quotation->total),
            'company' => CompanySetting::current(),
            'documentNumber' => "QUO-{$quotation->id}",
            'documentDate' => '2026-06-01',
            'copyNumber' => 1,
            'dateAd' => '2026-06-01',
            'dateBs' => '2083-02-18',
            'fiscalYearName' => 'FY1',
        ])->render();

        // Indian grouping, and the exact figures stored on the row: the old PDF
        // charged VAT on the exempt line too and never showed the split.
        expect($html)->toContain('933.33')
            ->and($html)->toContain('466.67')
            ->and($html)->toContain('121.33')
            ->and($html)->toContain('1,521.33');
    });

    $tenant->delete();
});

test('the quotation print route is rejected for an unauthenticated request', function () {
    $domain = 'quotation-print-guest.tenant-test';
    $tenant = provisionQuotationPrintTestTenant($domain);

    $quotationId = null;
    $tenant->run(function () use (&$quotationId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $quotationId = makeQuotationForPrint($admin, $customer, [
            ['item_id' => $item->id, 'quantity' => '1', 'rate' => '50', 'discount' => '0'],
        ])->id;
    });

    $this->get("http://{$domain}/quotations/{$quotationId}/print")
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});
