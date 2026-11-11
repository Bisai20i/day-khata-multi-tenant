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
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchContext;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Per-branch year-end close and open (plan 12, section 5.6, BR04 part b)
|--------------------------------------------------------------------------
|
| A Main-only company must get exactly the year-end vouchers it got before
| branches existed. With several branches, the trading pair, the P&L sweep
| and the opening balances are written per branch inside the same vouchers,
| so every branch balances on its own (invariant I1) and the company totals
| are unchanged.
|
*/

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * Every fixture year ends in the future relative to the suite's clock, so
 * each close is an early close: the owner plus a written reason.
 */
const BRANCH_CLOSE_REASON = 'Closed early by the branch close test.';

function provisionBranchCloseTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * A role-less factory user is the tenant owner: unrestricted, and the only
 * actor allowed to close a year early.
 */
function branchCloseOwner(): User
{
    return User::factory()->create(['email' => 'branch-closer@example.com']);
}

/**
 * An admin limited to $branch, read back so all_branches is a loaded value.
 */
function branchCloseRestrictedUser(Branch $branch): User
{
    $user = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
    $user->forceFill(['all_branches' => false, 'default_branch_id' => $branch->id])->save();
    $user->branches()->attach([$branch->id]);

    return User::query()->findOrFail($user->id);
}

function branchCloseAccount(string $code): Account
{
    return Account::where('code', $code)->firstOrFail();
}

function branchClosePl(): Account
{
    return Account::where('name', 'Profit & Loss')->firstOrFail();
}

/**
 * Posts one ordinary two-line voucher into one branch.
 */
function branchClosePost(User $actor, int $branchId, string $date, Account $debit, Account $credit, string $amount): JournalVoucher
{
    return JournalVoucher::post(
        ['date' => $date, 'narration' => 'Fixture entry', 'branch_id' => $branchId],
        [
            ['account_id' => $debit->id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $credit->id, 'debit' => 0, 'credit' => $amount],
        ],
        $actor,
    );
}

/**
 * One expected or stored line as a plain row:
 * [account_id, debit, credit, narration, branch_id, counterparty_branch_id].
 *
 * @return array{0: int, 1: string, 2: string, 3: string, 4: int, 5: int|null}
 */
function branchCloseRow(Account $account, string $debit, string $credit, string $narration, int $branchId, ?int $counterpartyBranchId = null): array
{
    return [$account->id, $debit, $credit, $narration, $branchId, $counterpartyBranchId];
}

/**
 * A voucher's stored lines, in posting order, as branchCloseRow() rows.
 *
 * @return list<array{0: int, 1: string, 2: string, 3: string|null, 4: int, 5: int|null}>
 */
function branchCloseRows(JournalVoucher $voucher): array
{
    return JournalVoucherLine::query()
        ->where('journal_voucher_id', $voucher->id)
        ->orderBy('id')
        ->get()
        ->map(fn (JournalVoucherLine $line): array => [
            (int) $line->account_id,
            $line->debit,
            $line->credit,
            $line->narration,
            $line->branch_id,
            $line->counterparty_branch_id,
        ])
        ->all();
}

/**
 * Puts expected rows into the order the close writes accounts in: the same
 * query FiscalYear::accountsWhereHeadIsProfitAndLoss() runs, so the test
 * pins "account order as before" without assuming what that order is.
 * Selected with get() like the model does, not pluck(): a narrower select
 * may let the database scan a different index and return another order.
 *
 * @param  list<array{0: int}>  $rows
 * @return list<array{0: int}>
 */
function branchCloseInAccountOrder(array $rows, bool $profitAndLoss): array
{
    $order = array_map('intval', Account::query()
        ->whereHas('group.accountHead', fn ($query) => $query->where('is_profit_and_loss', $profitAndLoss))
        ->orWhereHas('subgroup.accountGroup.accountHead', fn ($query) => $query->where('is_profit_and_loss', $profitAndLoss))
        ->get()
        ->modelKeys());

    usort($rows, fn (array $a, array $b): int => array_search($a[0], $order, true) <=> array_search($b[0], $order, true));

    return $rows;
}

