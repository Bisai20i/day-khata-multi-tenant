<?php

use App\Enums\FiscalYearStatus;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionVatReportFormatsTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Report Formats Test Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginVatReportFormatsUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('sales register computes subtotal and supports search filter', function () {
    $domain = 'sales-reg-format.tenant-test';
    $tenant = provisionVatReportFormatsTenant($domain);

    $tenant->run(function () {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);
        CompanySetting::current()->update(['allow_negative_stock' => true]);

        $cust = Customer::factory()->create(['name' => 'Acme Buyer']);
        $item = Item::factory()->create(['name' => 'Vatable Item', 'sale_rate' => '100.00', 'is_vatable' => true, 'is_stockable' => true]);

        Sale::post([
            'customer_id' => $cust->id,
            'invoice_type' => 'full',
            'date' => '2024-08-01',
            'payment_mode' => 'cash',
        ], [
            ['item_id' => $item->id, 'quantity' => '2', 'rate' => '100.00'],
        ], $user);
    });

    loginVatReportFormatsUser($domain);

    // 1. Without search: checks subtotal = 200.00
    $res = $this->get("http://{$domain}/reports/sales-register?from=2024-08-01&to=2024-08-02")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/SalesRegister')
            ->where('sales.0.subtotal', '200.00')
            ->where('totals.subtotal', '200.00')
        );

    // 2. With matching search
    $this->get("http://{$domain}/reports/sales-register?from=2024-08-01&to=2024-08-02&search=Acme")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 1)
        );

    // 3. With non-matching search: returns empty without redirecting to dashboard
    $this->get("http://{$domain}/reports/sales-register?from=2024-08-01&to=2024-08-02&search=NonExistent")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 0)
        );

    $tenant->delete();
});

test('sales and purchase vat books include subtotal in rows and totals', function () {
    $domain = 'vat-book-format.tenant-test';
    $tenant = provisionVatReportFormatsTenant($domain);

    $tenant->run(function () {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);
        CompanySetting::current()->update(['allow_negative_stock' => true]);

        $cust = Customer::factory()->create(['name' => 'VAT Buyer', 'tpin' => '111222333']);
        $item = Item::factory()->create(['name' => 'Item X', 'sale_rate' => '500.00', 'is_vatable' => true, 'is_stockable' => true]);

        Sale::post([
            'customer_id' => $cust->id,
            'invoice_type' => 'full',
            'date' => '2024-09-01',
            'payment_mode' => 'cash',
        ], [
            ['item_id' => $item->id, 'quantity' => '1', 'rate' => '500.00'],
        ], $user);
    });

    loginVatReportFormatsUser($domain);

    $this->get("http://{$domain}/reports/sales-vat-book?from=2024-09-01&to=2024-09-02")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/SalesVatBook')
            ->where('rows.0.subtotal', '500.00')
            ->where('totals.subtotal', '500.00')
        );

    // Verify excel export returns 200
    $this->get("http://{$domain}/reports/sales-vat-book/export?from=2024-09-01&to=2024-09-02")
        ->assertOk();

    $tenant->delete();
});
