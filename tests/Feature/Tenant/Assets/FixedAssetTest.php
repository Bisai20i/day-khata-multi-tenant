<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionFixedAssetTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('posting a cash fixed asset purchase creates its own ledger account and a balanced voucher', function () {
    $tenant = provisionFixedAssetTestTenant('fa-post-cash.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        $asset = FixedAsset::post([
            'asset_name' => 'Office Laptop',
            'category' => 'Pool B',
            'purchase_date' => '2026-01-15',
            'cost' => 10000,
            'salvage_value' => 0,
            'depreciation_method' => 'wdv',
            'depreciation_rate' => 25,
            'payment_mode' => 'cash',
        ], $actor);

        expect($asset->asset_code)->toBe('FA-'.str_pad((string) $asset->id, 5, '0', STR_PAD_LEFT))
            ->and($asset->status)->toBe('active')
            ->and($asset->account)->not->toBeNull()
            ->and($asset->account->name)->toBe('Office Laptop')
            ->and($asset->account->group->name)->toBe('Fixed Assets');

        $voucher = $asset->journalVoucher()->with('lines')->firstOrFail();
        expect($voucher->voucher_type)->toBe(VoucherType::FixedAssetPurchase)
            ->and($voucher->lines)->toHaveCount(2);

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $lines = $voucher->lines->keyBy('account_id');

        expect((float) $lines[$asset->account_id]->debit)->toBe(10000.0)
            ->and((float) $lines[$cash->id]->credit)->toBe(10000.0);
    });

    $tenant->delete();
});

test('posting a credit fixed asset purchase credits the supplier ledger account', function () {
    $tenant = provisionFixedAssetTestTenant('fa-post-credit.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplier = Supplier::factory()->create();

        $asset = FixedAsset::post([
            'asset_name' => 'Delivery Van',
            'category' => 'Pool A',
            'purchase_date' => '2026-01-15',
            'cost' => 500000,
            'depreciation_method' => 'wdv',
            'depreciation_rate' => 5,
            'payment_mode' => 'credit',
            'supplier_id' => $supplier->id,
        ], $actor);

        $voucher = $asset->journalVoucher()->with('lines')->firstOrFail();
        $lines = $voucher->lines->keyBy('account_id');

        expect((float) $lines[$supplier->account_id]->credit)->toBe(500000.0);
    });

    $tenant->delete();
});

