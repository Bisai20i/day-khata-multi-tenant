<?php

use App\Enums\FiscalYearStatus;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Tenant;
use App\Models\User;
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
