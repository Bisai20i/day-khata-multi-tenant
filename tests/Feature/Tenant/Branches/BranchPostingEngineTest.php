<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VoucherSequence;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchBalancer;
use App\Support\Branches\BranchRuleViolation;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

/*
|--------------------------------------------------------------------------
| Branch-aware posting engine (plan 12, section 5.3, BR04 part a)
|--------------------------------------------------------------------------
|
| Invariant I1: every voucher balances within each branch. A single-branch
| voucher must come out exactly as it did before branches existed; a
| cross-branch one either throws or, with autoBalance, gets Inter-Branch
| Clearing lines hub and spoke around its origin branch.
|
*/

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * Tenant creation migrates and seeds the tenant database, so Main, the chart
 * of accounts and the roles already exist.
 */
function provisionBranchEngineTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function branchEngineOpenYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function branchEngineAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

function branchEngineCash(): Account
{
    return Account::where('code', 'AS1')->firstOrFail();
}

function branchEngineSales(): Account
{
    return Account::where('code', 'INI20')->firstOrFail();
}

/**
 * The clearing account BR17 seeds, looked up by its protected code.
 */
function branchEngineClearingAccount(): Account
{
    return Account::where('code', BranchAccounts::CLEARING_ACCOUNT_CODE)->firstOrFail();
}

/**
 * Debit minus credit per branch id, as exact strings.
 *
 * @param  Collection<int, JournalVoucherLine>  $lines
 * @return array<int, string>
 */
function branchEngineNetByBranch(Collection $lines): array
{
    $nets = [];

    foreach ($lines as $line) {
        $nets[$line->branch_id] = ($nets[$line->branch_id] ?? Money::zero())->plus($line->debit)->minus($line->credit);
    }

    return array_map(fn (Money $net): string => $net->toString(), $nets);
}

/**
 * @return Collection<int, JournalVoucherLine>
 */
function branchEngineLines(JournalVoucher $voucher): Collection
{
    return JournalVoucherLine::query()->where('journal_voucher_id', $voucher->id)->orderBy('id')->get();
}

test('a single-branch voucher posts exactly as before: Main, two lines, no clearing, first number', function () {
    $tenant = provisionBranchEngineTenant('br-engine-parity.tenant-test');

    $tenant->run(function () {
        branchEngineOpenYear();
        $actor = branchEngineAdmin();
        $mainId = Branch::main()->id;

        // No clearing account exists: a single-branch voucher must never
        // need it, with or without autoBalance.
        $voucher = JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Cash sale'],
            [
                ['account_id' => branchEngineCash()->id, 'debit' => '100.50', 'credit' => 0],
                ['account_id' => branchEngineSales()->id, 'debit' => 0, 'credit' => '100.50'],
            ],
            $actor,
        );

        $autoBalanced = JournalVoucher::post(
            ['date' => '2026-06-02', 'narration' => 'Cash sale 2'],
            [
                ['account_id' => branchEngineCash()->id, 'debit' => '20', 'credit' => 0],
                ['account_id' => branchEngineSales()->id, 'debit' => 0, 'credit' => '20'],
            ],
            $actor,
            autoBalance: true,
        );

        $lines = branchEngineLines($voucher);

        expect($voucher->fresh()->branch_id)->toBe($mainId)
            ->and($voucher->voucher_number)->toBe(1)
            ->and($autoBalanced->voucher_number)->toBe(2)
            ->and($lines)->toHaveCount(2)
            ->and($lines[0]->account_id)->toBe(branchEngineCash()->id)
            ->and($lines[0]->debit)->toBe('100.50')
            ->and($lines[0]->credit)->toBe('0.00')
            ->and($lines[1]->account_id)->toBe(branchEngineSales()->id)
            ->and($lines[1]->credit)->toBe('100.50')
            ->and($lines->pluck('branch_id')->unique()->all())->toBe([$mainId])
            ->and($lines->pluck('counterparty_branch_id')->filter()->all())->toBe([])
            ->and(branchEngineLines($autoBalanced))->toHaveCount(2);
    });

    $tenant->delete();
});

