<?php

use App\Enums\FiscalYearStatus;
use App\Enums\QuotationStatus;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
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

test('the quotation print route returns a streamed PDF for an authenticated user', function () {
    $domain = 'quotation-print-http.tenant-test';
    $tenant = provisionQuotationPrintTestTenant($domain);

    $quotationId = null;
    $tenant->run(function () use (&$quotationId) {
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        $quotation = Quotation::create([
            'customer_id' => $customer->id,
            'date' => '2026-06-01',
            'discount' => 0,
            'vat_rate' => 13,
            'status' => QuotationStatus::Draft,
            'created_by' => $admin->id,
        ]);
        $quotation->lines()->create(['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]);

        $quotationId = $quotation->id;
    });

    loginQuotationPrintTestUser($domain);

    $this->get("http://{$domain}/quotations/{$quotationId}/print")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

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

        $quotation = Quotation::create([
            'customer_id' => $customer->id,
            'date' => '2026-06-01',
            'discount' => 0,
            'vat_rate' => 13,
            'status' => QuotationStatus::Draft,
            'created_by' => $admin->id,
        ]);
        $quotation->lines()->create(['item_id' => $item->id, 'quantity' => 1, 'rate' => 50, 'discount' => 0]);

        $quotationId = $quotation->id;
    });

    $this->get("http://{$domain}/quotations/{$quotationId}/print")
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});
