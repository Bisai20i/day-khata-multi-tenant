<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\MaterialIssueNote;
use App\Models\ProductionEntry;
use App\Models\ProductionWipEntry;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Manufacturing\ManufacturingAccounts;
use App\Support\Manufacturing\WipLedger;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * ProductionEntry's rules (MC6): the mandatory issue-note reference, store
 * matching, the non-final completion limit, material returns at the entry's
 * own WIP rate, abandon, cancel and the closed-year guard.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionProductionEntryTenant(bool $withClosedPriorYear = false): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => 'prod-entry-'.Str::lower(Str::random(8)).'.tenant-test']);

    $tenant->run(function () use ($withClosedPriorYear) {
        if ($withClosedPriorYear) {
            FiscalYear::create(['name' => '2025', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => FiscalYearStatus::Closed]);
        }

        FiscalYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    return $tenant;
}

function productionEntryStoreId(): int
{
    return (int) Store::where('is_active', true)->orderBy('id')->value('id');
}

function productionEntryCashId(): int
{
    return (int) Account::where('code', 'AS1')->value('id');
}

/**
 * A raw material holding $quantity at an average of $value / $quantity in
 * $storeId (the default store when null).
 */
function productionEntryRawItem(string $quantity = '100', string $value = '5000.00', ?int $storeId = null): Item
{
    $item = Item::factory()->rawMaterial()->create();
    $item->recordStockMovement(StockMovementType::Opening, $quantity, '2026-01-10', $storeId ?? productionEntryStoreId(), null, null, Money::of($value));

    return $item;
}

function productionEntryIssue(Item $item, string $quantity, User $actor, ?int $storeId = null): MaterialIssueNote
{
    return MaterialIssueNote::post(
        ['date' => '2026-06-01', 'store_id' => $storeId ?? productionEntryStoreId()],
        [['item_id' => $item->id, 'quantity' => $quantity]],
        $actor,
    );
}

/**
 * An in-progress entry planned for $planned units of a new finished good,
 * holding RM 10 @ 50 (WIP 500.00).
 *
 * @return array{entry: ProductionEntry, raw: Item, note: MaterialIssueNote, actor: User}
 */
function productionEntryStarted(string $planned = '10'): array
{
    $actor = User::factory()->create();
    $raw = productionEntryRawItem();
    $note = productionEntryIssue($raw, '10', $actor);

    $entry = ProductionEntry::create(
        ['date' => '2026-06-01', 'store_id' => productionEntryStoreId(), 'finished_item_id' => Item::factory()->finishedGood()->create()->id, 'planned_quantity' => $planned],
        [$note->id],
        $actor,
    );

    return compact('entry', 'raw', 'note', 'actor');
}

function productionEntryGl(string $code): string
{
    $lines = JournalVoucherLine::where('account_id', ManufacturingAccounts::id($code))->get();

    return Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')))->toString();
}

test('an entry without a material issue note is rejected', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        $fg = Item::factory()->finishedGood()->create();

        expect(fn () => ProductionEntry::create(
            ['date' => '2026-06-01', 'store_id' => productionEntryStoreId(), 'finished_item_id' => $fg->id, 'planned_quantity' => '10'],
            [],
            User::factory()->create(),
        ))->toThrow(InvalidArgumentException::class, 'at least one material issue note');

        expect(ProductionEntry::count())->toBe(0);
    });

    $tenant->delete();
});

test('the item produced must be a finished good', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        $actor = User::factory()->create();
        $raw = productionEntryRawItem();
        $note = productionEntryIssue($raw, '5', $actor);

        expect(fn () => ProductionEntry::create(
            ['date' => '2026-06-01', 'store_id' => productionEntryStoreId(), 'finished_item_id' => $raw->id, 'planned_quantity' => '10'],
            [$note->id],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'finished good');
    });

    $tenant->delete();
});

test('a note from another store is rejected', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        $actor = User::factory()->create();
        $otherStore = Store::factory()->create(['is_active' => true]);
        $raw = productionEntryRawItem('50', '2500.00', $otherStore->id);
        $note = productionEntryIssue($raw, '5', $actor, $otherStore->id);

        expect(fn () => ProductionEntry::create(
            ['date' => '2026-06-01', 'store_id' => productionEntryStoreId(), 'finished_item_id' => Item::factory()->finishedGood()->create()->id, 'planned_quantity' => '10'],
            [$note->id],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'different store');

        expect($note->fresh()->production_entry_id)->toBeNull();
    });

    $tenant->delete();
});

test('a note already attached to an entry cannot be attached again, a fresh one can', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'raw' => $raw, 'note' => $note, 'actor' => $actor] = productionEntryStarted();

        expect(fn () => $entry->attachIssueNotes([$note->id], $actor))
            ->toThrow(InvalidArgumentException::class, 'already attached');

        $second = productionEntryIssue($raw, '4', $actor);
        $entry->attachIssueNotes([$second->id], $actor);

        expect($second->fresh()->production_entry_id)->toBe($entry->id)
            ->and(WipLedger::balance($entry)->toString())->toBe('700.00')
            ->and(WipLedger::quantityByItem($entry)[$raw->id]->toString())->toBe('14.0000');
    });

    $tenant->delete();
});

