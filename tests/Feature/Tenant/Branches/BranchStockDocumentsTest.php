<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\MaterialIssueNote;
use App\Models\ProductionEntry;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockConversion;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Branches\BranchContext;
use App\Support\Manufacturing\InventoryReconciliation;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Stock and manufacturing documents carry a branch (plan 12, BR08)
|--------------------------------------------------------------------------
|
| Stock adjustments, conversions, material issue notes and production
| entries are filed in their store's branch, and every voucher they post
| (and every reversal) sits in that branch on every line. A store of another
| branch is refused before anything is written (I5). A stock transfer is
| single-step only inside one branch. Each branch's AS12 / AS13 / AS14
| reconciles against its own stores and WIP rows (I8), and with only Main
| everything is filed and reconciled exactly as before.
|
*/

uses(RefreshDatabase::class);

afterEach(function () {
    auth()->forgetGuards();
    tenancy()->end();
});

function provisionBranchStockTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function branchStockOpenYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function branchStockAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

/**
 * An admin limited to $branches, read back so all_branches is a loaded value.
 *
 * @param  list<Branch>  $branches
 */
function branchStockRestrictedUser(array $branches, ?Branch $default = null): User
{
    $user = branchStockAdmin();
    $user->forceFill(['all_branches' => false, 'default_branch_id' => $default?->id])->save();
    $user->branches()->attach(array_map(fn (Branch $branch): int => $branch->id, $branches));

    return User::query()->findOrFail($user->id);
}

/**
 * The store TenantDatabaseSeeder files under Main.
 */
function branchStockMainStore(): Store
{
    return Store::query()->where('name', 'Main Store')->firstOrFail();
}

/**
 * A second branch with one active store of its own.
 *
 * @return array{0: Branch, 1: Store}
 */
function branchStockOtherBranch(): array
{
    $branch = Branch::factory()->create(['name' => 'Pokhara']);
    $store = Store::factory()->create(['name' => 'Pokhara Store', 'branch_id' => $branch->id]);

    return [$branch, $store];
}

/**
 * Branch id of every line of $voucher, in line order.
 *
 * @return list<int>
 */
function branchStockLineBranches(JournalVoucher $voucher): array
{
    return JournalVoucherLine::query()->where('journal_voucher_id', $voucher->id)->orderBy('id')->pluck('branch_id')
        ->map(fn (mixed $id): int => (int) $id)->all();
}

/**
 * Brings $quantity of a perpetual item into $store as opening stock, so the
 * GL (AS12 / AS14) and the stock records both start from the same value.
 */
function branchStockOpening(Item $item, Store $store, string $quantity, string $rate, User $actor): StockAdjustment
{
    return StockAdjustment::post(
        ['date' => '2026-06-01', 'store_id' => $store->id],
        [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => $quantity, 'unit_cost_rate' => $rate]],
        $actor,
    );
}

/**
 * The three classes of an I8 check as plain strings, for exact comparison.
 *
 * @param  array<string, array{gl: Money, subledger: Money, difference: Money}>  $check
 * @return array<string, array{0: string, 1: string}>
 */
function branchStockFigures(array $check): array
{
    return array_map(fn (array $row): array => [$row['gl']->toString(), $row['subledger']->toString()], $check);
}