test('a voucher that balances overall but not within a branch is refused, and nothing is stored', function () {
    $tenant = provisionBranchEngineTenant('br-engine-unbalanced.tenant-test');

    $tenant->run(function () {
        branchEngineOpenYear();
        $actor = branchEngineAdmin();
        $other = Branch::factory()->create(['name' => 'Pokhara']);
        $vouchersBefore = JournalVoucher::count();
        $linesBefore = JournalVoucherLine::count();
        $sequencesBefore = VoucherSequence::count();

        expect(fn () => JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Cross-branch'],
            [
                ['account_id' => branchEngineCash()->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => branchEngineSales()->id, 'debit' => 0, 'credit' => 100, 'branch_id' => $other->id],
            ],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'do not balance');

        expect(JournalVoucher::count())->toBe($vouchersBefore)
            ->and(JournalVoucherLine::count())->toBe($linesBefore)
            ->and(VoucherSequence::count())->toBe($sequencesBefore);
    });

    $tenant->delete();
});

test('autoBalance across branches without the clearing account names the missing account', function () {
    $tenant = provisionBranchEngineTenant('br-engine-no-clearing.tenant-test');

    $tenant->run(function () {
        Account::query()->where('code', BranchAccounts::CLEARING_ACCOUNT_CODE)->delete();
        branchEngineOpenYear();
        $actor = branchEngineAdmin();
        $other = Branch::factory()->create();
        $vouchersBefore = JournalVoucher::count();

        expect(fn () => JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Cross-branch'],
            [
                ['account_id' => branchEngineCash()->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => branchEngineSales()->id, 'debit' => 0, 'credit' => 100, 'branch_id' => $other->id],
            ],
            $actor,
            autoBalance: true,
        ))->toThrow(BranchRuleViolation::class, BranchBalancer::CLEARING_ACCOUNT_NAME);

        expect(JournalVoucher::count())->toBe($vouchersBefore);
    });

    $tenant->delete();
});

test('autoBalance with three branches adds hub and spoke clearing lines around the origin', function () {
    $tenant = provisionBranchEngineTenant('br-engine-hub-spoke.tenant-test');

    $tenant->run(function () {
        branchEngineOpenYear();
        $actor = branchEngineAdmin();
        $clearing = branchEngineClearingAccount();
        $mainId = Branch::main()->id;
        $second = Branch::factory()->create();
        $third = Branch::factory()->create();

        // Main takes 300.75 in cash for sales booked 100.25 in the second
        // branch and 200.50 in the third.
        $voucher = JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Collected for two branches'],
            [
                ['account_id' => branchEngineCash()->id, 'debit' => '300.75', 'credit' => 0],
                ['account_id' => branchEngineSales()->id, 'debit' => 0, 'credit' => '100.25', 'branch_id' => $second->id],
                ['account_id' => branchEngineSales()->id, 'debit' => 0, 'credit' => '200.50', 'branch_id' => $third->id],
            ],
            $actor,
            autoBalance: true,
        );

        $lines = branchEngineLines($voucher);
        $clearingLines = $lines->where('account_id', $clearing->id)->values();

        expect($voucher->branch_id)->toBe($mainId)
            ->and($lines)->toHaveCount(7)
            ->and($clearingLines)->toHaveCount(4);

        // Spokes in first-appearance order, each followed by the hub mirror.
        expect([$clearingLines[0]->branch_id, $clearingLines[0]->counterparty_branch_id, $clearingLines[0]->debit])->toBe([$second->id, $mainId, '100.25'])
            ->and([$clearingLines[1]->branch_id, $clearingLines[1]->counterparty_branch_id, $clearingLines[1]->credit])->toBe([$mainId, $second->id, '100.25'])
            ->and([$clearingLines[2]->branch_id, $clearingLines[2]->counterparty_branch_id, $clearingLines[2]->debit])->toBe([$third->id, $mainId, '200.50'])
            ->and([$clearingLines[3]->branch_id, $clearingLines[3]->counterparty_branch_id, $clearingLines[3]->credit])->toBe([$mainId, $third->id, '200.50']);

        // I1 per branch, the voucher as a whole, and clearing nets to zero (I3).
        expect(branchEngineNetByBranch($lines))->toBe([$mainId => '0.00', $second->id => '0.00', $third->id => '0.00'])
            ->and(Money::sum($lines->pluck('debit'))->toString())->toBe(Money::sum($lines->pluck('credit'))->toString())
            ->and(Money::sum($clearingLines->pluck('debit'))->toString())->toBe('300.75')
            ->and(Money::sum($clearingLines->pluck('credit'))->toString())->toBe('300.75');

        // Non-clearing lines carry no counterparty.
        expect($lines->where('account_id', '!=', $clearing->id)->pluck('counterparty_branch_id')->filter()->all())->toBe([]);
    });

    $tenant->delete();
});

