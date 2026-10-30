<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\MaterialIssueNote;
use App\Models\ProductionDirectCost;
use App\Models\ProductionEntry;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Manufacturing\InventoryReconciliation;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * M08a: manufacturing postings into a closed year reopened for correction
 * take a reason exactly like purchases and stock adjustments: refused
 * without one, posted into that year (and rolled forward) with one, and the
 * year still reconciles (P1 for RM/FG, P2 for WIP) when it is relocked.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

const MFG_CORRECTION_REASON = 'Material issued in Poush was never entered.';

function mfgCorrectionTenant(): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => 'mfg-correction-'.Str::lower(Str::random(8)).'.tenant-test']);

    return $tenant;
}

function mfgCorrectionStoreId(): int
{
    return (int) Store::where('is_active', true)->orderBy('id')->value('id');
}

function mfgCorrectionCashId(): int
{
    return (int) Account::where('code', 'AS1')->value('id');
}

/**
 * FY1 (2026) with 100 of raw material bought at 50.00 (Dr AS12 5,000.00),
 * 10 issued to an in-progress entry (WIP 500.00), then closed early into
 * FY2 (2027) and reopened for correction.
 *
 * @return array{fy1: FiscalYear, fy2: FiscalYear, actor: User, raw: Item, finished: Item, entry: ProductionEntry}
 */
function mfgCorrectionReopenedYear(): array
{
    $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
    $actor = User::factory()->create();
    $storeId = mfgCorrectionStoreId();

    $raw = Item::factory()->rawMaterial()->create();
    $finished = Item::factory()->finishedGood()->create();

    $raw->recordStockMovement(StockMovementType::Purchase, '100', '2026-02-10', $storeId, null, '50.0000', '5000.00', costValue: '5000.00');
    JournalVoucher::post(['date' => '2026-02-10', 'narration' => 'RM purchase fixture'], [
        ['account_id' => ManufacturingAccounts::id(ManufacturingAccounts::RAW_MATERIAL), 'debit' => '5000.00', 'credit' => '0.00'],
        ['account_id' => mfgCorrectionCashId(), 'debit' => '0.00', 'credit' => '5000.00'],
    ], $actor);

    $note = MaterialIssueNote::post(['date' => '2026-03-01', 'store_id' => $storeId], [['item_id' => $raw->id, 'quantity' => '10']], $actor);

    $entry = ProductionEntry::create(
        ['date' => '2026-03-01', 'store_id' => $storeId, 'finished_item_id' => $finished->id, 'planned_quantity' => '20'],
        [$note->id],
        $actor,
    );

    // FY1 ends after the suite's clock, so this is an early close: owner
    // plus a written reason.
    $fy1->close($fy2, $actor, 'Closed early by the manufacturing correction test.');
    $fy1 = $fy1->fresh();
    $fy1->reopen($actor, 'Production postings were missed.');

    return ['fy1' => $fy1->fresh(), 'fy2' => $fy2->fresh(), 'actor' => $actor, 'raw' => $raw, 'finished' => $finished, 'entry' => $entry->fresh()];
}

/**
 * Asserts every manufacturing class reconciles (GL == subledger) for
 * $fiscalYear as of $asOf.
 */
function mfgCorrectionAssertReconciled(FiscalYear $fiscalYear, string $asOf): void
{
    $check = InventoryReconciliation::check($fiscalYear, $asOf);

    foreach (['raw_material', 'wip', 'finished_good'] as $class) {
        expect($check[$class]['difference']->isZero())->toBeTrue("{$class} does not reconcile for {$fiscalYear->name} as of {$asOf}");
    }
}

