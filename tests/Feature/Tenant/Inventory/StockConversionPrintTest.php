<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\StockConversion;
use App\Models\Store;
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
function stockConversionPrintTestOpenFiscalYear(): FiscalYear
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

function provisionStockConversionPrintTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginStockConversionPrintTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the stock conversion print route returns a streamed PDF for a repackaging entry', function () {
    $domain = 'stock-conversion-print-repackaging.tenant-test';
    $tenant = provisionStockConversionPrintTestTenant($domain);

    $conversionId = null;
    $tenant->run(function () use (&$conversionId) {
        stockConversionPrintTestOpenFiscalYear();
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $bulk = Item::factory()->create(['is_stockable' => true, 'name' => 'Bulk Sack']);
        $retail = Item::factory()->create(['is_stockable' => true, 'name' => 'Retail Bag']);
        $store = Store::where('is_active', true)->orderBy('id')->firstOrFail();

        $bulk->recordStockMovement(StockMovementType::Opening, 10, '2026-06-01', $store->id);

        $conversionId = StockConversion::post(
            ['type' => 'repackaging', 'date' => '2026-06-02', 'note' => 'Split into retail bags'],
            [['item_id' => $bulk->id, 'quantity' => 10]],
            [['item_id' => $retail->id, 'quantity' => 8]],
            $admin,
        )->id;
    });

    loginStockConversionPrintTestUser($domain);

    $this->get("http://{$domain}/stock-conversions/{$conversionId}/print")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $tenant->delete();
});

test('the stock conversion print route is rejected for an unauthenticated request', function () {
    $domain = 'stock-conversion-print-guest.tenant-test';
    $tenant = provisionStockConversionPrintTestTenant($domain);

    $conversionId = null;
    $tenant->run(function () use (&$conversionId) {
        stockConversionPrintTestOpenFiscalYear();
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $raw = Item::factory()->create(['is_stockable' => true]);
        $finished = Item::factory()->create(['is_stockable' => true]);
        $store = Store::where('is_active', true)->orderBy('id')->firstOrFail();

        $raw->recordStockMovement(StockMovementType::Opening, 10, '2026-06-01', $store->id);

        $conversionId = StockConversion::post(
            ['type' => 'production', 'date' => '2026-06-02'],
            [['item_id' => $raw->id, 'quantity' => 10]],
            [['item_id' => $finished->id, 'quantity' => 5]],
            $admin,
        )->id;
    });

    $this->get("http://{$domain}/stock-conversions/{$conversionId}/print")
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});