test('a production run in a non-Main branch files every document and voucher there and reconciles per branch', function () {
    $tenant = provisionBranchStockTenant('br-stock-production.tenant-test');

    $tenant->run(function () {
        $year = branchStockOpenYear();
        $admin = branchStockAdmin();
        [$pokhara, $pokharaStore] = branchStockOtherBranch();
        $mainId = Branch::main()->id;
        $flour = Item::factory()->rawMaterial()->create(['name' => 'Flour']);
        $bread = Item::factory()->finishedGood()->create(['name' => 'Bread']);

        $opening = branchStockOpening($flour, $pokharaStore, '100', '50', $admin);

        $note = MaterialIssueNote::post(
            ['date' => '2026-06-02', 'store_id' => $pokharaStore->id],
            [['item_id' => $flour->id, 'quantity' => '40']],
            $admin,
        );

        // Branch and store named together and agreeing.
        $entry = ProductionEntry::create(
            ['date' => '2026-06-02', 'branch_id' => $pokhara->id, 'store_id' => $pokharaStore->id, 'finished_item_id' => $bread->id, 'planned_quantity' => '10'],
            [$note->id],
            $admin,
        );

        $cost = $entry->addDirectCost(['date' => '2026-06-03', 'cost_type' => 'labor', 'description' => 'Labour', 'amount' => '300', 'credit_account_id' => Account::where('code', 'AS1')->value('id')], $admin);
        $entry->returnMaterial($flour->id, '10', '2026-06-04', $admin);
        $completion = $entry->complete('2026-06-05', '10', true, $admin);

        expect($opening->fresh()->branch_id)->toBe($pokhara->id)
            ->and($note->fresh()->branch_id)->toBe($pokhara->id)
            ->and($entry->fresh()->branch_id)->toBe($pokhara->id)
            ->and($completion->cost)->toBe('1800.00');

        // Opening (InventoryCost), issue, direct cost, return, completion.
        $vouchers = JournalVoucher::query()->orderBy('id')->get();

        expect($vouchers)->toHaveCount(5)
            ->and($cost->journal_voucher_id)->not->toBeNull();

        foreach ($vouchers as $voucher) {
            expect($voucher->branch_id)->toBe($pokhara->id)
                ->and(array_unique(branchStockLineBranches($voucher)))->toBe([$pokhara->id]);
        }

        expect(JournalVoucherLine::query()->whereNotNull('counterparty_branch_id')->count())->toBe(0)
            ->and(ItemStockMovement::query()->pluck('store_id')->unique()->values()->all())->toBe([$pokharaStore->id]);

        // I8: Pokhara reconciles with real figures, Main holds nothing, and
        // the branches add up to the unchanged company-wide check.
        $byBranch = InventoryReconciliation::checkByBranch($year, '2026-12-31');

        expect(array_keys($byBranch))->toBe([$mainId, $pokhara->id])
            ->and(branchStockFigures($byBranch[$pokhara->id]))->toBe([
                'raw_material' => ['3500.00', '3500.00'],
                'wip' => ['0.00', '0.00'],
                'finished_good' => ['1800.00', '1800.00'],
            ])
            ->and(branchStockFigures($byBranch[$mainId]))->toBe([
                'raw_material' => ['0.00', '0.00'],
                'wip' => ['0.00', '0.00'],
                'finished_good' => ['0.00', '0.00'],
            ])
            ->and(branchStockFigures(InventoryReconciliation::check($year, '2026-12-31')))->toBe(branchStockFigures($byBranch[$pokhara->id]));

        InventoryReconciliation::assertBranchesReconciled($year, '2026-12-31');
    });

    $tenant->delete();
});

test('a trading adjustment and a conversion are filed in their store\'s branch', function () {
    $tenant = provisionBranchStockTenant('br-stock-trading.tenant-test');

    $tenant->run(function () {
        branchStockOpenYear();
        $admin = branchStockAdmin();
        [$pokhara, $pokharaStore] = branchStockOtherBranch();
        $rice = Item::factory()->create(['is_stockable' => true]);
        $packet = Item::factory()->create(['is_stockable' => true]);

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $pokharaStore->id],
            [['item_id' => $rice->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '10', 'unit_cost_rate' => '20']],
            $admin,
        );

        $conversion = StockConversion::post(
            ['type' => 'repackaging', 'date' => '2026-06-02', 'branch_id' => $pokhara->id, 'store_id' => $pokharaStore->id],
            [['item_id' => $rice->id, 'quantity' => '4']],
            [['item_id' => $packet->id, 'quantity' => '8']],
            $admin,
        );

        // Trading stock is periodic: neither document posts a voucher.
        expect($adjustment->fresh()->branch_id)->toBe($pokhara->id)
            ->and($adjustment->journal_voucher_id)->toBeNull()
            ->and($conversion->fresh()->branch_id)->toBe($pokhara->id)
            ->and($conversion->store_id)->toBe($pokharaStore->id)
            ->and(JournalVoucher::query()->count())->toBe(0)
            ->and($rice->currentStock($pokharaStore->id)->toString())->toBe('6.0000')
            ->and($packet->currentStock($pokharaStore->id)->toString())->toBe('8.0000');
    });

    $tenant->delete();
});