test('a non-Main branch round-trips through post() on the header and every line', function () {
    $tenant = provisionBranchEngineTenant('br-engine-round-trip.tenant-test');

    $tenant->run(function () {
        branchEngineOpenYear();
        $actor = branchEngineAdmin();
        $branch = Branch::factory()->create();

        $voucher = JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Branch sale', 'branch_id' => $branch->id],
            [
                ['account_id' => branchEngineCash()->id, 'debit' => 75, 'credit' => 0],
                ['account_id' => branchEngineSales()->id, 'debit' => 0, 'credit' => 75],
            ],
            $actor,
        );

        $stored = JournalVoucher::query()->findOrFail($voucher->id);

        expect($stored->branch_id)->toBe($branch->id)
            ->and(branchEngineLines($voucher)->pluck('branch_id')->unique()->all())->toBe([$branch->id]);
    });

    $tenant->delete();
});

test('a reversal mirrors every line with the same branch and counterparty branch', function () {
    $tenant = provisionBranchEngineTenant('br-engine-reversal.tenant-test');

    $tenant->run(function () {
        branchEngineOpenYear();
        $actor = branchEngineAdmin();
        branchEngineClearingAccount();
        $second = Branch::factory()->create();

        $original = JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Collected for second branch'],
            [
                ['account_id' => branchEngineCash()->id, 'debit' => '40', 'credit' => 0],
                ['account_id' => branchEngineSales()->id, 'debit' => 0, 'credit' => '40', 'branch_id' => $second->id],
            ],
            $actor,
            autoBalance: true,
        );

        $reversal = JournalVoucher::reverse($original, $actor, 'Cancelled');

        $originalLines = branchEngineLines($original);
        $reversalLines = branchEngineLines($reversal);

        expect($reversal->branch_id)->toBe($original->branch_id)
            ->and($reversalLines)->toHaveCount($originalLines->count());

        foreach ($originalLines as $index => $line) {
            $mirror = $reversalLines[$index];

            expect($mirror->account_id)->toBe($line->account_id)
                ->and($mirror->branch_id)->toBe($line->branch_id)
                ->and($mirror->counterparty_branch_id)->toBe($line->counterparty_branch_id)
                ->and($mirror->debit)->toBe($line->credit)
                ->and($mirror->credit)->toBe($line->debit);
        }

        expect(branchEngineNetByBranch($reversalLines))->toBe([Branch::main()->id => '0.00', $second->id => '0.00']);
    });

    $tenant->delete();
});