test('entries are numbered from their own series', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        $first = productionEntryStarted()['entry'];
        $second = productionEntryStarted()['entry'];

        expect($first->number)->toBe(1)
            ->and($second->number)->toBe(2)
            ->and(JournalVoucher::where('voucher_type', 'production_entry')->count())->toBe(0);
    });

    $tenant->delete();
});

test('a non-final completion at or above the remaining planned quantity is rejected', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'actor' => $actor] = productionEntryStarted('10');

        expect(fn () => $entry->complete('2026-06-05', '10', false, $actor))
            ->toThrow(InvalidArgumentException::class, 'Mark this completion as final');

        $entry->complete('2026-06-05', '4', false, $actor);

        expect(fn () => $entry->complete('2026-06-06', '6', false, $actor))
            ->toThrow(InvalidArgumentException::class, 'Mark this completion as final');

        // 4 of 10 took 40% of 500.00.
        expect(WipLedger::balance($entry)->toString())->toBe('300.00')
            ->and($entry->completions()->count())->toBe(1);
    });

    $tenant->delete();
});

test('a completion with no WIP value is rejected', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'raw' => $raw, 'actor' => $actor] = productionEntryStarted();

        $entry->returnMaterial($raw->id, '10', '2026-06-02', $actor);

        expect(fn () => $entry->complete('2026-06-05', '5', true, $actor))
            ->toThrow(InvalidArgumentException::class, 'no work-in-process value');
    });

    $tenant->delete();
});

test('returning more than the WIP quantity is rejected', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'raw' => $raw, 'actor' => $actor] = productionEntryStarted();

        expect(fn () => $entry->returnMaterial($raw->id, '10.5', '2026-06-02', $actor))
            ->toThrow(InvalidArgumentException::class, 'only 10');

        expect(WipLedger::balance($entry)->toString())->toBe('500.00');
    });

    $tenant->delete();
});

test('a return is costed at the entry WIP rate, not the store average', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'raw' => $raw, 'actor' => $actor] = productionEntryStarted();
        $storeId = productionEntryStoreId();

        // A dearer purchase after the issue moves the store average to 60;
        // the return must still come back at the 50 it went in at.
        $raw->recordStockMovement(StockMovementType::Opening, '90', '2026-06-01', $storeId, null, null, Money::of('6300.00'));

        $entry->returnMaterial($raw->id, '4', '2026-06-02', $actor);

        $movement = ItemStockMovement::where('item_id', $raw->id)
            ->where('movement_type', StockMovementType::MaterialReturn->value)
            ->firstOrFail();

        expect((string) $movement->cost_value)->toBe('200.00')
            ->and(WipLedger::balance($entry)->toString())->toBe('300.00')
            ->and(WipLedger::quantityByItem($entry)[$raw->id]->toString())->toBe('6.0000')
            ->and(productionEntryGl(ManufacturingAccounts::WIP))->toBe(WipLedger::balance()->toString());

        $row = ProductionWipEntry::where('kind', ProductionWipEntry::KIND_MATERIAL_OUT)->firstOrFail();
        expect($row->direction)->toBe(-1)
            ->and((string) $row->value)->toBe('200.00');
    });

    $tenant->delete();
});

test('abandon writes the remaining WIP off to Production Loss', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'actor' => $actor] = productionEntryStarted('10');
        $entry->addDirectCost(['date' => '2026-06-02', 'cost_type' => 'labor', 'amount' => '100', 'credit_account_id' => productionEntryCashId()], $actor);
        $entry->complete('2026-06-03', '2', false, $actor);

        // 600 x 2 / 10 = 120 went to FG; 480 is left to write off.
        expect(WipLedger::balance($entry)->toString())->toBe('480.00');

        $entry->abandon($actor, 'Machine broke down');

        expect($entry->fresh()->status)->toBe(ProductionEntry::STATUS_ABANDONED)
            ->and($entry->fresh()->cancel_reason)->toBe('Machine broke down')
            ->and(WipLedger::balance($entry)->toString())->toBe('0.00')
            ->and(productionEntryGl(ManufacturingAccounts::PRODUCTION_LOSS))->toBe('480.00')
            ->and(productionEntryGl(ManufacturingAccounts::WIP))->toBe('0.00')
            ->and($entry->fresh()->costSheet()['abandoned_value'])->toBe('480.00');

        foreach (WipLedger::quantityByItem($entry) as $quantity) {
            expect($quantity->toString())->toBe('0.0000');
        }

        expect(fn () => $entry->fresh()->complete('2026-06-05', '1', true, $actor))
            ->toThrow(InvalidArgumentException::class, 'abandoned');
    });

    $tenant->delete();
});