test('a restricted user adjusting with no branch or store lands in their default branch and its default store', function () {
    $tenant = provisionBranchStockTenant('br-stock-restricted.tenant-test');

    $tenant->run(function () {
        branchStockOpenYear();
        [$pokhara, $firstStore] = branchStockOtherBranch();
        $defaultStore = Store::factory()->create(['name' => 'Pokhara Godown', 'branch_id' => $pokhara->id]);
        $pokhara->update(['default_store_id' => $defaultStore->id]);
        $clerk = branchStockRestrictedUser([$pokhara], default: $pokhara);
        $item = Item::factory()->create(['is_stockable' => true]);

        $adjustment = StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '1']],
            $clerk,
        );

        // The lowest-id active store (Main Store) is not a Pokhara store, so
        // the branch's own default store is used.
        expect($adjustment->fresh()->branch_id)->toBe($pokhara->id)
            ->and($adjustment->store_id)->toBe($defaultStore->id)
            ->and($adjustment->store_id)->not->toBe($firstStore->id);

        // A store of a branch the clerk cannot use is refused (I6).
        expect(fn () => StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => branchStockMainStore()->id],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '1']],
            $clerk,
        ))->toThrow(InvalidArgumentException::class, 'you do not have access');

        expect(StockAdjustment::query()->count())->toBe(1);
    });

    $tenant->delete();
});