test('a closed-year correction rolls forward with every line keeping its branch', function () {
    $tenant = provisionBranchEngineTenant('br-engine-roll-forward.tenant-test');

    $tenant->run(function () {
        $closed = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);
        $open = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = branchEngineAdmin();
        $closed->reopen($actor, 'Auditor found a duplicate sale');
        branchEngineClearingAccount();
        $second = Branch::factory()->create();

        $correction = JournalVoucher::post(
            ['fiscal_year_id' => $closed->id, 'reason' => 'Duplicate sale', 'date' => '2026-06-15', 'narration' => 'Reverse duplicate sale', 'branch_id' => $second->id],
            [
                ['account_id' => branchEngineSales()->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => branchEngineCash()->id, 'debit' => 0, 'credit' => 100, 'branch_id' => Branch::main()->id],
            ],
            $actor,
            autoBalance: true,
        );

        $rollForward = JournalVoucher::query()
            ->where('fiscal_year_id', $open->id)
            ->where('voucher_type', VoucherType::RollForwardAdjustment)
            ->firstOrFail();

        $correctionLines = branchEngineLines($correction);
        $replayedLines = branchEngineLines($rollForward);

        expect($rollForward->branch_id)->toBe($second->id)
            ->and($replayedLines)->toHaveCount($correctionLines->count());

        foreach ($correctionLines as $index => $line) {
            expect($replayedLines[$index]->branch_id)->toBe($line->branch_id)
                ->and($replayedLines[$index]->counterparty_branch_id)->toBe($line->counterparty_branch_id)
                ->and($replayedLines[$index]->debit)->toBe($line->debit)
                ->and($replayedLines[$index]->credit)->toBe($line->credit);
        }

        expect(branchEngineNetByBranch($replayedLines))->toBe([$second->id => '0.00', Branch::main()->id => '0.00']);
    });

    $tenant->delete();
});

test('post() refuses an inactive or unknown branch, but a voucher in a since-deactivated branch still reverses', function () {
    $tenant = provisionBranchEngineTenant('br-engine-usable.tenant-test');

    $tenant->run(function () {
        branchEngineOpenYear();
        $actor = branchEngineAdmin();
        $branch = Branch::factory()->create(['name' => 'Butwal']);
        $closed = Branch::factory()->inactive()->create(['name' => 'Dharan']);
        $lines = [
            ['account_id' => branchEngineCash()->id, 'debit' => 10, 'credit' => 0],
            ['account_id' => branchEngineSales()->id, 'debit' => 0, 'credit' => 10],
        ];

        expect(fn () => JournalVoucher::post(['date' => '2026-06-01', 'narration' => 'Closed', 'branch_id' => $closed->id], $lines, $actor))
            ->toThrow(InvalidArgumentException::class, 'branch Dharan is inactive')
            ->and(fn () => JournalVoucher::post(['date' => '2026-06-01', 'narration' => 'Unknown', 'branch_id' => 999999], $lines, $actor))
            ->toThrow(InvalidArgumentException::class, 'branch #999999 does not exist');

        $voucher = JournalVoucher::post(['date' => '2026-06-01', 'narration' => 'Open', 'branch_id' => $branch->id], $lines, $actor);
        $branch->update(['is_active' => false]);

        $reversal = JournalVoucher::reverse($voucher, $actor, 'Cancelled');

        expect($reversal->branch_id)->toBe($branch->id)
            ->and(branchEngineLines($reversal)->pluck('branch_id')->unique()->all())->toBe([$branch->id]);
    });

    $tenant->delete();
});

test('an invalid header branch is reported as the voucher\'s, not a line\'s', function () {
    $tenant = provisionBranchEngineTenant('br-engine-header-branch.tenant-test');

    $tenant->run(function () {
        branchEngineOpenYear();

        try {
            JournalVoucher::post(
                ['date' => '2026-06-01', 'narration' => 'Bad header', 'branch_id' => 'abc'],
                [
                    ['account_id' => branchEngineCash()->id, 'debit' => 10, 'credit' => 0],
                    ['account_id' => branchEngineSales()->id, 'debit' => 0, 'credit' => 10],
                ],
                branchEngineAdmin(),
            );
            $message = null;
        } catch (InvalidArgumentException $exception) {
            $message = $exception->getMessage();
        }

        expect($message)->toBe('The journal voucher names an invalid branch.');
    });

    $tenant->delete();
});