/**
 * @return list<JournalVoucher>
 */
function branchCloseVouchers(FiscalYear $year, VoucherType $type): array
{
    return JournalVoucher::query()
        ->where('fiscal_year_id', $year->id)
        ->where('voucher_type', $type)
        ->orderBy('voucher_number')
        ->get()
        ->all();
}

/**
 * Debit minus credit per branch over one voucher's lines.
 *
 * @return array<int, string>
 */
function branchCloseNetByBranch(JournalVoucher $voucher): array
{
    $nets = [];

    foreach (JournalVoucherLine::query()->where('journal_voucher_id', $voucher->id)->get() as $line) {
        $nets[$line->branch_id] = ($nets[$line->branch_id] ?? Money::zero())->plus($line->debit)->minus($line->credit);
    }

    return array_map(fn (Money $net): string => $net->toString(), $nets);
}

test('a Main-only company gets exactly the year-end vouchers it got before branches existed', function () {
    $tenant = provisionBranchCloseTenant('br-close-parity.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fy3 = FiscalYear::create(['name' => 'FY3', 'start_date' => '2028-01-01', 'end_date' => '2028-12-31', 'status' => FiscalYearStatus::Closed]);
        $owner = branchCloseOwner();
        $mainId = Branch::main()->id;

        $cash = branchCloseAccount('AS1');
        $purchases = branchCloseAccount('EXE8');
        $sales = branchCloseAccount('INI20');
        $stockInHand = branchCloseAccount(FiscalYear::STOCK_IN_HAND_CODE);
        $openingStock = branchCloseAccount(FiscalYear::OPENING_STOCK_CODE);
        $closingStock = branchCloseAccount(FiscalYear::CLOSING_STOCK_CODE);
        $pl = branchClosePl();

        // The fixture of FiscalYearClosingTest's stock case: 100 in at 10.00,
        // 40 out, so closing stock is 60 x 10.00 = 600.00. The store falls
        // into Main by default.
        $store = Store::factory()->create();
        $item = Item::factory()->create(['is_stockable' => true, 'purchase_rate' => '10.00']);
        $item->recordStockMovement(StockMovementType::Purchase, '100', '2026-02-01', $store->id, null, '10.0000', '1000.00');
        $item->recordStockMovement(StockMovementType::Sale, '40', '2026-05-01', $store->id);

        // Posted with no branch at all, the way every caller did before.
        JournalVoucher::post(
            ['date' => '2026-02-01', 'narration' => 'Cash purchase'],
            [
                ['account_id' => $purchases->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $cash->id, 'debit' => 0, 'credit' => 1000],
            ],
            $owner,
        );
        JournalVoucher::post(
            ['date' => '2026-05-01', 'narration' => 'Cash sale'],
            [
                ['account_id' => $cash->id, 'debit' => 700, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 700],
            ],
            $owner,
        );

        $fy1->close($fy2, $owner, BRANCH_CLOSE_REASON);

        // FY1: no stock was brought in, so the closing-stock voucher is #1
        // and the sweep #2, exactly as the pre-branch close numbered them.
        $closingEntries = branchCloseVouchers($fy1, VoucherType::ClosingEntry);
        expect($closingEntries)->toHaveCount(2);

        [$closingStockVoucher, $sweep] = $closingEntries;

        expect($closingStockVoucher->voucher_number)->toBe(1)
            ->and($closingStockVoucher->narration)->toBe('Closing stock on hand at 2026-12-31')
            ->and($closingStockVoucher->date->toDateString())->toBe('2026-12-31')
            ->and($closingStockVoucher->branch_id)->toBe($mainId)
            ->and($closingStockVoucher->created_by)->toBe($owner->id)
            ->and(branchCloseRows($closingStockVoucher))->toBe([
                branchCloseRow($stockInHand, '600.00', '0.00', 'Closing stock', $mainId),
                branchCloseRow($closingStock, '0.00', '600.00', 'Closing stock', $mainId),
            ]);

        // 700 sales - 1000 purchases + 600 closing stock = 300 profit.
        expect($sweep->voucher_number)->toBe(2)
            ->and($sweep->narration)->toBe('Year-end closing entries for FY1')
            ->and($sweep->branch_id)->toBe($mainId)
            ->and(branchCloseRows($sweep))->toBe([
                ...branchCloseInAccountOrder([
                    branchCloseRow($purchases, '0.00', '1000.00', 'Year-end closing', $mainId),
                    branchCloseRow($sales, '700.00', '0.00', 'Year-end closing', $mainId),
                    branchCloseRow($closingStock, '600.00', '0.00', 'Year-end closing', $mainId),
                ], true),
                branchCloseRow($pl, '0.00', '300.00', 'Net profit for the year', $mainId),
            ]);

        $fy2Opening = branchCloseVouchers($fy2, VoucherType::OpeningBalance);
        expect($fy2Opening)->toHaveCount(1);

        expect($fy2Opening[0]->voucher_number)->toBe(1)
            ->and($fy2Opening[0]->narration)->toBe('Opening balances carried forward from FY1')
            ->and($fy2Opening[0]->date->toDateString())->toBe('2027-01-01')
            ->and($fy2Opening[0]->branch_id)->toBe($mainId)
            ->and(branchCloseRows($fy2Opening[0]))->toBe(branchCloseInAccountOrder([
                branchCloseRow($cash, '0.00', '300.00', 'Opening balance', $mainId),
                branchCloseRow($stockInHand, '600.00', '0.00', 'Opening balance', $mainId),
                branchCloseRow($pl, '0.00', '300.00', 'Opening balance', $mainId),
            ], false));

        // FY2's close exercises the opening-stock half of the trading pair.
        $fy2->refresh()->close($fy3, $owner, BRANCH_CLOSE_REASON);

        $fy2ClosingEntries = branchCloseVouchers($fy2, VoucherType::ClosingEntry);
        expect($fy2ClosingEntries)->toHaveCount(3);

        [$openingStockVoucher, $fy2ClosingStockVoucher, $fy2Sweep] = $fy2ClosingEntries;

        expect($openingStockVoucher->voucher_number)->toBe(1)
            ->and($openingStockVoucher->narration)->toBe('Opening stock transferred to the trading account for FY2')
            ->and($openingStockVoucher->branch_id)->toBe($mainId)
            ->and(branchCloseRows($openingStockVoucher))->toBe([
                branchCloseRow($openingStock, '600.00', '0.00', 'Opening stock', $mainId),
                branchCloseRow($stockInHand, '0.00', '600.00', 'Opening stock', $mainId),
            ]);

        expect($fy2ClosingStockVoucher->voucher_number)->toBe(2)
            ->and($fy2ClosingStockVoucher->narration)->toBe('Closing stock on hand at 2027-12-31')
            ->and(branchCloseRows($fy2ClosingStockVoucher))->toBe([
                branchCloseRow($stockInHand, '600.00', '0.00', 'Closing stock', $mainId),
                branchCloseRow($closingStock, '0.00', '600.00', 'Closing stock', $mainId),
            ]);

        // Opening and closing stock cancel out: no Profit & Loss line.
        expect($fy2Sweep->voucher_number)->toBe(3)
            ->and(branchCloseRows($fy2Sweep))->toBe(branchCloseInAccountOrder([
                branchCloseRow($openingStock, '0.00', '600.00', 'Year-end closing', $mainId),
                branchCloseRow($closingStock, '600.00', '0.00', 'Year-end closing', $mainId),
            ], true));

        $fy3Opening = branchCloseVouchers($fy3, VoucherType::OpeningBalance);
        expect($fy3Opening)->toHaveCount(1)
            ->and(branchCloseRows($fy3Opening[0]))->toBe(branchCloseInAccountOrder([
                branchCloseRow($cash, '0.00', '300.00', 'Opening balance', $mainId),
                branchCloseRow($stockInHand, '600.00', '0.00', 'Opening balance', $mainId),
                branchCloseRow($pl, '0.00', '300.00', 'Opening balance', $mainId),
            ], false));

        // Nothing anywhere touched another branch or a clearing pair.
        expect(JournalVoucherLine::query()->where('branch_id', '!=', $mainId)->exists())->toBeFalse()
            ->and(JournalVoucherLine::query()->whereNotNull('counterparty_branch_id')->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('a two-branch close keeps every branch balanced and the company totals unchanged', function () {
    $tenant = provisionBranchCloseTenant('br-close-two.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $owner = branchCloseOwner();
        $mainId = Branch::main()->id;
        $branchB = Branch::factory()->create();

        $cash = branchCloseAccount('AS1');
        $purchases = branchCloseAccount('EXE8');
        $sales = branchCloseAccount('INI20');
        $stockInHand = branchCloseAccount(FiscalYear::STOCK_IN_HAND_CODE);
        $closingStock = branchCloseAccount(FiscalYear::CLOSING_STOCK_CODE);
        $pl = branchClosePl();

        // Main: sells 1000, buys 400. Branch B: sells 500, buys 200.
        branchClosePost($owner, $mainId, '2026-03-01', $cash, $sales, '1000.00');
        branchClosePost($owner, $mainId, '2026-04-01', $purchases, $cash, '400.00');
        branchClosePost($owner, $branchB->id, '2026-03-05', $cash, $sales, '500.00');
        branchClosePost($owner, $branchB->id, '2026-04-05', $purchases, $cash, '200.00');

        // Only B holds stock: 50 in at 10.00, 20 out, closing 30 x 10.00.
        $storeB = Store::factory()->create(['branch_id' => $branchB->id]);
        $item = Item::factory()->create(['is_stockable' => true, 'purchase_rate' => '10.00']);
        $item->recordStockMovement(StockMovementType::Purchase, '50', '2026-02-01', $storeB->id, null, '10.0000', '500.00');
        $item->recordStockMovement(StockMovementType::Sale, '20', '2026-05-01', $storeB->id);

        $fy1->close($fy2, $owner, BRANCH_CLOSE_REASON);

        $closingEntries = branchCloseVouchers($fy1, VoucherType::ClosingEntry);
        expect($closingEntries)->toHaveCount(2);

        [$closingStockVoucher, $sweep] = $closingEntries;

        // Main's share of the closing stock is zero, so only B's pair.
        expect($closingStockVoucher->branch_id)->toBe($mainId)
            ->and(branchCloseRows($closingStockVoucher))->toBe([
                branchCloseRow($stockInHand, '300.00', '0.00', 'Closing stock', $branchB->id),
                branchCloseRow($closingStock, '0.00', '300.00', 'Closing stock', $branchB->id),
            ]);

        // Main's group first, then B's, each ending in its own result.
        expect($sweep->branch_id)->toBe($mainId)
            ->and(branchCloseRows($sweep))->toBe([
                ...branchCloseInAccountOrder([
                    branchCloseRow($purchases, '0.00', '400.00', 'Year-end closing', $mainId),
                    branchCloseRow($sales, '1000.00', '0.00', 'Year-end closing', $mainId),
                ], true),
                branchCloseRow($pl, '0.00', '600.00', 'Net profit for the year', $mainId),
                ...branchCloseInAccountOrder([
                    branchCloseRow($purchases, '0.00', '200.00', 'Year-end closing', $branchB->id),
                    branchCloseRow($sales, '500.00', '0.00', 'Year-end closing', $branchB->id),
                    branchCloseRow($closingStock, '300.00', '0.00', 'Year-end closing', $branchB->id),
                ], true),
                branchCloseRow($pl, '0.00', '600.00', 'Net profit for the year', $branchB->id),
            ]);

        $opening = branchCloseVouchers($fy2, VoucherType::OpeningBalance);
        expect($opening)->toHaveCount(1)
            ->and($opening[0]->branch_id)->toBe($mainId)
            ->and(branchCloseRows($opening[0]))->toBe([
                ...branchCloseInAccountOrder([
                    branchCloseRow($cash, '600.00', '0.00', 'Opening balance', $mainId),
                    branchCloseRow($pl, '0.00', '600.00', 'Opening balance', $mainId),
                ], false),
                ...branchCloseInAccountOrder([
                    branchCloseRow($cash, '300.00', '0.00', 'Opening balance', $branchB->id),
                    branchCloseRow($stockInHand, '300.00', '0.00', 'Opening balance', $branchB->id),
                    branchCloseRow($pl, '0.00', '600.00', 'Opening balance', $branchB->id),
                ], false),
            ]);

        // Invariant I1 on every year-end voucher.
        foreach ([...$closingEntries, ...$opening] as $voucher) {
            expect(array_filter(branchCloseNetByBranch($voucher), fn (string $net): bool => $net !== '0.00'))->toBe([]);
        }

        // Company figures are what the single-ledger close would have
        // posted: every P&L account swept to zero, 1,200 profit in total.
        expect($fy1->netBalance($sales)->toString())->toBe('0.00')
            ->and($fy1->netBalance($purchases)->toString())->toBe('0.00')
            ->and($fy1->netBalance($closingStock)->toString())->toBe('0.00')
            ->and($fy1->netBalance($pl)->toString())->toBe('-1200.00')
            ->and($fy1->netBalance($pl, $mainId)->toString())->toBe('-600.00')
            ->and($fy1->netBalance($pl, $branchB->id)->toString())->toBe('-600.00')
            ->and($fy1->netBalance($stockInHand)->toString())->toBe('300.00')
            ->and($fy2->netBalance($cash)->toString())->toBe('900.00')
            ->and($fy2->netBalance($stockInHand, $branchB->id)->toString())->toBe('300.00')
            ->and($fy2->netBalance($stockInHand, $mainId)->toString())->toBe('0.00');
    });

    $tenant->delete();
});

test('netBalance filters by branch, and always reads the whole ledger even for a restricted user', function () {
    $tenant = provisionBranchCloseTenant('br-close-netbalance.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $owner = branchCloseOwner();
        $mainId = Branch::main()->id;
        $branchB = Branch::factory()->create();
        $branchC = Branch::factory()->create();

        $cash = branchCloseAccount('AS1');
        $sales = branchCloseAccount('INI20');
        $purchases = branchCloseAccount('EXE8');

        branchClosePost($owner, $mainId, '2026-03-01', $cash, $sales, '1000.00');
        branchClosePost($owner, $mainId, '2026-04-01', $purchases, $cash, '400.00');
        branchClosePost($owner, $branchB->id, '2026-03-05', $cash, $sales, '500.25');
        branchClosePost($owner, $branchB->id, '2026-04-05', $purchases, $cash, '200.10');

        expect($fy1->netBalance($cash)->toString())->toBe('900.15')
            ->and($fy1->netBalance($cash, $mainId)->toString())->toBe('600.00')
            ->and($fy1->netBalance($cash, $branchB->id)->toString())->toBe('300.15')
            ->and($fy1->netBalance($cash, $branchC->id)->toString())->toBe('0.00')
            ->and($fy1->netBalance($sales)->toString())->toBe('-1500.25');

        $restricted = branchCloseRestrictedUser($branchB);

        // The access scope is live for this user: an ordinary query sees
        // only B's two cash lines...
        expect(BranchContext::restrictedTo($restricted, fn () => JournalVoucherLine::query()->where('account_id', $cash->id)->count()))->toBe(2);

        // ...but the year-end figure is still the whole company's.
        expect(BranchContext::restrictedTo($restricted, fn () => $fy1->netBalance($cash)->toString()))->toBe('900.15')
            ->and(BranchContext::restrictedTo($restricted, fn () => $fy1->netBalance($cash, $mainId)->toString()))->toBe('600.00');
    });

    $tenant->delete();
});

test('a restricted user closing a finished year still closes every branch', function () {
    $tenant = provisionBranchCloseTenant('br-close-restricted.tenant-test');

    $tenant->run(function () {
        // A year that has already ended needs no owner and no reason.
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => FiscalYearStatus::Closed]);
        $owner = branchCloseOwner();
        $mainId = Branch::main()->id;
        $branchB = Branch::factory()->create();

        $cash = branchCloseAccount('AS1');
        $sales = branchCloseAccount('INI20');
        $pl = branchClosePl();

        branchClosePost($owner, $mainId, '2024-03-01', $cash, $sales, '1000.00');
        branchClosePost($owner, $branchB->id, '2024-03-05', $cash, $sales, '250.00');

        $restricted = branchCloseRestrictedUser($branchB);

        BranchContext::restrictedTo($restricted, fn () => $fy1->close($fy2, $restricted));

        expect($fy1->fresh()->status)->toBe(FiscalYearStatus::Closed);

        $sweep = branchCloseVouchers($fy1, VoucherType::ClosingEntry);
        expect($sweep)->toHaveCount(1)
            ->and(branchCloseRows($sweep[0]))->toBe([
                branchCloseRow($sales, '1000.00', '0.00', 'Year-end closing', $mainId),
                branchCloseRow($pl, '0.00', '1000.00', 'Net profit for the year', $mainId),
                branchCloseRow($sales, '250.00', '0.00', 'Year-end closing', $branchB->id),
                branchCloseRow($pl, '0.00', '250.00', 'Net profit for the year', $branchB->id),
            ]);

        $opening = branchCloseVouchers($fy2, VoucherType::OpeningBalance);
        expect($opening)->toHaveCount(1)
            ->and(branchCloseRows($opening[0]))->toBe([
                ...branchCloseInAccountOrder([
                    branchCloseRow($cash, '1000.00', '0.00', 'Opening balance', $mainId),
                    branchCloseRow($pl, '0.00', '1000.00', 'Opening balance', $mainId),
                ], false),
                ...branchCloseInAccountOrder([
                    branchCloseRow($cash, '250.00', '0.00', 'Opening balance', $branchB->id),
                    branchCloseRow($pl, '0.00', '250.00', 'Opening balance', $branchB->id),
                ], false),
            ]);
    });

    $tenant->delete();
});