test('a store of another branch is refused on every stock document and nothing is written', function () {
    $tenant = provisionBranchStockTenant('br-stock-mismatch.tenant-test');

    $tenant->run(function () {
        branchStockOpenYear();
        $admin = branchStockAdmin();
        [$pokhara, $pokharaStore] = branchStockOtherBranch();
        $mainStore = branchStockMainStore();
        $flour = Item::factory()->rawMaterial()->create(['name' => 'Flour']);
        $bread = Item::factory()->finishedGood()->create(['name' => 'Bread']);
        $rice = Item::factory()->create(['is_stockable' => true]);

        // Stock to work with, in Main.
        branchStockOpening($flour, $mainStore, '50', '10', $admin);
        $mainNote = MaterialIssueNote::post(['date' => '2026-06-02', 'store_id' => $mainStore->id], [['item_id' => $flour->id, 'quantity' => '5']], $admin);
        StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $mainStore->id],
            [['item_id' => $rice->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '10']],
            $admin,
        );

        $counts = fn (): array => [
            StockAdjustment::query()->count(),
            StockConversion::query()->count(),
            MaterialIssueNote::query()->count(),
            ProductionEntry::query()->count(),
            StockTransfer::query()->count(),
            JournalVoucher::query()->count(),
            ItemStockMovement::query()->count(),
        ];
        $before = $counts();

        expect(fn () => StockAdjustment::post(
            ['date' => '2026-06-03', 'branch_id' => $pokhara->id, 'store_id' => $mainStore->id],
            [['item_id' => $flour->id, 'direction' => 'out', 'reason_type' => 'damage', 'quantity' => '1']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'does not belong to branch "Pokhara"');

        expect(fn () => StockConversion::post(
            ['type' => 'repackaging', 'date' => '2026-06-03', 'branch_id' => $pokhara->id, 'store_id' => $mainStore->id],
            [['item_id' => $rice->id, 'quantity' => '1']],
            [['item_id' => $rice->id, 'quantity' => '1']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'does not belong to branch "Pokhara"');

        expect(fn () => MaterialIssueNote::post(
            ['date' => '2026-06-03', 'branch_id' => $pokhara->id, 'store_id' => $mainStore->id],
            [['item_id' => $flour->id, 'quantity' => '1']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'does not belong to branch "Pokhara"');

        // A Pokhara entry cannot take a Main note: different store, so
        // different branch.
        expect(fn () => ProductionEntry::create(
            ['date' => '2026-06-03', 'store_id' => $pokharaStore->id, 'finished_item_id' => $bread->id, 'planned_quantity' => '1'],
            [$mainNote->id],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'different store');

        expect(fn () => StockTransfer::post(
            ['date' => '2026-06-03', 'branch_id' => $pokhara->id, 'from_store_id' => $mainStore->id, 'to_store_id' => Store::factory()->create()->id],
            [['item_id' => $rice->id, 'quantity' => '1']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'does not belong to branch "Pokhara"');

        expect($counts())->toBe($before);
    });

    $tenant->delete();
});

test('the stock adjustment form shows a store of another branch as a form error, not a server error', function () {
    $domain = 'br-stock-mismatch-http.tenant-test';
    $tenant = provisionBranchStockTenant($domain);

    $payload = [];
    $tenant->run(function () use (&$payload) {
        User::factory()->create(['email' => 'owner@example.com']);
        branchStockOpenYear();
        [$pokhara] = branchStockOtherBranch();

        $payload = [
            'date' => '2026-06-01',
            'branch_id' => $pokhara->id,
            'store_id' => branchStockMainStore()->id,
            'lines' => [['item_id' => Item::factory()->create(['is_stockable' => true])->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => 1]],
        ];
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/stock-adjustments", $payload)->assertSessionHasErrors('lines');

    $tenant->run(function () {
        expect(StockAdjustment::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('cancelling stock and manufacturing documents reverses them inside their branch', function () {
    $this->travelTo('2026-07-01 10:00:00');

    $tenant = provisionBranchStockTenant('br-stock-cancel.tenant-test');

    $tenant->run(function () {
        $year = branchStockOpenYear();
        $admin = branchStockAdmin();
        [$pokhara, $pokharaStore] = branchStockOtherBranch();
        $flour = Item::factory()->rawMaterial()->create(['name' => 'Flour']);

        $opening = branchStockOpening($flour, $pokharaStore, '100', '50', $admin);
        $writeOff = StockAdjustment::post(
            ['date' => '2026-06-02', 'store_id' => $pokharaStore->id],
            [['item_id' => $flour->id, 'direction' => 'out', 'reason_type' => 'damage', 'quantity' => '10']],
            $admin,
        );
        $note = MaterialIssueNote::post(['date' => '2026-06-03', 'store_id' => $pokharaStore->id], [['item_id' => $flour->id, 'quantity' => '20']], $admin);

        $note->cancel($admin, 'Issued twice');
        $writeOff->cancel($admin, 'Counted wrong');

        $reversals = [
            JournalVoucher::query()->findOrFail($note->fresh()->reversal_journal_voucher_id),
            JournalVoucher::query()->where('reversal_of_id', $writeOff->fresh()->journal_voucher_id)->sole(),
        ];

        foreach ($reversals as $reversal) {
            expect($reversal->branch_id)->toBe($pokhara->id)
                ->and(array_unique(branchStockLineBranches($reversal)))->toBe([$pokhara->id]);
        }

        expect($note->fresh()->branch_id)->toBe($pokhara->id)
            ->and($writeOff->fresh()->status)->toBe('cancelled')
            ->and($writeOff->fresh()->branch_id)->toBe($pokhara->id)
            ->and($opening->fresh()->status)->toBe('posted');

        // Only the opening is left, still reconciled inside Pokhara.
        expect(branchStockFigures(InventoryReconciliation::checkBranch($year, '2026-12-31', $pokhara->id))['raw_material'])
            ->toBe(['5000.00', '5000.00']);
    });

    $tenant->delete();
});

test('a transfer inside one branch stamps that branch on both ends and stays out of other branches\' sight', function () {
    $tenant = provisionBranchStockTenant('br-stock-transfer.tenant-test');

    $tenant->run(function () {
        branchStockOpenYear();
        $admin = branchStockAdmin();
        [$pokhara, $pokharaStore] = branchStockOtherBranch();
        $godown = Store::factory()->create(['name' => 'Pokhara Godown', 'branch_id' => $pokhara->id]);
        $rice = Item::factory()->create(['is_stockable' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $pokharaStore->id],
            [['item_id' => $rice->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => '10']],
            $admin,
        );

        $transfer = StockTransfer::post(
            ['date' => '2026-06-02', 'from_store_id' => $pokharaStore->id, 'to_store_id' => $godown->id],
            [['item_id' => $rice->id, 'quantity' => '4']],
            $admin,
        );

        $transfer = $transfer->fresh();

        expect($transfer->from_branch_id)->toBe($pokhara->id)
            ->and($transfer->to_branch_id)->toBe($pokhara->id)
            ->and(JournalVoucher::query()->count())->toBe(0)
            ->and($rice->currentStock($godown->id)->toString())->toBe('4.0000')
            ->and($rice->currentStock($pokharaStore->id)->toString())->toBe('6.0000');

        $mainOnly = branchStockRestrictedUser([Branch::main()]);
        $pokharaOnly = branchStockRestrictedUser([$pokhara], default: $pokhara);

        expect(BranchContext::restrictedTo($mainOnly, fn () => StockTransfer::query()->count()))->toBe(0)
            ->and(BranchContext::restrictedTo($pokharaOnly, fn () => StockTransfer::query()->count()))->toBe(1)
            ->and(BranchContext::restrictedTo($mainOnly, fn () => StockAdjustment::query()->count()))->toBe(0);

        $transfer->cancel($admin, 'Wrong godown');

        expect($transfer->fresh()->status)->toBe('cancelled')
            ->and($transfer->fresh()->from_branch_id)->toBe($pokhara->id);
    });

    $tenant->delete();
});

test('per-branch reconciliation catches a posting filed in the wrong branch that the company-wide check cannot see', function () {
    $tenant = provisionBranchStockTenant('br-stock-i8-mismatch.tenant-test');

    $tenant->run(function () {
        $year = branchStockOpenYear();
        $admin = branchStockAdmin();
        [, $pokharaStore] = branchStockOtherBranch();
        $flour = Item::factory()->rawMaterial()->create(['name' => 'Flour']);

        branchStockOpening($flour, $pokharaStore, '100', '50', $admin);
        $note = MaterialIssueNote::post(['date' => '2026-06-02', 'store_id' => $pokharaStore->id], [['item_id' => $flour->id, 'quantity' => '40']], $admin);

        // Re-file the whole issue voucher under Main (still balanced there):
        // the books and the stock agree company-wide, but not per branch.
        DB::table('journal_voucher_lines')->where('journal_voucher_id', $note->journal_voucher_id)->update(['branch_id' => Branch::main()->id]);

        expect(InventoryReconciliation::assertReconciled($year, '2026-12-31'))->toBeArray();

        expect(fn () => InventoryReconciliation::assertBranchesReconciled($year, '2026-12-31'))
            ->toThrow(InvalidArgumentException::class, 'Pokhara, Raw Material');
    });

    $tenant->delete();
});

test('with only Main, stock and manufacturing documents are filed and reconciled exactly as before', function () {
    $tenant = provisionBranchStockTenant('br-stock-parity.tenant-test');

    $tenant->run(function () {
        $year = branchStockOpenYear();
        $admin = branchStockAdmin();
        $mainId = Branch::main()->id;
        $mainStore = branchStockMainStore();
        $secondStore = Store::factory()->create(['name' => 'Back Store']);
        // A company default store that is not the lowest-id one: stock
        // documents never used it, and still do not.
        CompanySetting::current()->update(['default_store_id' => $secondStore->id]);
        $flour = Item::factory()->rawMaterial()->create(['name' => 'Flour']);
        $bread = Item::factory()->finishedGood()->create(['name' => 'Bread']);
        $legacyDefault = Store::query()->where('is_active', true)->orderBy('id')->value('id');

        $opening = StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $flour->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => '10', 'unit_cost_rate' => '30']],
            $admin,
        );
        $note = MaterialIssueNote::post(['date' => '2026-06-02'], [['item_id' => $flour->id, 'quantity' => '4']], $admin);
        $entry = ProductionEntry::create(['date' => '2026-06-02', 'finished_item_id' => $bread->id, 'planned_quantity' => '2'], [$note->id], $admin);
        $entry->complete('2026-06-03', '2', true, $admin);
        $transfer = StockTransfer::post(
            ['date' => '2026-06-04', 'from_store_id' => $mainStore->id, 'to_store_id' => $secondStore->id],
            [['item_id' => $flour->id, 'quantity' => '1']],
            $admin,
        )->fresh();

        expect($legacyDefault)->toBe($mainStore->id)
            ->and($opening->store_id)->toBe($legacyDefault)
            ->and($note->store_id)->toBe($legacyDefault)
            ->and($entry->store_id)->toBe($legacyDefault)
            ->and([$opening->fresh()->branch_id, $note->fresh()->branch_id, $entry->fresh()->branch_id])->toBe([$mainId, $mainId, $mainId])
            ->and([$transfer->from_branch_id, $transfer->to_branch_id])->toBe([$mainId, $mainId])
            ->and(JournalVoucher::query()->where('branch_id', '!=', $mainId)->count())->toBe(0)
            ->and(JournalVoucherLine::query()->where('branch_id', '!=', $mainId)->count())->toBe(0)
            ->and(JournalVoucherLine::query()->whereNotNull('counterparty_branch_id')->count())->toBe(0);

        // The company-wide check is what it always was, and Main's own check
        // is the very same figures.
        $company = InventoryReconciliation::assertReconciled($year, '2026-12-31');

        expect(branchStockFigures($company))->toBe([
            'raw_material' => ['180.00', '180.00'],
            'wip' => ['0.00', '0.00'],
            'finished_good' => ['120.00', '120.00'],
        ])
            ->and(array_keys(InventoryReconciliation::assertBranchesReconciled($year, '2026-12-31')))->toBe([$mainId])
            ->and(branchStockFigures(InventoryReconciliation::checkBranch($year, '2026-12-31', $mainId)))->toBe(branchStockFigures($company));
    });

    $tenant->delete();
});

test('a material issue to a production entry with no store named takes the entry\'s store and branch', function () {
    $tenant = provisionBranchStockTenant('br-stock-issue-to-entry.tenant-test');

    $tenant->run(function () {
        branchStockOpenYear();
        $admin = branchStockAdmin();
        [$pokhara, $pokharaStore] = branchStockOtherBranch();
        $flour = Item::factory()->rawMaterial()->create(['name' => 'Flour']);
        $bread = Item::factory()->finishedGood()->create(['name' => 'Bread']);

        branchStockOpening($flour, $pokharaStore, '100', '50', $admin);
        $first = MaterialIssueNote::post(['date' => '2026-06-02', 'store_id' => $pokharaStore->id], [['item_id' => $flour->id, 'quantity' => '10']], $admin);
        $entry = ProductionEntry::create(
            ['date' => '2026-06-02', 'store_id' => $pokharaStore->id, 'finished_item_id' => $bread->id, 'planned_quantity' => '5'],
            [$first->id],
            $admin,
        );

        // No store, no branch: the legacy default (Main Store) would not
        // match the entry, so the entry's own store is used.
        $second = MaterialIssueNote::post(
            ['date' => '2026-06-03', 'production_entry_id' => $entry->id],
            [['item_id' => $flour->id, 'quantity' => '5']],
            $admin,
        );

        $voucher = JournalVoucher::query()->findOrFail($second->journal_voucher_id);

        expect($second->store_id)->toBe($pokharaStore->id)
            ->and($second->fresh()->branch_id)->toBe($pokhara->id)
            ->and($second->production_entry_id)->toBe($entry->id)
            ->and($voucher->branch_id)->toBe($pokhara->id)
            ->and(array_unique(branchStockLineBranches($voucher)))->toBe([$pokhara->id]);

        // Naming another branch for that entry is still refused.
        expect(fn () => MaterialIssueNote::post(
            ['date' => '2026-06-03', 'production_entry_id' => $entry->id, 'branch_id' => Branch::main()->id],
            [['item_id' => $flour->id, 'quantity' => '1']],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'does not belong to branch');
    });

    $tenant->delete();
});

test('with only Main, a material issue to an entry with no store named is filed exactly as before', function () {
    $tenant = provisionBranchStockTenant('br-stock-issue-to-entry-parity.tenant-test');

    $tenant->run(function () {
        branchStockOpenYear();
        $admin = branchStockAdmin();
        $flour = Item::factory()->rawMaterial()->create(['name' => 'Flour']);
        $bread = Item::factory()->finishedGood()->create(['name' => 'Bread']);

        branchStockOpening($flour, branchStockMainStore(), '10', '30', $admin);
        $first = MaterialIssueNote::post(['date' => '2026-06-02'], [['item_id' => $flour->id, 'quantity' => '2']], $admin);
        $entry = ProductionEntry::create(['date' => '2026-06-02', 'finished_item_id' => $bread->id, 'planned_quantity' => '1'], [$first->id], $admin);

        $second = MaterialIssueNote::post(['date' => '2026-06-03', 'production_entry_id' => $entry->id], [['item_id' => $flour->id, 'quantity' => '1']], $admin);

        expect($second->store_id)->toBe(Store::query()->where('is_active', true)->orderBy('id')->value('id'))
            ->and($second->fresh()->branch_id)->toBe(Branch::main()->id);
    });

    $tenant->delete();
});

test('the opening-stock "will be replaced" warning only counts a batch in the branch the import would land in', function () {
    $domain = 'br-stock-opening-warning.tenant-test';
    $tenant = provisionBranchStockTenant($domain);

    $tenant->run(function () {
        branchStockOpenYear();
        [$pokhara] = branchStockOtherBranch();
        $owner = User::factory()->create(['email' => 'owner@example.com']);

        // Main already has an opening batch; Pokhara has none.
        StockAdjustment::postOpeningImport(
            ['date' => '2026-06-01', 'store_id' => branchStockMainStore()->id],
            [['item_id' => Item::factory()->create(['is_stockable' => true])->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => '5', 'unit_cost_rate' => '10']],
            $owner,
        );

        // The owner files into Pokhara by default, so an import there would
        // replace nothing.
        $owner->forceFill(['default_branch_id' => $pokhara->id])->save();
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/stock-adjustments")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('hasOpeningStockImport', false));

    $tenant->delete();
});

test('with only Main, the opening-stock warning shows whenever a batch exists, as before', function () {
    $domain = 'br-stock-opening-warning-parity.tenant-test';
    $tenant = provisionBranchStockTenant($domain);

    $tenant->run(function () {
        branchStockOpenYear();
        $owner = User::factory()->create(['email' => 'owner@example.com']);

        StockAdjustment::postOpeningImport(
            ['date' => '2026-06-01'],
            [['item_id' => Item::factory()->create(['is_stockable' => true])->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => '5', 'unit_cost_rate' => '10']],
            $owner,
        );
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/stock-adjustments")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('hasOpeningStockImport', true));

    $tenant->delete();
});