test('cancel with a completion is rejected', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'actor' => $actor] = productionEntryStarted('10');
        $entry->complete('2026-06-05', '3', false, $actor);

        expect(fn () => $entry->cancel($actor, 'Wrong item'))
            ->toThrow(InvalidArgumentException::class, 'completed output');

        expect($entry->fresh()->status)->toBe(ProductionEntry::STATUS_IN_PROGRESS);
    });

    $tenant->delete();
});

test('cancel reverses direct costs and returns and detaches the notes', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'raw' => $raw, 'note' => $note, 'actor' => $actor] = productionEntryStarted();
        $cost = $entry->addDirectCost(['date' => '2026-06-02', 'cost_type' => 'labor', 'amount' => '100', 'credit_account_id' => productionEntryCashId()], $actor);
        $entry->returnMaterial($raw->id, '2', '2026-06-02', $actor);

        $entry->cancel($actor, 'Entered twice');

        expect($entry->fresh()->status)->toBe(ProductionEntry::STATUS_CANCELLED)
            ->and($cost->fresh()->cancelled_at)->not->toBeNull()
            ->and(JournalVoucher::findOrFail($cost->journal_voucher_id)->status)->toBe('cancelled')
            ->and($note->fresh()->production_entry_id)->toBeNull()
            ->and(ProductionWipEntry::where('material_issue_note_id', $note->id)->whereNotNull('production_entry_id')->count())->toBe(0)
            ->and(ItemStockMovement::where('movement_type', StockMovementType::MaterialReturn->value)->where('cancelled', false)->count())->toBe(0)
            // Back to exactly what the note itself carries, now unattached.
            ->and(WipLedger::balance()->toString())->toBe('500.00')
            ->and(productionEntryGl(ManufacturingAccounts::WIP))->toBe('500.00')
            ->and($raw->fresh()->currentStock(productionEntryStoreId())->toString())->toBe('90.0000');

        // The detached note can now go onto another entry.
        $next = ProductionEntry::create(
            ['date' => '2026-06-03', 'store_id' => productionEntryStoreId(), 'finished_item_id' => $entry->finished_item_id, 'planned_quantity' => '5'],
            [$note->id],
            $actor,
        );
        expect(WipLedger::balance($next)->toString())->toBe('500.00');
    });

    $tenant->delete();
});

test('a direct cost already partly transferred to finished goods cannot be cancelled in full', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'actor' => $actor] = productionEntryStarted('10');
        $labour = $entry->addDirectCost(['date' => '2026-06-02', 'cost_type' => 'labor', 'amount' => '100', 'credit_account_id' => productionEntryCashId()], $actor);
        $entry->complete('2026-06-03', '5', false, $actor);

        // Half of the 100 went to FG with the first 5 units.
        expect(fn () => $entry->cancelDirectCost($labour, $actor, 'Typo'))
            ->toThrow(InvalidArgumentException::class, 'already been transferred');

        $power = $entry->addDirectCost(['date' => '2026-06-04', 'cost_type' => 'utilities', 'amount' => '40', 'credit_account_id' => productionEntryCashId()], $actor);
        $entry->cancelDirectCost($power, $actor, 'Booked to the wrong entry');

        expect($power->fresh()->cancelled_at)->not->toBeNull()
            ->and(WipLedger::balance($entry)->toString())->toBe('300.00')
            ->and(productionEntryGl(ManufacturingAccounts::WIP))->toBe('300.00');
    });

    $tenant->delete();
});

test('a direct cost must be credited to a cash, bank or liability account', function () {
    $tenant = provisionProductionEntryTenant();

    $tenant->run(function () {
        ['entry' => $entry, 'actor' => $actor] = productionEntryStarted();

        expect(fn () => $entry->addDirectCost([
            'date' => '2026-06-02',
            'cost_type' => 'labor',
            'amount' => '100',
            'credit_account_id' => ManufacturingAccounts::id(ManufacturingAccounts::FINISHED_GOODS),
        ], $actor))->toThrow(InvalidArgumentException::class, 'cash, bank or liability');
    });

    $tenant->delete();
});

test('a date in a closed year is rejected', function () {
    $tenant = provisionProductionEntryTenant(withClosedPriorYear: true);

    $tenant->run(function () {
        $actor = User::factory()->create();
        $raw = productionEntryRawItem();
        $note = productionEntryIssue($raw, '10', $actor);
        $fg = Item::factory()->finishedGood()->create();

        expect(fn () => ProductionEntry::create(
            ['date' => '2025-12-20', 'store_id' => productionEntryStoreId(), 'finished_item_id' => $fg->id, 'planned_quantity' => '10'],
            [$note->id],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'closed');

        expect(ProductionEntry::count())->toBe(0)
            ->and($note->fresh()->production_entry_id)->toBeNull();
    });

    $tenant->delete();
});
