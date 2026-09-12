<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\StockConversion;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/*
|--------------------------------------------------------------------------
| Stock documents and the fiscal year guard (CONTRACTS C4, audit P0-11)
|--------------------------------------------------------------------------
|
| Stock adjustments, transfers and conversions never post a journal voucher
| (inventory stays periodic in this app), so they never passed through
| JournalVoucher::post()'s fiscal-year check either. A quantity movement
| could therefore be dated into a closed, already-filed year and silently
| restate the stock position that year's VAT return and Balance Sheet were
| built on. Each of the three now calls
| ClosedFiscalYearGuard::assertDateInOpenYear() itself.
|
*/

function provisionStockGuardTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function stockGuardActor(): User
{
    return User::factory()->create();
}

/**
 * A closed 2025 beside an open 2026, so a document dated in 2025 lands in a
 * year that exists but is shut rather than in no year at all - the two
 * failures the guard reports differently.
 */
function stockGuardFiscalYears(): void
{
    FiscalYear::firstOrCreate(
        ['name' => '2025'],
        ['start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => FiscalYearStatus::Closed],
    );

    FiscalYear::firstOrCreate(
        ['name' => '2026'],
        ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open],
    );
}

test('a stock adjustment dated in a closed fiscal year is rejected', function () {
    $tenant = provisionStockGuardTenant('stock-guard-adjustment-closed.tenant-test');

    $tenant->run(function () {
        stockGuardFiscalYears();
        $actor = stockGuardActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        expect(fn () => StockAdjustment::post(
            ['date' => '2025-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '5', 'unit_cost_rate' => '10']],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect(StockAdjustment::count())->toBe(0)
            ->and($item->fresh()->currentStock()->toString())->toBe('0.0000');
    });

    $tenant->delete();
});

test('a stock adjustment dated outside every fiscal year is rejected', function () {
    $tenant = provisionStockGuardTenant('stock-guard-adjustment-no-year.tenant-test');

    $tenant->run(function () {
        stockGuardFiscalYears();
        $actor = stockGuardActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        expect(fn () => StockAdjustment::post(
            ['date' => '2030-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '5']],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect(StockAdjustment::count())->toBe(0);
    });

    $tenant->delete();
});

test('a stock transfer dated in a closed fiscal year is rejected', function () {
    $tenant = provisionStockGuardTenant('stock-guard-transfer-closed.tenant-test');

    $tenant->run(function () {
        stockGuardFiscalYears();
        $actor = stockGuardActor();
        $item = Item::factory()->create(['is_stockable' => true]);
        $source = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $destination = Store::factory()->create(['is_active' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $source->id],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '10', 'unit_cost_rate' => '10']],
            $actor,
        );

        expect(fn () => StockTransfer::post(
            ['date' => '2025-06-01', 'from_store_id' => $source->id, 'to_store_id' => $destination->id],
            [['item_id' => $item->id, 'quantity' => '4']],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect(StockTransfer::count())->toBe(0)
            ->and($item->fresh()->currentStock($source->id)->toString())->toBe('10.0000');
    });

    $tenant->delete();
});

test('a stock conversion dated in a closed fiscal year is rejected', function () {
    $tenant = provisionStockGuardTenant('stock-guard-conversion-closed.tenant-test');

    $tenant->run(function () {
        stockGuardFiscalYears();
        $actor = stockGuardActor();
        $raw = Item::factory()->create(['is_stockable' => true]);
        $finished = Item::factory()->create(['is_stockable' => true]);
        $store = Store::where('is_active', true)->orderBy('id')->firstOrFail();

        StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $store->id],
            [['item_id' => $raw->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '10', 'unit_cost_rate' => '10']],
            $actor,
        );

        expect(fn () => StockConversion::post(
            ['type' => 'production', 'date' => '2025-06-01', 'store_id' => $store->id],
            [['item_id' => $raw->id, 'quantity' => '5']],
            [['item_id' => $finished->id, 'quantity' => '5']],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect(StockConversion::count())->toBe(0)
            ->and($raw->fresh()->currentStock($store->id)->toString())->toBe('10.0000');
    });

    $tenant->delete();
});

test('a stock document cannot be cancelled once its fiscal year has closed', function () {
    $tenant = provisionStockGuardTenant('stock-guard-cancel-closed.tenant-test');

    $tenant->run(function () {
        stockGuardFiscalYears();
        $actor = stockGuardActor();
        $item = Item::factory()->create(['is_stockable' => true]);

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '5', 'unit_cost_rate' => '10']],
            $actor,
        );

        // The year is shut straight on the row rather than through
        // FiscalYear::close(): this test is about the guard in cancel(), not
        // about the closing entries.
        FiscalYear::where('name', '2026')->update(['status' => FiscalYearStatus::Closed->value]);

        expect(fn () => $adjustment->cancel($actor, 'Recorded in error'))
            ->toThrow(InvalidArgumentException::class);

        expect($adjustment->fresh()->status)->toBe('posted')
            ->and($item->fresh()->currentStock()->toString())->toBe('5.0000');
    });

    $tenant->delete();
});