test('a counterparty branch must exist and differ from the line\'s own branch', function () {
    $tenant = provisionBranchEngineTenant('br-engine-counterparty.tenant-test');

    $tenant->run(function () {
        branchEngineOpenYear();
        $actor = branchEngineAdmin();
        $mainId = Branch::main()->id;
        $line = fn (array $extra) => [
            ['account_id' => branchEngineCash()->id, 'debit' => 10, 'credit' => 0, ...$extra],
            ['account_id' => branchEngineSales()->id, 'debit' => 0, 'credit' => 10],
        ];

        expect(fn () => JournalVoucher::post(['date' => '2026-06-01', 'narration' => 'Own'], $line(['counterparty_branch_id' => $mainId]), $actor))
            ->toThrow(InvalidArgumentException::class, 'own branch as the counterparty')
            ->and(fn () => JournalVoucher::post(['date' => '2026-06-01', 'narration' => 'Unknown'], $line(['counterparty_branch_id' => 999999]), $actor))
            ->toThrow(InvalidArgumentException::class, 'counterparty branch #999999 does not exist');
    });

    $tenant->delete();
});

test('the balancer leaves an already balanced line set untouched and never resolves the clearing account', function () {
    $lines = [
        ['account_id' => 1, 'debit' => '10.00', 'credit' => '0.00', 'narration' => null, 'branch_id' => 1, 'counterparty_branch_id' => null],
        ['account_id' => 2, 'debit' => '0.00', 'credit' => '10.00', 'narration' => null, 'branch_id' => 1, 'counterparty_branch_id' => null],
        ['account_id' => 3, 'debit' => '5.00', 'credit' => '0.00', 'narration' => null, 'branch_id' => 2, 'counterparty_branch_id' => null],
        ['account_id' => 4, 'debit' => '0.00', 'credit' => '5.00', 'narration' => null, 'branch_id' => 2, 'counterparty_branch_id' => null],
    ];

    $balanced = BranchBalancer::balance($lines, 1, fn (): int => throw new RuntimeException('The clearing account must not be needed.'));

    expect($balanced)->toBe($lines)
        ->and(BranchBalancer::imbalances($lines))->toBe([]);
});

test('the balancer handles an origin with no lines of its own', function () {
    $lines = [
        ['account_id' => 1, 'debit' => '10.00', 'credit' => '0.00', 'narration' => null, 'branch_id' => 2, 'counterparty_branch_id' => null],
        ['account_id' => 2, 'debit' => '0.00', 'credit' => '10.00', 'narration' => null, 'branch_id' => 3, 'counterparty_branch_id' => null],
    ];

    $balanced = BranchBalancer::balance($lines, 1, fn (): int => 99);

    expect($balanced)->toHaveCount(6)
        ->and(BranchBalancer::imbalances($balanced))->toBe([])
        ->and(array_slice($balanced, 2))->toBe([
            ['account_id' => 99, 'debit' => '0.00', 'credit' => '10.00', 'narration' => BranchBalancer::CLEARING_NARRATION, 'branch_id' => 2, 'counterparty_branch_id' => 1],
            ['account_id' => 99, 'debit' => '10.00', 'credit' => '0.00', 'narration' => BranchBalancer::CLEARING_NARRATION, 'branch_id' => 1, 'counterparty_branch_id' => 2],
            ['account_id' => 99, 'debit' => '10.00', 'credit' => '0.00', 'narration' => BranchBalancer::CLEARING_NARRATION, 'branch_id' => 3, 'counterparty_branch_id' => 1],
            ['account_id' => 99, 'debit' => '0.00', 'credit' => '10.00', 'narration' => BranchBalancer::CLEARING_NARRATION, 'branch_id' => 1, 'counterparty_branch_id' => 3],
        ]);
});
