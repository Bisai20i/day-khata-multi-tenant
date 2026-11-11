<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\MaterialIssueNote;
use App\Models\ProductionEntry;
use App\Models\StockAdjustment;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Manufacturing\ManufacturingAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Per-branch inventory reconciliation gates the year end (plan 12, I8)
|--------------------------------------------------------------------------
|
| FiscalYear::close() and relock() run the company-wide perpetual check
| first and then, when the company has more than one branch,
| InventoryReconciliation::assertBranchesReconciled(). A clean multi-branch
| year closes; a posting filed in the wrong branch, which the company-wide
| check cannot see, refuses the close or relock and leaves the year as it
| was. A single-branch company closes exactly as before.
|
| FY1 ends 2026-12-31, after the suite's clock, so every close is an early
| close: the owner (User::factory() with no role) plus a written reason.
|
*/

uses(RefreshDatabase::class);

afterEach(function () {
    auth()->forgetGuards();
    tenancy()->end();
});

const BRANCH_YEAR_CLOSE_REASON = 'Closed early by the branch inventory close test.';

function provisionBranchYearCloseTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * FY1 open, FY2 created closed and empty, the shape close() expects.
 *
 * @return array{0: FiscalYear, 1: FiscalYear}
 */
function branchYearCloseYears(): array
{
    return [
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]),
        FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]),
    ];
}

/**
 * A complete production run in $store through the real documents: opening
 * raw material 100 @ 50 (5,000.00), 40 issued (2,000.00), a final
 * completion of 10 finished units (2,000.00). Closing: raw material
 * 3,000.00, WIP 0.00, finished goods 2,000.00, all in $store's branch.
 *
 * @return array{note: MaterialIssueNote, entry: ProductionEntry}
 */
function branchYearCloseProductionRun(Store $store, User $actor): array
{
    $flour = Item::factory()->rawMaterial()->create();
    $bread = Item::factory()->finishedGood()->create();

    StockAdjustment::post(
        ['date' => '2026-06-01', 'store_id' => $store->id],
        [['item_id' => $flour->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => '100', 'unit_cost_rate' => '50']],
        $actor,
    );

    $note = MaterialIssueNote::post(['date' => '2026-06-02', 'store_id' => $store->id], [['item_id' => $flour->id, 'quantity' => '40']], $actor);
    $entry = ProductionEntry::create(
        ['date' => '2026-06-02', 'store_id' => $store->id, 'finished_item_id' => $bread->id, 'planned_quantity' => '10'],
        [$note->id],
        $actor,
    );
    $entry->complete('2026-06-03', '10', true, $actor);

    return ['note' => $note, 'entry' => $entry];
}

/**
 * Re-files the whole issue voucher of $note under Main. It stays balanced in
 * Main and the company-wide figures do not move, but the issuing branch's
 * AS12 / AS13 no longer match its own stores and WIP rows.
 */
function branchYearCloseMisfileIntoMain(MaterialIssueNote $note): void
{
    DB::table('journal_voucher_lines')
        ->where('journal_voucher_id', $note->journal_voucher_id)
        ->update(['branch_id' => Branch::main()->id]);
}

/**
 * A branch's single opening line for $code in $year's opening voucher.
 */
function branchYearCloseOpeningLine(FiscalYear $year, string $code, int $branchId): ?JournalVoucherLine
{
    return JournalVoucherLine::query()
        ->where('account_id', Account::where('code', $code)->value('id'))
        ->where('branch_id', $branchId)
        ->whereHas('journalVoucher', fn ($query) => $query
            ->where('fiscal_year_id', $year->id)
            ->where('voucher_type', VoucherType::OpeningBalance->value))
        ->first();
}

