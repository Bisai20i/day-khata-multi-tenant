<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\StockTransfer;
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
function stockTransferPrintTestOpenFiscalYear(): FiscalYear
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

function provisionStockTransferPrintTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginStockTransferPrintTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the stock transfer print route returns a streamed PDF for an authenticated user', function () {
    $domain = 'stock-transfer-print-http.tenant-test';
    $tenant = provisionStockTransferPrintTestTenant($domain);

    $transferId = null;
    $tenant->run(function () use (&$transferId) {
        stockTransferPrintTestOpenFiscalYear();
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['is_stockable' => true]);
        $fromStore = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $toStore = Store::factory()->create(['is_active' => true]);

        $item->recordStockMovement(StockMovementType::Opening, 10, '2026-06-01', $fromStore->id);

        $transferId = StockTransfer::post(
            ['date' => '2026-06-02', 'from_store_id' => $fromStore->id, 'to_store_id' => $toStore->id, 'note' => 'Rebalance stock'],
            [['item_id' => $item->id, 'quantity' => 4]],
            $admin,
        )->id;
    });

    loginStockTransferPrintTestUser($domain);

    $this->get("http://{$domain}/stock-transfers/{$transferId}/print")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $tenant->delete();
});

/**
 * Every printable document has to log its print (CONTRACTS C9) - a stock
 * transfer's print route was silently skipping PrintLog::record(), the same
 * gap PurchasePrintTest and QuotationPrintTest already guard for their own
 * documents.
 */
test('printing a stock transfer records a PrintLog row', function () {
    $domain = 'stock-transfer-print-log.tenant-test';
    $tenant = provisionStockTransferPrintTestTenant($domain);

    $transferId = null;
    $tenant->run(function () use (&$transferId) {
        stockTransferPrintTestOpenFiscalYear();
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['is_stockable' => true]);
        $fromStore = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $toStore = Store::factory()->create(['is_active' => true]);

        $item->recordStockMovement(StockMovementType::Opening, 10, '2026-06-01', $fromStore->id);

        $transferId = StockTransfer::post(
            ['date' => '2026-06-02', 'from_store_id' => $fromStore->id, 'to_store_id' => $toStore->id, 'note' => 'Rebalance stock'],
            [['item_id' => $item->id, 'quantity' => 4]],
            $admin,
        )->id;
    });

    loginStockTransferPrintTestUser($domain);

    $this->get("http://{$domain}/stock-transfers/{$transferId}/print")->assertOk();
    $this->get("http://{$domain}/stock-transfers/{$transferId}/print")->assertOk();

    $tenant->run(function () use ($transferId) {
        expect(PrintLog::where('printable_type', (new StockTransfer)->getMorphClass())
            ->where('printable_id', $transferId)
            ->count())->toBe(2);
    });

    $tenant->delete();
});

test('the stock transfer print route is rejected for an unauthenticated request', function () {
    $domain = 'stock-transfer-print-guest.tenant-test';
    $tenant = provisionStockTransferPrintTestTenant($domain);

    $transferId = null;
    $tenant->run(function () use (&$transferId) {
        stockTransferPrintTestOpenFiscalYear();
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $item = Item::factory()->create(['is_stockable' => true]);
        $fromStore = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $toStore = Store::factory()->create(['is_active' => true]);

        $item->recordStockMovement(StockMovementType::Opening, 5, '2026-06-01', $fromStore->id);

        $transferId = StockTransfer::post(
            ['date' => '2026-06-02', 'from_store_id' => $fromStore->id, 'to_store_id' => $toStore->id],
            [['item_id' => $item->id, 'quantity' => 1]],
            $admin,
        )->id;
    });

    $this->get("http://{$domain}/stock-transfers/{$transferId}/print")
        ->assertRedirect("http://{$domain}/login");

    $tenant->delete();
});