test('a branch with only an opening balance and no activity carries it forward in its own branch', function () {
    $tenant = provisionBranchCloseTenant('br-close-opening-only.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fy3 = FiscalYear::create(['name' => 'FY3', 'start_date' => '2028-01-01', 'end_date' => '2028-12-31', 'status' => FiscalYearStatus::Closed]);
        $owner = branchCloseOwner();
        $mainId = Branch::main()->id;
        $branchB = Branch::factory()->create();

        $cash = branchCloseAccount('AS1');
        $sales = branchCloseAccount('INI20');
        $pl = branchClosePl();

        branchClosePost($owner, $mainId, '2026-03-01', $cash, $sales, '800.00');
        branchClosePost($owner, $branchB->id, '2026-03-05', $cash, $sales, '500.00');

        $fy1->close($fy2, $owner, BRANCH_CLOSE_REASON);

        // FY2: only Main trades; B has nothing but its opening balance.
        branchClosePost($owner, $mainId, '2027-03-01', $cash, $sales, '100.00');

        $fy2->refresh()->close($fy3, $owner, BRANCH_CLOSE_REASON);

        // B had no profit-and-loss balance in FY2, so the sweep is Main only.
        $sweep = branchCloseVouchers($fy2, VoucherType::ClosingEntry);
        expect($sweep)->toHaveCount(1)
            ->and(branchCloseRows($sweep[0]))->toBe([
                branchCloseRow($sales, '100.00', '0.00', 'Year-end closing', $mainId),
                branchCloseRow($pl, '0.00', '100.00', 'Net profit for the year', $mainId),
            ]);

        // B's opening balance is carried into FY3 unchanged, in B.
        $opening = branchCloseVouchers($fy3, VoucherType::OpeningBalance);
        expect($opening)->toHaveCount(1)
            ->and(branchCloseRows($opening[0]))->toBe([
                ...branchCloseInAccountOrder([
                    branchCloseRow($cash, '900.00', '0.00', 'Opening balance', $mainId),
                    branchCloseRow($pl, '0.00', '900.00', 'Opening balance', $mainId),
                ], false),
                ...branchCloseInAccountOrder([
                    branchCloseRow($cash, '500.00', '0.00', 'Opening balance', $branchB->id),
                    branchCloseRow($pl, '0.00', '500.00', 'Opening balance', $branchB->id),
                ], false),
            ]);
    });

    $tenant->delete();
});