test('a clean two-branch year with perpetual documents in Pokhara closes, carrying each branch\'s stock forward', function () {
    $tenant = provisionBranchYearCloseTenant('br-year-close-clean.tenant-test');

    $tenant->run(function () {
        [$fy1, $fy2] = branchYearCloseYears();
        $owner = User::factory()->create();
        $pokhara = Branch::factory()->create(['name' => 'Pokhara']);
        $pokharaStore = Store::factory()->create(['name' => 'Pokhara Store', 'branch_id' => $pokhara->id]);

        branchYearCloseProductionRun($pokharaStore, $owner);

        $fy1->close($fy2, $owner, BRANCH_YEAR_CLOSE_REASON);

        expect($fy1->fresh()->status)->toBe(FiscalYearStatus::Closed)
            ->and($fy2->fresh()->status)->toBe(FiscalYearStatus::Open)
            ->and(branchYearCloseOpeningLine($fy2, ManufacturingAccounts::RAW_MATERIAL, $pokhara->id)?->debit)->toBe('3000.00')
            ->and(branchYearCloseOpeningLine($fy2, ManufacturingAccounts::FINISHED_GOODS, $pokhara->id)?->debit)->toBe('2000.00')
            ->and(branchYearCloseOpeningLine($fy2, ManufacturingAccounts::RAW_MATERIAL, Branch::main()->id))->toBeNull();
    });

    $tenant->delete();
});

