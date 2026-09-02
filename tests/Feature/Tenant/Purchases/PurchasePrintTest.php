<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
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