test('an Inter-Branch Clearing balance carries forward per branch', function () {
    $tenant = provisionBranchCloseTenant('br-close-clearing.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $owner = branchCloseOwner();
        $mainId = Branch::main()->id;
        $branchB = Branch::factory()->create();

        // The seeded clearing account (a balance-sheet account).
        $clearing = Account::where('code', BranchAccounts::CLEARING_ACCOUNT_CODE)->firstOrFail();
        $cash = branchCloseAccount('AS1');
        $sales = branchCloseAccount('INI20');
        $pl = branchClosePl();

        // Main collects 100 cash for a sale that belongs to B.
        JournalVoucher::post(
            ['date' => '2026-03-01', 'narration' => 'Main collects for B', 'branch_id' => $mainId],
            [
                ['account_id' => $cash->id, 'debit' => '100.00', 'credit' => 0, 'branch_id' => $mainId],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => '100.00', 'branch_id' => $branchB->id],
            ],
            $owner,
            autoBalance: true,
        );

        $fy1->close($fy2, $owner, BRANCH_CLOSE_REASON);

        $opening = branchCloseVouchers($fy2, VoucherType::OpeningBalance);
        expect($opening)->toHaveCount(1)
            ->and(branchCloseRows($opening[0]))->toBe([
                ...branchCloseInAccountOrder([
                    branchCloseRow($cash, '100.00', '0.00', 'Opening balance', $mainId),
                    branchCloseRow($clearing, '0.00', '100.00', 'Opening balance', $mainId, $branchB->id),
                ], false),
                ...branchCloseInAccountOrder([
                    branchCloseRow($clearing, '100.00', '0.00', 'Opening balance', $branchB->id, $mainId),
                    branchCloseRow($pl, '0.00', '100.00', 'Opening balance', $branchB->id),
                ], false),
            ]);

        // Main owes B 100; across the company the clearing nets to zero (I3).
        expect($fy2->netBalance($clearing, $mainId)->toString())->toBe('-100.00')
            ->and($fy2->netBalance($clearing, $branchB->id)->toString())->toBe('100.00')
            ->and($fy2->netBalance($clearing)->toString())->toBe('0.00');
    });

    $tenant->delete();
});