test('a posting filed in the wrong branch refuses the close, naming the branch and class, and the year stays open', function () {
    $tenant = provisionBranchYearCloseTenant('br-year-close-misfiled.tenant-test');

    $tenant->run(function () {
        [$fy1, $fy2] = branchYearCloseYears();
        $owner = User::factory()->create();
        $pokhara = Branch::factory()->create(['name' => 'Pokhara']);
        $pokharaStore = Store::factory()->create(['name' => 'Pokhara Store', 'branch_id' => $pokhara->id]);

        ['note' => $note] = branchYearCloseProductionRun($pokharaStore, $owner);
        branchYearCloseMisfileIntoMain($note);

        $vouchersBefore = JournalVoucher::query()->count();

        expect(fn () => $fy1->close($fy2, $owner, BRANCH_YEAR_CLOSE_REASON))
            ->toThrow(InvalidArgumentException::class, 'Pokhara, Raw Material');

        expect($fy1->fresh()->status)->toBe(FiscalYearStatus::Open)
            ->and($fy1->fresh()->closed_at)->toBeNull()
            ->and($fy2->fresh()->status)->toBe(FiscalYearStatus::Closed)
            ->and(JournalVoucher::query()->count())->toBe($vouchersBefore)
            ->and(JournalVoucher::query()->where('fiscal_year_id', $fy2->id)->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('a correction filed in the wrong branch refuses the relock and the year stays reopened', function () {
    $tenant = provisionBranchYearCloseTenant('br-year-relock-misfiled.tenant-test');

    $tenant->run(function () {
        [$fy1, $fy2] = branchYearCloseYears();
        $owner = User::factory()->create();
        $pokhara = Branch::factory()->create(['name' => 'Pokhara']);
        $pokharaStore = Store::factory()->create(['name' => 'Pokhara Store', 'branch_id' => $pokhara->id]);
        $flour = Item::factory()->rawMaterial()->create();

        branchYearCloseProductionRun($pokharaStore, $owner);
        $fy1->close($fy2, $owner, BRANCH_YEAR_CLOSE_REASON);

        $fy1 = $fy1->fresh();
        $fy1->reopen($owner, 'Missed a raw material purchase.');

        // The correction's stock arrives in a Pokhara store, but its voucher
        // is filed under Main: company-wide both sides rise by 100.00.
        $flour->recordStockMovement(StockMovementType::Purchase, '20', '2026-12-20', $pokharaStore->id, null, '5.0000', '100.00', costValue: '100.00');
        JournalVoucher::post(
            ['date' => '2026-12-20', 'narration' => 'Late raw material bill', 'fiscal_year_id' => $fy1->id, 'reason' => 'Correction posted by the branch close test.', 'branch_id' => Branch::main()->id],
            [
                ['account_id' => Account::where('code', ManufacturingAccounts::RAW_MATERIAL)->value('id'), 'debit' => '100.00', 'credit' => '0.00'],
                ['account_id' => Account::where('code', 'AS1')->value('id'), 'debit' => '0.00', 'credit' => '100.00'],
            ],
            $owner,
        );

        expect(fn () => $fy1->fresh()->relock($owner))
            ->toThrow(InvalidArgumentException::class, 'Pokhara, Raw Material');

        $fy1 = $fy1->fresh();

        expect($fy1->isOpenForCorrection())->toBeTrue()
            ->and($fy1->relocked_at)->toBeNull()
            ->and($fy1->status)->toBe(FiscalYearStatus::Closed);
    });

    $tenant->delete();
});

test('a clean correction in the right branch relocks in a two-branch company', function () {
    $tenant = provisionBranchYearCloseTenant('br-year-relock-clean.tenant-test');

    $tenant->run(function () {
        [$fy1, $fy2] = branchYearCloseYears();
        $owner = User::factory()->create();
        $pokhara = Branch::factory()->create(['name' => 'Pokhara']);
        $pokharaStore = Store::factory()->create(['name' => 'Pokhara Store', 'branch_id' => $pokhara->id]);
        $flour = Item::factory()->rawMaterial()->create();

        branchYearCloseProductionRun($pokharaStore, $owner);
        $fy1->close($fy2, $owner, BRANCH_YEAR_CLOSE_REASON);

        $fy1 = $fy1->fresh();
        $fy1->reopen($owner, 'Missed a raw material purchase.');

        $flour->recordStockMovement(StockMovementType::Purchase, '20', '2026-12-20', $pokharaStore->id, null, '5.0000', '100.00', costValue: '100.00');
        JournalVoucher::post(
            ['date' => '2026-12-20', 'narration' => 'Late raw material bill', 'fiscal_year_id' => $fy1->id, 'reason' => 'Correction posted by the branch close test.', 'branch_id' => $pokhara->id],
            [
                ['account_id' => Account::where('code', ManufacturingAccounts::RAW_MATERIAL)->value('id'), 'debit' => '100.00', 'credit' => '0.00'],
                ['account_id' => Account::where('code', 'AS1')->value('id'), 'debit' => '0.00', 'credit' => '100.00'],
            ],
            $owner,
        );

        $fy1->fresh()->relock($owner);

        expect($fy1->fresh()->relocked_at)->not->toBeNull()
            ->and($fy1->fresh()->isOpenForCorrection())->toBeFalse();
    });

    $tenant->delete();
});

test('a single-branch company closes exactly as before', function () {
    $tenant = provisionBranchYearCloseTenant('br-year-close-single.tenant-test');

    $tenant->run(function () {
        [$fy1, $fy2] = branchYearCloseYears();
        $owner = User::factory()->create();
        $mainId = Branch::main()->id;

        branchYearCloseProductionRun(Store::query()->where('name', 'Main Store')->firstOrFail(), $owner);

        expect(Branch::query()->count())->toBe(1);

        $fy1->close($fy2, $owner, BRANCH_YEAR_CLOSE_REASON);

        expect($fy1->fresh()->status)->toBe(FiscalYearStatus::Closed)
            ->and($fy2->fresh()->status)->toBe(FiscalYearStatus::Open)
            ->and(branchYearCloseOpeningLine($fy2, ManufacturingAccounts::RAW_MATERIAL, $mainId)?->debit)->toBe('3000.00')
            ->and(branchYearCloseOpeningLine($fy2, ManufacturingAccounts::FINISHED_GOODS, $mainId)?->debit)->toBe('2000.00')
            ->and(JournalVoucherLine::query()->where('branch_id', '!=', $mainId)->count())->toBe(0);
    });

    $tenant->delete();
});