test('a material issue posts into a reopened year only with a reason', function () {
    $tenant = mfgCorrectionTenant();

    $tenant->run(function () {
        ['fy1' => $fy1, 'fy2' => $fy2, 'actor' => $actor, 'raw' => $raw] = mfgCorrectionReopenedYear();
        $notesBefore = MaterialIssueNote::count();

        expect(fn () => MaterialIssueNote::post(
            ['date' => '2026-12-20', 'store_id' => mfgCorrectionStoreId()],
            [['item_id' => $raw->id, 'quantity' => '5']],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'A reason is required');

        expect(fn () => MaterialIssueNote::post(
            ['date' => '2026-12-20', 'store_id' => mfgCorrectionStoreId(), 'reason' => '   '],
            [['item_id' => $raw->id, 'quantity' => '5']],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'A reason is required');

        expect(MaterialIssueNote::count())->toBe($notesBefore);

        $note = MaterialIssueNote::post(
            ['date' => '2026-12-20', 'store_id' => mfgCorrectionStoreId(), 'reason' => MFG_CORRECTION_REASON],
            [['item_id' => $raw->id, 'quantity' => '5']],
            $actor,
        );

        $voucher = $note->journalVoucher()->firstOrFail();

        expect((int) $note->fiscal_year_id)->toBe((int) $fy1->id)
            ->and(Money::of($note->total_cost)->toString())->toBe('250.00')
            ->and((int) $voucher->fiscal_year_id)->toBe((int) $fy1->id)
            ->and($voucher->reason)->toBe(MFG_CORRECTION_REASON)
            ->and(ActivityLog::where('action', 'correction')->where('subject_id', $fy1->id)->exists())->toBeTrue();

        $fy1->fresh()->relock($actor);

        expect($fy1->fresh()->isOpenForCorrection())->toBeFalse();
        mfgCorrectionAssertReconciled($fy1->fresh(), '2026-12-31');
        mfgCorrectionAssertReconciled($fy2->fresh(), '2027-01-01');

        // Relocked: the year takes nothing more, reason or not.
        expect(fn () => MaterialIssueNote::post(
            ['date' => '2026-12-21', 'store_id' => mfgCorrectionStoreId(), 'reason' => MFG_CORRECTION_REASON],
            [['item_id' => $raw->id, 'quantity' => '1']],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'has not been reopened');
    });

    $tenant->delete();
});

test('a direct cost posts into a reopened year only with a reason', function () {
    $tenant = mfgCorrectionTenant();

    $tenant->run(function () {
        ['fy1' => $fy1, 'fy2' => $fy2, 'actor' => $actor, 'entry' => $entry] = mfgCorrectionReopenedYear();

        $payload = ['date' => '2026-12-21', 'cost_type' => 'labor', 'amount' => '75.00', 'credit_account_id' => mfgCorrectionCashId()];

        expect(fn () => $entry->addDirectCost($payload, $actor))
            ->toThrow(InvalidArgumentException::class, 'A reason is required');
        expect(ProductionDirectCost::count())->toBe(0);

        $cost = $entry->addDirectCost($payload + ['reason' => MFG_CORRECTION_REASON], $actor);
        $voucher = JournalVoucher::query()->findOrFail($cost->journal_voucher_id);

        expect((int) $voucher->fiscal_year_id)->toBe((int) $fy1->id)
            ->and($voucher->reason)->toBe(MFG_CORRECTION_REASON);

        $fy1->fresh()->relock($actor);

        mfgCorrectionAssertReconciled($fy1->fresh(), '2026-12-31');
        mfgCorrectionAssertReconciled($fy2->fresh(), '2027-01-01');
    });

    $tenant->delete();
});

test('an entry, its return and its completion post into a reopened year with a reason and still reconcile', function () {
    $tenant = mfgCorrectionTenant();

    $tenant->run(function () {
        ['fy1' => $fy1, 'fy2' => $fy2, 'actor' => $actor, 'raw' => $raw, 'finished' => $finished] = mfgCorrectionReopenedYear();
        $storeId = mfgCorrectionStoreId();

        $note = MaterialIssueNote::post(
            ['date' => '2026-12-20', 'store_id' => $storeId, 'reason' => MFG_CORRECTION_REASON],
            [['item_id' => $raw->id, 'quantity' => '8']],
            $actor,
        );

        $data = ['date' => '2026-12-22', 'store_id' => $storeId, 'finished_item_id' => $finished->id, 'planned_quantity' => '4'];

        expect(fn () => ProductionEntry::create($data, [$note->id], $actor))
            ->toThrow(InvalidArgumentException::class, 'A reason is required');
        expect($note->fresh()->production_entry_id)->toBeNull();

        $entry = ProductionEntry::create($data + ['reason' => MFG_CORRECTION_REASON], [$note->id], $actor);
        expect((int) $entry->fiscal_year_id)->toBe((int) $fy1->id);

        expect(fn () => $entry->returnMaterial($raw->id, '2', '2026-12-23', $actor))
            ->toThrow(InvalidArgumentException::class, 'A reason is required');
        $entry->returnMaterial($raw->id, '2', '2026-12-23', $actor, MFG_CORRECTION_REASON);

        expect(fn () => $entry->complete('2026-12-24', '4', true, $actor))
            ->toThrow(InvalidArgumentException::class, 'A reason is required');
        $completion = $entry->complete('2026-12-24', '4', true, $actor, MFG_CORRECTION_REASON);

        expect((int) JournalVoucher::query()->findOrFail($completion->journal_voucher_id)->fiscal_year_id)->toBe((int) $fy1->id)
            ->and($entry->fresh()->status)->toBe(ProductionEntry::STATUS_COMPLETED);

        // A row posted into a correction year cannot be cancelled: reversals
        // only ever reverse a voucher of the open year (C4/C5).
        expect(fn () => $entry->cancelCompletion($completion, $actor, 'Undo.'))
            ->toThrow(InvalidArgumentException::class, 'not the open fiscal year');

        $fy1->fresh()->relock($actor);

        mfgCorrectionAssertReconciled($fy1->fresh(), '2026-12-31');
        mfgCorrectionAssertReconciled($fy2->fresh(), '2027-01-01');
    });

    $tenant->delete();
});