test('a close is refused while Inter-Branch Clearing does not net to zero across branches', function () {
    $tenant = provisionBranchCloseTenant('br-close-clearing-broken.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $owner = branchCloseOwner();
        $branchB = Branch::factory()->create();

        $clearing = Account::where('code', BranchAccounts::CLEARING_ACCOUNT_CODE)->firstOrFail();
        $cash = branchCloseAccount('AS1');

        // Balanced within B, but a clearing balance nobody owes (breaks I3).
        branchClosePost($owner, $branchB->id, '2026-03-01', $clearing, $cash, '50.00');

        expect(fn () => $fy1->close($fy2, $owner, BRANCH_CLOSE_REASON))
            ->toThrow(InvalidArgumentException::class, 'Inter-Branch Clearing does not net to zero across branches (50.00 debit)');

        expect($fy1->fresh()->status)->toBe(FiscalYearStatus::Open)
            ->and(branchCloseVouchers($fy1, VoucherType::ClosingEntry))->toBe([])
            ->and(branchCloseVouchers($fy2, VoucherType::OpeningBalance))->toBe([]);
    });

    $tenant->delete();
});

test('reopen, correction roll-forward and relock keep every line in its branch', function () {
    $tenant = provisionBranchCloseTenant('br-close-reopen.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $owner = branchCloseOwner();
        $mainId = Branch::main()->id;
        $branchB = Branch::factory()->create();

        $cash = branchCloseAccount('AS1');
        $sales = branchCloseAccount('INI20');
        $purchases = branchCloseAccount('EXE8');
        $pl = branchClosePl();

        branchClosePost($owner, $mainId, '2026-03-01', $cash, $sales, '1000.00');
        branchClosePost($owner, $branchB->id, '2026-03-05', $cash, $sales, '500.00');

        $fy1->close($fy2, $owner, BRANCH_CLOSE_REASON);

        $fy1->refresh()->reopen($owner, 'A late bill for branch B');

        // Reopening rewrites nothing: still one sweep and one opening voucher.
        expect(branchCloseVouchers($fy1, VoucherType::ClosingEntry))->toHaveCount(1)
            ->and(branchCloseVouchers($fy2, VoucherType::OpeningBalance))->toHaveCount(1);

        JournalVoucher::post(
            [
                'fiscal_year_id' => $fy1->id,
                'reason' => 'A late bill for branch B',
                'date' => '2026-06-01',
                'narration' => 'Late bill',
                'branch_id' => $branchB->id,
            ],
            [
                ['account_id' => $purchases->id, 'debit' => '100.00', 'credit' => 0],
                ['account_id' => $cash->id, 'debit' => 0, 'credit' => '100.00'],
            ],
            $owner,
            false,
        );

        // The roll-forward into FY2 stays in B, retargeted to Profit & Loss.
        $rollForward = branchCloseVouchers($fy2, VoucherType::RollForwardAdjustment);
        expect($rollForward)->toHaveCount(1)
            ->and($rollForward[0]->branch_id)->toBe($branchB->id)
            ->and(collect(branchCloseRows($rollForward[0]))->pluck(4)->unique()->values()->all())->toBe([$branchB->id]);

        $fy1->refresh()->relock($owner);

        $closingEntries = branchCloseVouchers($fy1, VoucherType::ClosingEntry);
        expect($closingEntries)->toHaveCount(2);

        // The supplementary sweep only has B's correction to sweep, in B.
        expect($closingEntries[1]->narration)->toBe('Supplementary year-end closing entries for FY1 (corrections posted while reopened)')
            ->and($closingEntries[1]->branch_id)->toBe($mainId)
            ->and(branchCloseRows($closingEntries[1]))->toBe([
                branchCloseRow($purchases, '0.00', '100.00', 'Year-end closing', $branchB->id),
                branchCloseRow($pl, '100.00', '0.00', 'Net loss for the year', $branchB->id),
            ]);

        expect($fy1->netBalance($purchases, $branchB->id)->toString())->toBe('0.00')
            ->and($fy1->netBalance($pl, $branchB->id)->toString())->toBe('-400.00')
            ->and($fy1->netBalance($pl, $mainId)->toString())->toBe('-1000.00')
            ->and($fy2->netBalance($pl, $branchB->id)->toString())->toBe('-400.00')
            ->and($fy2->netBalance($cash, $branchB->id)->toString())->toBe('400.00');
    });

    $tenant->delete();
});
