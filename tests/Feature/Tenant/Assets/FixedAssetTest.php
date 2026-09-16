<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
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
        // An open year has to exist first, otherwise the fiscal-year guard
        // rejects the date before the payment-mode check is ever reached.
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

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
        expect($year1)->toBe(['posted' => 1, 'total' => '6300.00'])
            ->and((float) $asset->fresh()->accumulated_depreciation)->toBe(6300.0);

        // Calling it again for the same fiscal year is a no-op (the unique
        // constraint's guard, checked explicitly before ever posting).
        $again = FixedAsset::postDepreciationForFiscalYear($fy1, $actor);
        expect($again)->toBe(['posted' => 0, 'total' => '0.00']);

        $year2 = FixedAsset::postDepreciationForFiscalYear($fy2, $actor);
        expect($year2)->toBe(['posted' => 1, 'total' => '2700.00'])
            ->and((float) $asset->fresh()->accumulated_depreciation)->toBe(9000.0);

        $year3 = FixedAsset::postDepreciationForFiscalYear($fy3, $actor);
        expect($year3)->toBe(['posted' => 0, 'total' => '0.00']);

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
    // Every asset here carries a 0% rate on purpose, so the disposal-year
    // depreciation charge dispose() now runs first is zero and the gain/loss
    // arithmetic is the only thing under test. The charge itself has its own
    // test below.
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
            'cost' => 10000, 'depreciation_method' => 'wdv', 'depreciation_rate' => 0, 'payment_mode' => 'cash',
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
            'cost' => 10000, 'depreciation_method' => 'wdv', 'depreciation_rate' => 0, 'payment_mode' => 'cash',
        ], $actor);
        $lossAsset->update(['accumulated_depreciation' => 2000]);
        $lossAsset->dispose($actor, '2026-06-01', 1000, 'cash');

        $lossLines = $lossAsset->disposalJournalVoucher->lines->keyBy('account_id');
        expect((float) $lossLines[$lossAccount->id]->debit)->toBe(7000.0);

        // Break-even case: proceeds 0 + accumulated 10000 - cost 10000 = 0.
        $evenAsset = FixedAsset::post([
            'asset_name' => 'Even Asset', 'category' => 'Pool A', 'purchase_date' => '2026-01-01',
            'cost' => 10000, 'depreciation_method' => 'wdv', 'depreciation_rate' => 0, 'payment_mode' => 'cash',
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
        // FY1 has not reached its end date yet on the suite's clock, so the
        // close is an early one: it needs an admin and a written reason.
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
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

        $fy1->close($fy2, $actor, 'Closed early by the test fixture.');

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

        expect(FixedAsset::postDepreciationForFiscalYear($fy1, $actor))->toBe(['posted' => 0, 'total' => '0.00']);
        expect(JournalVoucher::where('voucher_type', VoucherType::Depreciation)->count())->toBe(0);
    });

    $tenant->delete();
});

test('an asset bought part way through the year is depreciated only for the days it was held', function () {
    // Audit P1: depreciation had no proration by acquisition date, so an
    // asset bought on the year's last day was charged a full year.
    $tenant = provisionFixedAssetTestTenant('fa-proration.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        // 365 days: 2026 is not a leap year.
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        // Bought on 2026-10-02, so held for 91 days of 365 (2 Oct to 31 Dec
        // inclusive). A full year at 20% WDV on 10,000 would be 2,000.00;
        // the prorated charge is round(2000 x 91 / 365) = 498.63.
        $asset = FixedAsset::post([
            'asset_name' => 'Late Arrival', 'category' => 'Pool C', 'purchase_date' => '2026-10-02',
            'cost' => 10000, 'depreciation_method' => 'wdv', 'depreciation_rate' => 20, 'payment_mode' => 'cash',
        ], $actor);

        $result = FixedAsset::postDepreciationForFiscalYear($fy1, $actor);

        expect($result)->toBe(['posted' => 1, 'total' => '498.63'])
            ->and($asset->fresh()->accumulated_depreciation)->toBe('498.63')
            ->and($asset->fresh()->wdv)->toBe('9501.37');

        $row = FixedAssetDepreciation::where('fixed_asset_id', $asset->id)->firstOrFail();
        expect($row->opening_wdv)->toBe('10000.00')
            ->and($row->depreciation_amount)->toBe('498.63')
            ->and($row->closing_wdv)->toBe('9501.37');
    });

    $tenant->delete();
});

test('an asset bought after the fiscal year ended is charged nothing for that year', function () {
    $tenant = provisionFixedAssetTestTenant('fa-not-held.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);

        FixedAsset::post([
            'asset_name' => 'Next Year Asset', 'category' => 'Pool C', 'purchase_date' => '2027-03-01',
            'cost' => 10000, 'depreciation_method' => 'wdv', 'depreciation_rate' => 20, 'payment_mode' => 'cash',
        ], $actor);

        expect(FixedAsset::postDepreciationForFiscalYear($fy1, $actor))->toBe(['posted' => 0, 'total' => '0.00']);
        expect(JournalVoucher::where('fiscal_year_id', $fy1->id)->where('voucher_type', VoucherType::Depreciation)->count())->toBe(0);

        // FY2 charges it from the purchase date only: 306 days of 365
        // (1 Mar to 31 Dec inclusive), round(2000 x 306 / 365) = 1676.71.
        expect(FixedAsset::postDepreciationForFiscalYear($fy2, $actor))->toBe(['posted' => 1, 'total' => '1676.71']);
    });

    $tenant->delete();
});

