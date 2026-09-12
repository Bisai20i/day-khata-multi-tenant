<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucherLine;
use App\Models\StockAdjustment;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/**
 * Tenancy has no automatic "end of request" hook outside of a real PHP-FPM
 * style process boundary, so within a single test process the tenant
 * connection stays the default connection after an HTTP call. Revert to the
 * central connection after every test so RefreshDatabase's teardown rolls
 * back the connection it actually started a transaction on.
 */
afterEach(function () {
    tenancy()->end();
});

/**
 * Every stock document now resolves and guards its fiscal year by date
 * (CONTRACTS C4, audit P0-11), so the tenant needs one open year wide
 * enough to hold the dates these tests post on. firstOrCreate, so a test
 * that opens the tenant twice does not try to open a second year.
 */
function openingStockImportTestOpenFiscalYear(): FiscalYear
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

function provisionOpeningStockImportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsOpeningStockImportOwner(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('an authenticated user can bulk import opening stock from a csv file', function () {
    $domain = 'opening-stock-import-success.tenant-test';
    $tenant = provisionOpeningStockImportTestTenant($domain);

    $tenant->run(function () {
        openingStockImportTestOpenFiscalYear();
        User::factory()->create(['email' => 'owner@example.com']);
        Item::factory()->create(['name' => 'Bottled Water 1L', 'is_stockable' => true]);
        Item::factory()->create(['name' => 'Potato Chips 50g', 'is_stockable' => true]);
    });

    loginAsOpeningStockImportOwner($domain);

    $csv = "item,quantity,unit_cost_rate,remarks\n"
        ."Bottled Water 1L,100,15.00,Opening stock as of setup\n"
        ."Potato Chips 50g,50,,\n";
    $file = UploadedFile::fake()->createWithContent('opening-stock.csv', $csv);

    $response = $this->post("http://{$domain}/stock-adjustments/opening-stock/import", [
        'file' => $file,
        'date' => '2026-01-01',
    ]);

    $response->assertRedirect("http://{$domain}/stock-adjustments");
    $response->assertSessionHas('importResult', function (array $result) {
        return $result['imported'] === 2 && $result['skipped'] === [];
    });

    $tenant->run(function () {
        openingStockImportTestOpenFiscalYear();
        $water = Item::query()->where('name', 'Bottled Water 1L')->firstOrFail();
        $chips = Item::query()->where('name', 'Potato Chips 50g')->firstOrFail();

        // Same posting mechanism StockAdjustment::post() always uses:
        // exactly one 'opening' movement per item, picked up by
        // Item::currentStock().
        expect($water->currentStock()->toString())->toBe('100.0000')
            ->and($chips->currentStock()->toString())->toBe('50.0000');

        $waterMovement = ItemStockMovement::where('item_id', $water->id)->firstOrFail();
        expect($waterMovement->movement_type)->toBe(StockMovementType::Opening)
            ->and((float) $waterMovement->unit_cost_rate)->toBe(15.0);

        expect(StockAdjustment::query()->count())->toBe(1);
        expect(StockAdjustment::query()->first()->lines()->count())->toBe(2);
    });

    $tenant->delete();
});

test('opening stock import scopes to the given store and is picked up by currentStock($storeId)', function () {
    $domain = 'opening-stock-import-store-scope.tenant-test';
    $tenant = provisionOpeningStockImportTestTenant($domain);

    $tenant->run(function () {
        openingStockImportTestOpenFiscalYear();
        User::factory()->create(['email' => 'owner@example.com']);
        Item::factory()->create(['name' => 'Bottled Water 1L', 'is_stockable' => true]);
        Store::create(['name' => 'Branch Store', 'is_active' => true]);
    });

    loginAsOpeningStockImportOwner($domain);

    $branchStoreId = $tenant->run(fn () => Store::where('name', 'Branch Store')->value('id'));
    $mainStoreId = $tenant->run(fn () => Store::where('name', 'Main Store')->value('id'));

    $csv = "item,quantity,unit_cost_rate,remarks\nBottled Water 1L,40,10.00,\n";
    $file = UploadedFile::fake()->createWithContent('opening-stock.csv', $csv);

    $response = $this->post("http://{$domain}/stock-adjustments/opening-stock/import", [
        'file' => $file,
        'date' => '2026-01-01',
        'store_id' => $branchStoreId,
    ]);

    $response->assertRedirect("http://{$domain}/stock-adjustments");

    $tenant->run(function () use ($branchStoreId, $mainStoreId) {
        openingStockImportTestOpenFiscalYear();
        $water = Item::query()->where('name', 'Bottled Water 1L')->firstOrFail();

        expect($water->currentStock($branchStoreId)->toString())->toBe('40.0000')
            ->and($water->currentStock($mainStoreId)->toString())->toBe('0.0000')
            ->and($water->currentStock()->toString())->toBe('40.0000');
    });

    $tenant->delete();
});

test('bulk import skips invalid or unresolvable rows and reports why without importing them', function () {
    $domain = 'opening-stock-import-invalid.tenant-test';
    $tenant = provisionOpeningStockImportTestTenant($domain);

    $tenant->run(function () {
        openingStockImportTestOpenFiscalYear();
        User::factory()->create(['email' => 'owner@example.com']);
        Item::factory()->create(['name' => 'Good Item', 'is_stockable' => true]);
        Item::factory()->create(['name' => 'Non Stockable Item', 'is_stockable' => false]);
    });

    loginAsOpeningStockImportOwner($domain);

    $csv = "item,quantity,unit_cost_rate,remarks\n"
        // valid row.
        ."Good Item,25,5.00,\n"
        // unknown item.
        ."No Such Item,10,,\n"
        // not stockable.
        ."Non Stockable Item,10,,\n"
        // zero quantity.
        ."Good Item,0,,\n"
        // repeated item.
        ."Good Item,15,,\n";
    $file = UploadedFile::fake()->createWithContent('opening-stock.csv', $csv);

    $response = $this->post("http://{$domain}/stock-adjustments/opening-stock/import", [
        'file' => $file,
        'date' => '2026-01-01',
    ]);

    $response->assertRedirect("http://{$domain}/stock-adjustments");
    $response->assertSessionHas('importResult', function (array $result) {
        return $result['imported'] === 1 && count($result['skipped']) === 3;
    });

    $tenant->run(function () {
        openingStockImportTestOpenFiscalYear();
        $item = Item::query()->where('name', 'Good Item')->firstOrFail();
        expect($item->currentStock()->toString())->toBe('25.0000');

        $nonStockable = Item::query()->where('name', 'Non Stockable Item')->firstOrFail();
        expect($nonStockable->currentStock()->toString())->toBe('0.0000');
    });

    $tenant->delete();
});

test('guests cannot reach the opening stock import endpoints', function () {
    $domain = 'opening-stock-import-guest.tenant-test';
    $tenant = provisionOpeningStockImportTestTenant($domain);

    $tenant->run(function () {
        openingStockImportTestOpenFiscalYear();
        Item::factory()->create(['name' => 'Good Item', 'is_stockable' => true]);
    });

    $file = UploadedFile::fake()->createWithContent('opening-stock.csv', "item,quantity,unit_cost_rate,remarks\nGood Item,10,,\n");

    $this->post("http://{$domain}/stock-adjustments/opening-stock/import", ['file' => $file, 'date' => '2026-01-01'])
        ->assertRedirect("http://{$domain}/login");

    $this->get("http://{$domain}/stock-adjustments/opening-stock/template")
        ->assertRedirect("http://{$domain}/login");

    $tenant->run(function () {
        openingStockImportTestOpenFiscalYear();
        expect(StockAdjustment::query()->count())->toBe(0);
    });

    $tenant->delete();
});

/*
|--------------------------------------------------------------------------
| Re-importing replaces the previous batch (audit P1, P0-17)
|--------------------------------------------------------------------------
|
| A tenant who corrected one row of their opening-stock spreadsheet and
| re-uploaded used to end up with a second full set of opening quantities on
| top of the first - double the stock, with no way to tell which batch was
| which. The import now cancels the previous batch first, and posts the one
| ledger entry opening stock gets: Dr AS11 "Opening Stock" / Cr CA2 "Profit
| & Loss", the same equity account FiscalYear::close() sweeps retained
| earnings into and the one the opening-balance import deliberately leaves
| AS11 out of.
|
*/

test('re-importing opening stock replaces the previous batch instead of stacking on it', function () {
    // JournalVoucher::reverse() dates the reversal on today in Kathmandu
    // (CONTRACTS C4), so "today" has to sit inside the fiscal year this test
    // opens rather than wherever the clock happens to be.
    Carbon::setTestNow('2026-09-12 10:00:00');

    $domain = 'opening-stock-import-replace.tenant-test';
    $tenant = provisionOpeningStockImportTestTenant($domain);

    $tenant->run(function () {
        openingStockImportTestOpenFiscalYear();
        User::factory()->create(['email' => 'owner@example.com']);
        Item::factory()->create(['name' => 'Bottled Water 1L', 'is_stockable' => true]);
    });

    loginAsOpeningStockImportOwner($domain);

    $this->post("http://{$domain}/stock-adjustments/opening-stock/import", [
        'file' => UploadedFile::fake()->createWithContent(
            'opening-stock.csv',
            "item,quantity,unit_cost_rate,remarks\nBottled Water 1L,100,15.00,\n",
        ),
        'date' => '2026-01-01',
    ])->assertRedirect("http://{$domain}/stock-adjustments");

    $this->post("http://{$domain}/stock-adjustments/opening-stock/import", [
        'file' => UploadedFile::fake()->createWithContent(
            'opening-stock.csv',
            "item,quantity,unit_cost_rate,remarks\nBottled Water 1L,80,15.00,Corrected count\n",
        ),
        'date' => '2026-01-01',
    ])->assertRedirect("http://{$domain}/stock-adjustments");

    $tenant->run(function () {
        openingStockImportTestOpenFiscalYear();
        $water = Item::query()->where('name', 'Bottled Water 1L')->firstOrFail();

        // 80, never 180.
        expect($water->currentStock()->toString())->toBe('80.0000');

        $batches = StockAdjustment::query()->where('is_opening_import', true)->orderBy('id')->get();

        expect($batches)->toHaveCount(2)
            ->and($batches->first()->status)->toBe('cancelled')
            ->and($batches->last()->status)->toBe('posted');

        // Dr 1,500 - Cr 1,500 (the reversal) + Dr 1,200 = Dr 1,200 net. The
        // stock account matches the stock ledger exactly, which is the whole
        // point of posting it from here rather than from the opening-balance
        // import.
        $openingStockAccountId = Account::where('code', 'AS11')->value('id');
        $lines = JournalVoucherLine::where('account_id', $openingStockAccountId)->get();

        $debits = Money::sum($lines->map(fn (JournalVoucherLine $line) => Money::of($line->debit)));
        $credits = Money::sum($lines->map(fn (JournalVoucherLine $line) => Money::of($line->credit)));

        expect($debits->minus($credits)->toString())->toBe('1200.00');

        // One live ledger entry for the live batch, and the cancelled one
        // still points at the voucher that was reversed rather than losing
        // the audit trail.
        expect($batches->last()->journal_voucher_id)->not->toBeNull()
            ->and($batches->first()->journal_voucher_id)->not->toBeNull();
    });

    $tenant->delete();
    Carbon::setTestNow();
});

test('an opening stock import posts exactly one Dr AS11 / Cr Profit & Loss entry', function () {
    $domain = 'opening-stock-import-ledger.tenant-test';
    $tenant = provisionOpeningStockImportTestTenant($domain);

    $tenant->run(function () {
        openingStockImportTestOpenFiscalYear();
        User::factory()->create(['email' => 'owner@example.com']);
        Item::factory()->create(['name' => 'Bottled Water 1L', 'is_stockable' => true]);
        Item::factory()->create(['name' => 'Potato Chips 50g', 'is_stockable' => true]);
    });

    loginAsOpeningStockImportOwner($domain);

    $this->post("http://{$domain}/stock-adjustments/opening-stock/import", [
        'file' => UploadedFile::fake()->createWithContent(
            'opening-stock.csv',
            "item,quantity,unit_cost_rate,remarks\n"
            ."Bottled Water 1L,100,15.00,\n"
            ."Potato Chips 50g,50,4.00,\n",
        ),
        'date' => '2026-01-01',
    ])->assertRedirect("http://{$domain}/stock-adjustments");

    $tenant->run(function () {
        openingStockImportTestOpenFiscalYear();

        $adjustment = StockAdjustment::query()->where('is_opening_import', true)->firstOrFail();

        // 100 x 15.00 + 50 x 4.00.
        expect($adjustment->total_value)->toBe('1700.00')
            ->and($adjustment->journal_voucher_id)->not->toBeNull();

        $voucher = $adjustment->journalVoucher()->with('lines')->firstOrFail();
        $openingStockAccountId = Account::where('code', 'AS11')->value('id');
        $equityAccountId = Account::where('name', 'Profit & Loss')->value('id');

        expect($voucher->lines)->toHaveCount(2);

        $debit = $voucher->lines->firstWhere('account_id', $openingStockAccountId);
        $credit = $voucher->lines->firstWhere('account_id', $equityAccountId);

        expect($debit->debit)->toBe('1700.00')
            ->and($debit->credit)->toBe('0.00')
            ->and($credit->credit)->toBe('1700.00')
            ->and($credit->debit)->toBe('0.00');
    });

    $tenant->delete();
});
