<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * Every stock document now resolves and guards its fiscal year by date
 * (CONTRACTS C4, audit P0-11), so the tenant needs one open year wide
 * enough to hold the dates these tests post on. firstOrCreate, so a test
 * that opens the tenant twice does not try to open a second year.
 */
function stockAdjustmentPrintTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::firstOrCreate(
        ['name' => '2026'],
        [
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open,
        ],
    );
}

function provisionStockAdjustmentPrintTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginStockAdjustmentPrintTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the stock adjustment print route returns a streamed PDF for an authenticated user', function () {
    $domain = 'stock-adjustment-print-http.tenant-test';
    $tenant = provisionStockAdjustmentPrintTestTenant($domain);

    $adjustmentId = null;
    $tenant->run(function () use (&$adjustmentId) {
        stockAdjustmentPrintTestOpenFiscalYear();
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['is_stockable' => true]);

        $adjustmentId = StockAdjustment::post(
            ['date' => '2026-06-01', 'note' => 'Damaged in storage'],
            [['item_id' => $item->id, 'direction' => 'out', 'reason_type' => 'damage', 'quantity' => 2]],
            $admin,
        )->id;
    });

    loginStockAdjustmentPrintTestUser($domain);

    $this->get("http://{$domain}/stock-adjustments/{$adjustmentId}/print")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $tenant->delete();
});

test('the stock adjustment print route is rejected for an unauthenticated request', function () {
    $domain = 'stock-adjustment-print-guest.tenant-test';
    $tenant = provisionStockAdjustmentPrintTestTenant($domain);

    $adjustmentId = null;
    $tenant->run(function () use (&$adjustmentId) {
        stockAdjustmentPrintTestOpenFiscalYear();
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['is_stockable' => true]);

        $adjustmentId = StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => 1]],
            $admin,
        )->id;
    });

    $this->get("http://{$domain}/stock-adjustments/{$adjustmentId}/print")
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});