test('disposing an asset charges the depreciation it earned up to the disposal date first', function () {
    $tenant = provisionFixedAssetTestTenant('fa-dispose-proration.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        $asset = FixedAsset::post([
            'asset_name' => 'Sold Mid-Year', 'category' => 'Pool C', 'purchase_date' => '2026-01-01',
            'cost' => 10000, 'depreciation_method' => 'wdv', 'depreciation_rate' => 20, 'payment_mode' => 'cash',
        ], $actor);

        // Held 1 Jan to 30 Jun inclusive = 181 days of 365.
        // round(2000 x 181 / 365) = 991.78.
        $asset->dispose($actor, '2026-06-30', '9500.00', 'cash');

        $charge = FixedAssetDepreciation::where('fixed_asset_id', $asset->id)->firstOrFail();
        expect($charge->depreciation_amount)->toBe('991.78')
            ->and($charge->posted_date->toDateString())->toBe('2026-06-30');

        $fresh = $asset->fresh();
        expect($fresh->status)->toBe('disposed')
            ->and($fresh->accumulated_depreciation)->toBe('991.78');

        // Gain = proceeds 9500 + accumulated 991.78 - cost 10000 = 491.78.
        $gainAccount = Account::where('code', 'INI30')->firstOrFail();
        $lines = $fresh->disposalJournalVoucher->lines->keyBy('account_id');
        expect($lines[$gainAccount->id]->credit)->toBe('491.78');

        // The year-end run must not charge the same asset a second time.
        expect(FixedAsset::postDepreciationForFiscalYear($fy1, $actor))->toBe(['posted' => 0, 'total' => '0.00']);
        expect(FixedAssetDepreciation::where('fixed_asset_id', $asset->id)->count())->toBe(1);
    });

    $tenant->delete();
});

test('depreciation never takes an asset below its salvage value', function () {
    $tenant = provisionFixedAssetTestTenant('fa-salvage-floor.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        // Depreciable base = 500 (1000 cost - 500 salvage) but the rate
        // would charge 900, so the charge is capped at 500.
        $asset = FixedAsset::post([
            'asset_name' => 'Salvage Guard', 'category' => 'Pool C', 'purchase_date' => '2026-01-01',
            'cost' => 1000, 'salvage_value' => 500, 'depreciation_method' => 'slm',
            'depreciation_rate' => 90, 'payment_mode' => 'cash',
        ], $actor);

        expect(FixedAsset::postDepreciationForFiscalYear($fy1, $actor))->toBe(['posted' => 1, 'total' => '500.00'])
            ->and($asset->fresh()->wdv)->toBe('500.00');
    });

    $tenant->delete();
});

test('a double-clicked depreciation run posts one voucher and one row per asset per year', function () {
    // Audit P1: the run was not atomic, so a second click committed a
    // depreciation voucher and then failed on the fixed_asset_depreciations
    // unique index, leaving an orphan charge in the ledger.
    $tenant = provisionFixedAssetTestTenant('fa-double-run.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        $asset = FixedAsset::post([
            'asset_name' => 'Twice Clicked', 'category' => 'Pool C', 'purchase_date' => '2026-01-01',
            'cost' => 10000, 'depreciation_method' => 'wdv', 'depreciation_rate' => 20, 'payment_mode' => 'cash',
        ], $actor);

        FixedAsset::postDepreciationForFiscalYear($fy1, $actor);
        FixedAsset::postDepreciationForFiscalYear($fy1, $actor);

        expect(FixedAssetDepreciation::where('fixed_asset_id', $asset->id)->count())->toBe(1)
            ->and(JournalVoucher::where('fiscal_year_id', $fy1->id)->where('voucher_type', VoucherType::Depreciation)->count())->toBe(1)
            ->and($asset->fresh()->accumulated_depreciation)->toBe('2000.00');
    });

    $tenant->delete();
});