test('a bank or credit fixed asset purchase requires the matching account', function () {
    $tenant = provisionFixedAssetTestTenant('fa-post-validation.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();

        expect(fn () => FixedAsset::post([
            'asset_name' => 'Untethered Asset',
            'category' => 'Pool A',
            'purchase_date' => '2026-01-15',
            'cost' => 1000,
            'depreciation_method' => 'wdv',
            'depreciation_rate' => 5,
            'payment_mode' => 'bank',
        ], $actor))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('SLM depreciation is a flat percentage of the depreciable base, capped at what remains', function () {
    $tenant = provisionFixedAssetTestTenant('fa-slm.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fy3 = FiscalYear::create(['name' => 'FY3', 'start_date' => '2028-01-01', 'end_date' => '2028-12-31', 'status' => FiscalYearStatus::Closed]);

        // Depreciable base = 9000 (10000 cost - 1000 salvage). At 70% SLM
        // that's 6300/year - year 1 posts the full 6300, year 2 has only
        // 2700 left so posts a capped 2700, year 3 is fully depreciated.
        $asset = FixedAsset::post([
            'asset_name' => 'Heavy Machine',
            'category' => 'Pool C',
            'purchase_date' => '2026-01-01',
            'cost' => 10000,
            'salvage_value' => 1000,
            'depreciation_method' => 'slm',
            'depreciation_rate' => 70,
            'payment_mode' => 'cash',
        ], $actor);

        $year1 = FixedAsset::postDepreciationForFiscalYear($fy1, $actor);
        expect($year1)->toBe(['posted' => 1, 'total' => 6300.0])
            ->and((float) $asset->fresh()->accumulated_depreciation)->toBe(6300.0);

        // Calling it again for the same fiscal year is a no-op (the unique
        // constraint's guard, checked explicitly before ever posting).
        $again = FixedAsset::postDepreciationForFiscalYear($fy1, $actor);
        expect($again)->toBe(['posted' => 0, 'total' => 0.0]);

        $year2 = FixedAsset::postDepreciationForFiscalYear($fy2, $actor);
        expect($year2)->toBe(['posted' => 1, 'total' => 2700.0])
            ->and((float) $asset->fresh()->accumulated_depreciation)->toBe(9000.0);

        $year3 = FixedAsset::postDepreciationForFiscalYear($fy3, $actor);
        expect($year3)->toBe(['posted' => 0, 'total' => 0.0]);

        expect(FixedAssetDepreciation::where('fixed_asset_id', $asset->id)->count())->toBe(2);

        $depreciationExpense = Account::where('code', 'EXE20')->firstOrFail();
        $accumulatedDepreciation = Account::where('code', 'AS31')->firstOrFail();

        $voucher = JournalVoucher::where('fiscal_year_id', $fy1->id)
            ->where('voucher_type', VoucherType::Depreciation)
            ->with('lines')
            ->firstOrFail();
        $lines = $voucher->lines->keyBy('account_id');

        expect((float) $lines[$depreciationExpense->id]->debit)->toBe(6300.0)
            ->and((float) $lines[$accumulatedDepreciation->id]->credit)->toBe(6300.0);
    });

    $tenant->delete();
});

test('WDV depreciation is a percentage of the opening written-down value each year', function () {
    $tenant = provisionFixedAssetTestTenant('fa-wdv.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);

        $asset = FixedAsset::post([
            'asset_name' => 'Server Rack',
            'category' => 'Pool D',
            'purchase_date' => '2026-01-01',
            'cost' => 10000,
            'depreciation_method' => 'wdv',
            'depreciation_rate' => 20,
            'payment_mode' => 'cash',
        ], $actor);

        FixedAsset::postDepreciationForFiscalYear($fy1, $actor);
        expect((float) $asset->fresh()->accumulated_depreciation)->toBe(2000.0); // 10000 * 20%

        FixedAsset::postDepreciationForFiscalYear($fy2, $actor);
        expect((float) $asset->fresh()->accumulated_depreciation)->toBe(3600.0); // 2000 + (8000 * 20%)

        $depreciations = FixedAssetDepreciation::where('fixed_asset_id', $asset->id)->orderBy('fiscal_year_id')->get();
        expect((float) $depreciations[0]->opening_wdv)->toBe(10000.0)
            ->and((float) $depreciations[0]->depreciation_amount)->toBe(2000.0)
            ->and((float) $depreciations[0]->closing_wdv)->toBe(8000.0)
            ->and((float) $depreciations[1]->opening_wdv)->toBe(8000.0)
            ->and((float) $depreciations[1]->depreciation_amount)->toBe(1600.0)
            ->and((float) $depreciations[1]->closing_wdv)->toBe(6400.0);
    });

    $tenant->delete();
});

test('disposing an asset posts a gain, a loss, or neither depending on proceeds plus accumulated depreciation versus cost', function () {
    $tenant = provisionFixedAssetTestTenant('fa-dispose.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $cash = Account::where('code', 'AS1')->firstOrFail();
        $lossAccount = Account::where('code', 'EXE21')->firstOrFail();
        $gainAccount = Account::where('code', 'INI30')->firstOrFail();
        $accumulatedDepreciationAccount = Account::where('code', 'AS31')->firstOrFail();

        // Gain case: proceeds 5000 + accumulated 6000 - cost 10000 = +1000 gain.
        $gainAsset = FixedAsset::post([
            'asset_name' => 'Gain Asset', 'category' => 'Pool A', 'purchase_date' => '2026-01-01',
            'cost' => 10000, 'depreciation_method' => 'wdv', 'depreciation_rate' => 5, 'payment_mode' => 'cash',
        ], $actor);
        $gainAsset->update(['accumulated_depreciation' => 6000]);
        $gainAsset->dispose($actor, '2026-06-01', 5000, 'cash');

        $gainLines = $gainAsset->disposalJournalVoucher->lines->keyBy('account_id');
        expect($gainAsset->fresh()->status)->toBe('disposed')
            ->and((float) $gainLines[$accumulatedDepreciationAccount->id]->debit)->toBe(6000.0)
            ->and((float) $gainLines[$gainAccount->id]->credit)->toBe(1000.0)
            ->and((float) $gainLines[$gainAsset->account_id]->credit)->toBe(10000.0);

        // Loss case: proceeds 1000 + accumulated 2000 - cost 10000 = -7000 loss.
        $lossAsset = FixedAsset::post([
            'asset_name' => 'Loss Asset', 'category' => 'Pool A', 'purchase_date' => '2026-01-01',
            'cost' => 10000, 'depreciation_method' => 'wdv', 'depreciation_rate' => 5, 'payment_mode' => 'cash',
        ], $actor);
        $lossAsset->update(['accumulated_depreciation' => 2000]);
        $lossAsset->dispose($actor, '2026-06-01', 1000, 'cash');

        $lossLines = $lossAsset->disposalJournalVoucher->lines->keyBy('account_id');
        expect((float) $lossLines[$lossAccount->id]->debit)->toBe(7000.0);

        // Break-even case: proceeds 0 + accumulated 10000 - cost 10000 = 0.
        $evenAsset = FixedAsset::post([
            'asset_name' => 'Even Asset', 'category' => 'Pool A', 'purchase_date' => '2026-01-01',
            'cost' => 10000, 'depreciation_method' => 'wdv', 'depreciation_rate' => 5, 'payment_mode' => 'cash',
        ], $actor);
        $evenAsset->update(['accumulated_depreciation' => 10000]);
        $evenAsset->dispose($actor, '2026-06-01', 0, 'cash');

        $evenLines = $evenAsset->disposalJournalVoucher->lines;
        expect($evenLines)->toHaveCount(2)
            ->and($evenLines->contains(fn ($l) => $l->account_id === $gainAccount->id))->toBeFalse()
            ->and($evenLines->contains(fn ($l) => $l->account_id === $lossAccount->id))->toBeFalse();

        expect(fn () => $evenAsset->dispose($actor, '2026-06-02', 0, 'cash'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('FiscalYear::close() posts each active asset\'s depreciation before sweeping profit-and-loss accounts', function () {
    $tenant = provisionFixedAssetTestTenant('fa-fy-close.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $sales = Account::where('code', 'INI20')->firstOrFail();
        $pl = Account::where('name', 'Profit & Loss')->firstOrFail();

        // A cash sale of 5000, and one asset whose SLM depreciation for the
        // year is exactly 1000 (10000 cost * 10%).
        JournalVoucher::post(
            ['date' => '2026-03-01', 'narration' => 'Cash sale'],
            [
                ['account_id' => $cash->id, 'debit' => 5000, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 5000],
            ],
            $actor,
        );

        FixedAsset::post([
            'asset_name' => 'Depreciating Asset', 'category' => 'Pool A', 'purchase_date' => '2026-01-01',
            'cost' => 10000, 'depreciation_method' => 'slm', 'depreciation_rate' => 10, 'payment_mode' => 'cash',
        ], $actor);

        $fy1->close($fy2, $actor);

        $depreciationVoucher = JournalVoucher::where('fiscal_year_id', $fy1->id)
            ->where('voucher_type', VoucherType::Depreciation)
            ->first();
        expect($depreciationVoucher)->not->toBeNull();

        // Net profit = 5000 sale - 1000 depreciation = 4000, credited to
        // Profit & Loss within FY1's own closing entries.
        $plNet = JournalVoucherLine::query()
            ->where('account_id', $pl->id)
            ->whereHas('journalVoucher', fn ($q) => $q->where('fiscal_year_id', $fy1->id))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');

        expect((float) $plNet)->toBe(-4000.0);
    });

    $tenant->delete();
});

test('a tenant with zero fixed assets closes its fiscal year with no depreciation posted', function () {
    $tenant = provisionFixedAssetTestTenant('fa-none.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        expect(FixedAsset::postDepreciationForFiscalYear($fy1, $actor))->toBe(['posted' => 0, 'total' => 0.0]);
        expect(JournalVoucher::where('voucher_type', VoucherType::Depreciation)->count())->toBe(0);
    });

    $tenant->delete();
});