test('a fixed asset purchase with a VAT rate debits Vat Receivable and settles cost plus VAT (T14)', function () {
    $tenant = provisionFixedAssetTestTenant('fa-vat-on-purchase.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        $asset = FixedAsset::post([
            'asset_name' => 'Vatable Printer',
            'category' => 'Pool D',
            'purchase_date' => '2026-01-15',
            'cost' => 10000,
            'vat_rate' => 13,
            'salvage_value' => 0,
            'depreciation_method' => 'wdv',
            'depreciation_rate' => 25,
            'payment_mode' => 'cash',
        ], $actor);

        // 13% of 10000 = 1300 input VAT; the asset's own cost line stays net
        // of VAT (it is recoverable, not part of the depreciable base), and
        // the cash settlement carries both.
        expect($asset->vat_amount)->toBe('1300.00')
            ->and($asset->cost)->toBe('10000.00');

        $voucher = $asset->journalVoucher()->with('lines')->firstOrFail();
        $lines = $voucher->lines->keyBy('account_id');
        $vatReceivable = Account::where('code', 'ASA23')->firstOrFail();
        $cash = Account::where('code', 'AS1')->firstOrFail();

        expect($lines[$asset->account_id]->debit)->toBe('10000.00')
            ->and($lines[$vatReceivable->id]->debit)->toBe('1300.00')
            ->and($lines[$cash->id]->credit)->toBe('11300.00');
    });

    $tenant->delete();
});

test('a fixed asset purchase with no VAT rate posts no Vat Receivable line at all', function () {
    $tenant = provisionFixedAssetTestTenant('fa-no-vat-on-purchase.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        $asset = FixedAsset::post([
            'asset_name' => 'Plain Chair', 'category' => 'Pool E', 'purchase_date' => '2026-01-15',
            'cost' => 2000, 'depreciation_method' => 'slm', 'depreciation_rate' => 10, 'payment_mode' => 'cash',
        ], $actor);

        expect($asset->vat_amount)->toBe('0.00')
            ->and($asset->journalVoucher->lines()->count())->toBe(2);
    });

    $tenant->delete();
});

test('registering an existing asset posts against accumulated depreciation and the opening-balance equity account, with no cash movement', function () {
    $tenant = provisionFixedAssetTestTenant('fa-register-existing.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        $asset = FixedAsset::registerExisting([
            'asset_name' => 'Legacy Generator',
            'category' => 'Pool B',
            'purchase_date' => '2026-01-01',
            'cost' => 50000,
            'accumulated_depreciation' => 20000,
            'depreciation_method' => 'wdv',
            'depreciation_rate' => 25,
        ], $actor);

        expect($asset->cost)->toBe('50000.00')
            ->and($asset->accumulated_depreciation)->toBe('20000.00')
            ->and($asset->wdv)->toBe('30000.00');

        $voucher = $asset->journalVoucher()->with('lines')->firstOrFail();
        $lines = $voucher->lines->keyBy('account_id');
        $accumulatedDepreciation = Account::where('code', 'AS31')->firstOrFail();
        $equity = Account::where('code', 'CA2')->firstOrFail();
        $cash = Account::where('code', 'AS1')->firstOrFail();

        expect($lines[$asset->account_id]->debit)->toBe('50000.00')
            ->and($lines[$accumulatedDepreciation->id]->credit)->toBe('20000.00')
            ->and($lines[$equity->id]->credit)->toBe('30000.00')
            ->and($lines->has($cash->id))->toBeFalse();

        $totalDebit = Money::sum($voucher->lines->pluck('debit'));
        $totalCredit = Money::sum($voucher->lines->pluck('credit'));
        expect($totalDebit->isEqualTo($totalCredit))->toBeTrue();
    });

    $tenant->delete();
});

test('registering a fully depreciated existing asset posts no equity line, only accumulated depreciation', function () {
    $tenant = provisionFixedAssetTestTenant('fa-register-existing-fully-depreciated.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        $asset = FixedAsset::registerExisting([
            'asset_name' => 'Old Shelf',
            'category' => 'Pool E',
            'purchase_date' => '2026-01-01',
            'cost' => 5000,
            'accumulated_depreciation' => 5000,
            'depreciation_method' => 'slm',
            'depreciation_rate' => 10,
        ], $actor);

        $voucher = $asset->journalVoucher()->with('lines')->firstOrFail();
        expect($voucher->lines)->toHaveCount(2);
    });

    $tenant->delete();
});

test('registering an existing asset rejects accumulated depreciation greater than cost', function () {
    $tenant = provisionFixedAssetTestTenant('fa-register-existing-over-depreciated.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        expect(fn () => FixedAsset::registerExisting([
            'asset_name' => 'Broken Data', 'category' => 'Pool E', 'purchase_date' => '2026-01-01',
            'cost' => 1000, 'accumulated_depreciation' => 1500,
            'depreciation_method' => 'slm', 'depreciation_rate' => 10,
        ], $actor))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('an admin can register an existing asset through the HTTP route', function () {
    $domain = 'fa-register-existing-http.tenant-test';
    $tenant = provisionFixedAssetTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/fixed-assets/existing", [
        'asset_name' => 'HTTP Registered Asset',
        'category' => 'Pool B',
        'purchase_date' => '2026-01-01',
        'cost' => 8000,
        'accumulated_depreciation' => 1000,
        'depreciation_method' => 'wdv',
        'depreciation_rate' => 25,
    ])->assertRedirect("http://{$domain}/fixed-assets");

    $tenant->run(function () {
        expect(FixedAsset::where('asset_name', 'HTTP Registered Asset')->exists())->toBeTrue();
    });

    $tenant->delete();
});
