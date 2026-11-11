<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\PayComponent;
use App\Models\Role;
use App\Models\StaffMember;
use App\Models\StaffPayItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Branches\BranchAccounts;
use App\Support\Hr\PayrollDraft;
use App\Support\Hr\PayrollPoster;
use App\Support\Hr\SalaryPaymentPoster;
use App\Support\Hr\StaffAdvancePoster;
use App\Support\Hr\StaffBalances;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| HR and fixed assets carry a branch (plan 12, BR09)
|--------------------------------------------------------------------------
|
| A staff member has a home branch; each payslip posts its expense, Salary
| Payable and advance recovery there, so a company-wide payroll voucher
| balances per branch without clearing (I1). A salary payment or advance
| paid by another branch moves the money in the paying branch and gets
| Inter-Branch Clearing lines. A fixed asset's purchase, depreciation and
| disposal all post in the asset's branch. A Main-only company posts exactly
| what it posted before.
|
*/

uses(RefreshDatabase::class);

afterEach(function () {
    auth()->forgetGuards();
    tenancy()->end();
});

function provisionBranchHrTenant(string $domain): Tenant
{
    $tenant = Tenant::create([
        'company_name' => 'Acme Co',
        'enabled_modules' => config('permissions.default_modules'),
    ]);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function branchHrOpenYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

/**
 * User::factory() with no role is the tenant owner: unrestricted.
 */
function branchHrOwner(): User
{
    return User::factory()->create(['email' => 'owner@example.com']);
}

/**
 * An admin limited to $branches, read back so all_branches is a loaded value.
 *
 * @param  list<Branch>  $branches
 */
function branchHrRestrictedUser(array $branches, ?Branch $default = null): User
{
    $user = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
    $user->forceFill(['all_branches' => false, 'default_branch_id' => $default?->id])->save();
    $user->branches()->attach(array_map(fn (Branch $branch): int => $branch->id, $branches));

    return User::query()->findOrFail($user->id);
}

function branchHrSalaryComponent(): int
{
    return (int) PayComponent::where('code', 'SALARY')->value('id');
}

/**
 * A staff member with a recurring salary from the start of 2026, in $branch
 * (Main when null).
 *
 * @param  array<string, mixed>  $attributes
 */
function branchHrStaff(string $salary, ?Branch $branch = null, array $attributes = []): StaffMember
{
    $staff = StaffMember::factory()->create([...$attributes, ...($branch !== null ? ['branch_id' => $branch->id] : [])]);

    StaffPayItem::factory()->create(['staff_id' => $staff->id, 'pay_component_id' => branchHrSalaryComponent(), 'amount' => $salary]);

    return $staff;
}

/**
 * @return array{period_label: string, period_start: string, period_end: string, date: string}
 */
function branchHrPeriod(): array
{
    return ['period_label' => 'Asar 2083', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'date' => '2026-06-30'];
}

/**
 * The clearing account BR17 seeds, looked up by its protected code.
 */
function branchHrClearingAccount(): Account
{
    return Account::where('code', BranchAccounts::CLEARING_ACCOUNT_CODE)->firstOrFail();
}

function branchHrAccountId(string $code): int
{
    return (int) Account::where('code', $code)->value('id');
}

/**
 * Each line of a voucher in id order as [branch, account, debit, credit,
 * counterparty branch].
 *
 * @return list<array{0: int, 1: int, 2: string, 3: string, 4: int|null}>
 */
function branchHrLineRows(JournalVoucher $voucher): array
{
    return $voucher->lines()->orderBy('id')->get()
        ->map(fn (JournalVoucherLine $line): array => [
            (int) $line->branch_id,
            (int) $line->account_id,
            $line->debit,
            $line->credit,
            $line->counterparty_branch_id === null ? null : (int) $line->counterparty_branch_id,
        ])
        ->all();
}

/**
 * Debit minus credit per branch id, by ascending id (I1 holds when every
 * value is zero).
 *
 * @param  Collection<int, JournalVoucherLine>  $lines
 * @return array<int, string>
 */
function branchHrNetByBranch(Collection $lines): array
{
    $nets = [];

    foreach ($lines as $line) {
        $nets[(int) $line->branch_id] = ($nets[(int) $line->branch_id] ?? Money::zero())->plus($line->debit)->minus($line->credit);
    }

    ksort($nets);

    return array_map(fn (Money $net): string => $net->toString(), $nets);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function branchHrAssetData(array $overrides = []): array
{
    return [
        'asset_name' => 'Delivery Van',
        'category' => 'Pool B',
        'purchase_date' => '2026-01-01',
        'cost' => '100000.00',
        'depreciation_method' => 'wdv',
        'depreciation_rate' => '20.00',
        'payment_mode' => 'cash',
        ...$overrides,
    ];
}

test('the staff form stores a non-Main home branch, refuses an unusable one, and moves staff only without an advance', function () {
    $domain = 'br-hr-staff.tenant-test';
    $tenant = provisionBranchHrTenant($domain);

    [$branchId, $inactiveId, $mainId] = $tenant->run(function (): array {
        branchHrOpenYear();
        branchHrOwner();

        return [Branch::factory()->create()->id, Branch::factory()->inactive()->create()->id, Branch::main()->id];
    });

    test()->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    test()->post("http://{$domain}/hr/staff", ['name' => 'Asha Gurung', 'branch_id' => $branchId])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $staffId = $tenant->run(function () use ($branchId): int {
        $staff = StaffMember::where('name', 'Asha Gurung')->firstOrFail();

        // Read raw: were branch_id missing from #[Fillable], mass assignment
        // would drop it and the row would fall back to the Main default.
        expect((int) DB::table('hr_staff')->where('id', $staff->id)->value('branch_id'))->toBe($branchId)
            ->and($staff->branch_id)->toBe($branchId);

        return $staff->id;
    });

    // No branch named: the owner's posting branch, Main.
    test()->post("http://{$domain}/hr/staff", ['name' => 'Dipak'])->assertSessionHasNoErrors();

    // An inactive or unknown branch is a form error on branch_id, not a 500.
    test()->post("http://{$domain}/hr/staff", ['name' => 'Bikash', 'branch_id' => $inactiveId])->assertSessionHasErrors('branch_id');
    test()->post("http://{$domain}/hr/staff", ['name' => 'Chandra', 'branch_id' => 999999])->assertSessionHasErrors('branch_id');

    $advance = $tenant->run(function () use ($mainId) {
        expect((int) StaffMember::where('name', 'Dipak')->value('branch_id'))->toBe($mainId)
            ->and(StaffMember::whereIn('name', ['Bikash', 'Chandra'])->count())->toBe(0);

        return StaffAdvancePoster::post([
            'staff_id' => StaffMember::where('name', 'Asha Gurung')->value('id'),
            'date' => '2026-06-10', 'amount' => '500', 'payment_mode' => 'cash',
        ], User::where('email', 'owner@example.com')->firstOrFail());
    });

    // An outstanding advance sits in the current branch: the move is refused.
    test()->put("http://{$domain}/hr/staff/{$staffId}", ['name' => 'Asha Gurung', 'branch_id' => $mainId])
        ->assertSessionHasErrors('branch_id');

    $tenant->run(function () use ($advance, $branchId, $staffId) {
        expect((int) StaffMember::find($staffId)->branch_id)->toBe($branchId);

        StaffAdvancePoster::cancel($advance, User::where('email', 'owner@example.com')->firstOrFail(), 'Not given');
    });

    test()->put("http://{$domain}/hr/staff/{$staffId}", ['name' => 'Asha Gurung', 'branch_id' => $mainId])
        ->assertSessionHasNoErrors();

    $tenant->run(fn () => expect((int) StaffMember::find($staffId)->branch_id)->toBe($mainId));
});

test('a company-wide payroll run posts each payslip in its staff member\'s branch, every branch balanced without clearing', function () {
    provisionBranchHrTenant('br-hr-split.tenant-test')->run(function () {
        branchHrOpenYear();
        $actor = branchHrOwner();
        $mainId = Branch::main()->id;
        $other = Branch::factory()->create();
        $asha = branchHrStaff('30000.00', null, ['name' => 'Asha']);
        $bikash = branchHrStaff('20000.00', $other, ['name' => 'Bikash']);

        // Bikash's own branch gives him an advance; payroll recovers part of it.
        StaffAdvancePoster::post(['staff_id' => $bikash->id, 'date' => '2026-06-10', 'amount' => '5000', 'payment_mode' => 'cash'], $actor);

        $draft = PayrollDraft::generate([...branchHrPeriod(), 'all_branches' => true], $actor);

        expect((int) $draft->branch_id)->toBe($mainId)
            ->and($draft->lines)->toHaveCount(2);

        PayrollDraft::replace($draft, [...branchHrPeriod(), 'lines' => [
            ['staff_id' => $asha->id, 'items' => [['pay_component_id' => branchHrSalaryComponent(), 'amount' => '30000.00']]],
            ['staff_id' => $bikash->id, 'advance_recovery' => '2000', 'items' => [['pay_component_id' => branchHrSalaryComponent(), 'amount' => '20000.00']]],
        ]], $actor);

        $run = PayrollPoster::post($draft, $actor);
        $voucher = $run->journalVoucher;

        // Run branch first, then by id; each group is expense, payable, recovery.
        expect((int) $voucher->branch_id)->toBe($mainId)
            ->and(branchHrLineRows($voucher))->toBe([
                [$mainId, branchHrAccountId('EXE25'), '30000.00', '0.00', null],
                [$mainId, branchHrAccountId('LIA22'), '0.00', '30000.00', null],
                [$other->id, branchHrAccountId('EXE25'), '20000.00', '0.00', null],
                [$other->id, branchHrAccountId('LIA22'), '0.00', '18000.00', null],
                [$other->id, branchHrAccountId('ASA24'), '0.00', '2000.00', null],
            ])
            ->and(branchHrNetByBranch($voucher->lines))->toBe([$mainId => '0.00', $other->id => '0.00'])
            ->and(StaffBalances::advance($bikash->id)->toString())->toBe('3000.00');

        // Main pays Bikash's payslip: Salary Payable falls in his branch, cash
        // leaves Main, and the clearing pair says Main paid for the other branch.
        $clearing = branchHrClearingAccount();
        $bikashLine = $run->lines->firstWhere('staff_id', $bikash->id);

        $payment = SalaryPaymentPoster::post([
            'date' => '2026-07-05', 'payment_mode' => 'cash', 'branch_id' => $mainId,
            'allocations' => [['payroll_line_id' => $bikashLine->id, 'amount' => '18000']],
        ], $actor);

        $paymentVoucher = $payment->journalVoucher;

        expect($payment->branch_id)->toBe($mainId)
            ->and((int) $paymentVoucher->branch_id)->toBe($mainId)
            ->and(branchHrLineRows($paymentVoucher))->toBe([
                [$other->id, branchHrAccountId('LIA22'), '18000.00', '0.00', null],
                [$mainId, branchHrAccountId('AS1'), '0.00', '18000.00', null],
                [$other->id, $clearing->id, '0.00', '18000.00', $mainId],
                [$mainId, $clearing->id, '18000.00', '0.00', $other->id],
            ])
            ->and(branchHrNetByBranch($paymentVoucher->lines))->toBe([$mainId => '0.00', $other->id => '0.00'])
            ->and(StaffBalances::payable($bikash->id)->toString())->toBe('0.00');

        // With no branch named, a payment of one branch's payslip is paid by
        // that branch: single-branch, no clearing.
        $ashaLine = $run->lines->firstWhere('staff_id', $asha->id);
        $own = SalaryPaymentPoster::post(hrBranchCashPayment($ashaLine->id, '1000'), $actor);

        expect($own->branch_id)->toBe($mainId)
            ->and($own->journalVoucher->lines->pluck('branch_id')->unique()->values()->all())->toBe([$mainId]);

        // Cancelling the cross-branch payment mirrors every line, clearing too.
        $reversal = JournalVoucher::findOrFail(SalaryPaymentPoster::cancel($payment, $actor, 'Wrong branch')->reversal_journal_voucher_id);

        expect(branchHrNetByBranch($reversal->lines))->toBe([$mainId => '0.00', $other->id => '0.00'])
            ->and(StaffBalances::payable($bikash->id)->toString())->toBe('18000.00');
    });
});

/**
 * @return array<string, mixed>
 */
function hrBranchCashPayment(int $lineId, string $amount): array
{
    return [
        'date' => '2026-07-05',
        'payment_mode' => 'cash',
        'allocations' => [['payroll_line_id' => $lineId, 'amount' => $amount]],
    ];
}

test('an advance paid by another branch debits the receivable in the staff member\'s branch through clearing', function () {
    provisionBranchHrTenant('br-hr-advance.tenant-test')->run(function () {
        branchHrOpenYear();
        $actor = branchHrOwner();
        $mainId = Branch::main()->id;
        $other = Branch::factory()->create();
        $staff = branchHrStaff('20000.00', $other);
        $clearing = branchHrClearingAccount();

        $advance = StaffAdvancePoster::post([
            'staff_id' => $staff->id, 'date' => '2026-06-10', 'amount' => '1000', 'payment_mode' => 'cash', 'branch_id' => $mainId,
        ], $actor);

        expect($advance->branch_id)->toBe($mainId)
            ->and(branchHrLineRows($advance->journalVoucher))->toBe([
                [$other->id, branchHrAccountId('ASA24'), '1000.00', '0.00', null],
                [$mainId, branchHrAccountId('AS1'), '0.00', '1000.00', null],
                [$other->id, $clearing->id, '0.00', '1000.00', $mainId],
                [$mainId, $clearing->id, '1000.00', '0.00', $other->id],
            ])
            ->and(StaffBalances::advance($staff->id)->toString())->toBe('1000.00');

        // No branch named: the staff member's own branch pays, no clearing.
        $own = StaffAdvancePoster::post(['staff_id' => $staff->id, 'date' => '2026-06-11', 'amount' => '500', 'payment_mode' => 'cash'], $actor);

        expect($own->branch_id)->toBe($other->id)
            ->and($own->journalVoucher->lines)->toHaveCount(2)
            ->and($own->journalVoucher->lines->pluck('branch_id')->unique()->values()->all())->toBe([$other->id]);

        // An inactive or unknown paying branch is a form error, and nothing posts.
        $inactive = Branch::factory()->inactive()->create();
        $before = JournalVoucher::count();

        expect(fn () => StaffAdvancePoster::post([
            'staff_id' => $staff->id, 'date' => '2026-06-12', 'amount' => '100', 'payment_mode' => 'cash', 'branch_id' => $inactive->id,
        ], $actor))->toThrow(InvalidArgumentException::class, 'inactive or you do not have access')
            ->and(fn () => StaffAdvancePoster::post([
                'staff_id' => $staff->id, 'date' => '2026-06-12', 'amount' => '100', 'payment_mode' => 'cash', 'branch_id' => 999999,
            ], $actor))->toThrow(InvalidArgumentException::class)
            ->and(JournalVoucher::count())->toBe($before);
    });
});

test('a fixed asset posts its purchase, depreciation and disposal in its own branch', function () {
    provisionBranchHrTenant('br-hr-asset.tenant-test')->run(function () {
        $year = branchHrOpenYear();
        $actor = branchHrOwner();
        $other = Branch::factory()->create();

        $asset = FixedAsset::post(branchHrAssetData(['branch_id' => $other->id]), $actor);

        expect((int) DB::table('fixed_assets')->where('id', $asset->id)->value('branch_id'))->toBe($other->id)
            ->and((int) $asset->journalVoucher->branch_id)->toBe($other->id)
            ->and($asset->journalVoucher->lines->pluck('branch_id')->unique()->values()->all())->toBe([$other->id]);

        $result = FixedAsset::postDepreciationForFiscalYear($year, $actor);
        $depreciation = JournalVoucher::where('voucher_type', VoucherType::Depreciation->value)->firstOrFail();

        // A full year at 20% WDV on 1,00,000, filed in the asset's branch,
        // not in the branch of whoever ran it.
        expect($result)->toBe(['posted' => 1, 'total' => '20000.00'])
            ->and((int) $depreciation->branch_id)->toBe($other->id)
            ->and(branchHrLineRows($depreciation))->toBe([
                [$other->id, branchHrAccountId('EXE20'), '20000.00', '0.00', null],
                [$other->id, branchHrAccountId('AS31'), '0.00', '20000.00', null],
            ]);

        $asset->refresh()->dispose($actor, '2026-12-31', '70000', 'cash');
        $disposal = $asset->fresh()->disposalJournalVoucher;

        expect((int) $disposal->branch_id)->toBe($other->id)
            ->and($disposal->lines->pluck('branch_id')->unique()->values()->all())->toBe([$other->id])
            ->and(branchHrNetByBranch($disposal->lines))->toBe([$other->id => '0.00']);

        // An unknown branch is refused before anything is written.
        $assets = FixedAsset::count();
        $accounts = Account::count();

        expect(fn () => FixedAsset::post(branchHrAssetData(['asset_name' => 'Ghost', 'branch_id' => 999999]), $actor))
            ->toThrow(InvalidArgumentException::class)
            ->and(FixedAsset::count())->toBe($assets)
            ->and(Account::count())->toBe($accounts);
    });
});

test('a restricted user can only draft payroll and buy assets in their own branches', function () {
    provisionBranchHrTenant('br-hr-restricted.tenant-test')->run(function () {
        branchHrOpenYear();
        $mainId = Branch::main()->id;
        $other = Branch::factory()->create();
        $user = branchHrRestrictedUser([$other], $other);
        branchHrStaff('20000.00', $other);

        expect(fn () => PayrollDraft::generate([...branchHrPeriod(), 'branch_id' => $mainId], $user))
            ->toThrow(InvalidArgumentException::class, 'do not have access')
            ->and(fn () => FixedAsset::post(branchHrAssetData(['branch_id' => $mainId]), $user))
            ->toThrow(InvalidArgumentException::class, 'do not have access');

        // No branch named: their default branch.
        expect((int) PayrollDraft::generate(branchHrPeriod(), $user)->branch_id)->toBe($other->id)
            ->and((int) FixedAsset::post(branchHrAssetData(), $user)->branch_id)->toBe($other->id);
    });
});

test('payroll refuses an unknown or inactive branch in the header', function () {
    provisionBranchHrTenant('br-hr-header.tenant-test')->run(function () {
        branchHrOpenYear();
        $actor = branchHrOwner();
        branchHrStaff('20000.00');

        expect(fn () => PayrollDraft::generate([...branchHrPeriod(), 'branch_id' => 999999], $actor))
            ->toThrow(InvalidArgumentException::class, 'inactive or you do not have access')
            ->and(fn () => PayrollDraft::generate([...branchHrPeriod(), 'branch_id' => Branch::factory()->inactive()->create()->id], $actor))
            ->toThrow(InvalidArgumentException::class, 'inactive or you do not have access')
            ->and(fn () => PayrollDraft::generate([...branchHrPeriod(), 'branch_id' => 'abc'], $actor))
            ->toThrow(InvalidArgumentException::class, 'invalid branch');
    });
});

test('a Main-only company posts HR and asset vouchers exactly as before', function () {
    provisionBranchHrTenant('br-hr-parity.tenant-test')->run(function () {
        $year = branchHrOpenYear();
        $actor = branchHrOwner();
        $mainId = Branch::main()->id;
        $staff = branchHrStaff('30000.00');

        $advance = StaffAdvancePoster::post(['staff_id' => $staff->id, 'date' => '2026-06-10', 'amount' => '1000', 'payment_mode' => 'cash'], $actor);

        $draft = PayrollDraft::generate(branchHrPeriod(), $actor);
        PayrollDraft::replace($draft, [...branchHrPeriod(), 'lines' => [
            ['staff_id' => $staff->id, 'advance_recovery' => '1000', 'items' => [['pay_component_id' => branchHrSalaryComponent(), 'amount' => '30000.00']]],
        ]], $actor);
        $run = PayrollPoster::post($draft, $actor);
        $payment = SalaryPaymentPoster::post(hrBranchCashPayment($run->lines->first()->id, '29000'), $actor);
        $asset = FixedAsset::post(branchHrAssetData(), $actor);
        FixedAsset::postDepreciationForFiscalYear($year, $actor);

        // The same lines, order and amounts the pre-branch code posted, all in Main.
        expect(branchHrLineRows($advance->journalVoucher))->toBe([
            [$mainId, branchHrAccountId('ASA24'), '1000.00', '0.00', null],
            [$mainId, branchHrAccountId('AS1'), '0.00', '1000.00', null],
        ])
            ->and(branchHrLineRows($run->journalVoucher))->toBe([
                [$mainId, branchHrAccountId('EXE25'), '30000.00', '0.00', null],
                [$mainId, branchHrAccountId('LIA22'), '0.00', '29000.00', null],
                [$mainId, branchHrAccountId('ASA24'), '0.00', '1000.00', null],
            ])
            ->and(branchHrLineRows($payment->journalVoucher))->toBe([
                [$mainId, branchHrAccountId('LIA22'), '29000.00', '0.00', null],
                [$mainId, branchHrAccountId('AS1'), '0.00', '29000.00', null],
            ])
            ->and((int) $asset->branch_id)->toBe($mainId)
            ->and((int) $run->run_number)->toBe(1)
            ->and($payment->payment_number)->toBe('SAL-1')
            ->and($advance->advance_number)->toBe('ADV-1');

        // Every voucher and line is Main, and no clearing was ever needed
        // (any clearing attempt would leave a counterparty line).
        expect(JournalVoucher::where('branch_id', '!=', $mainId)->count())->toBe(0)
            ->and(JournalVoucherLine::where('branch_id', '!=', $mainId)->count())->toBe(0)
            ->and(JournalVoucherLine::whereNotNull('counterparty_branch_id')->count())->toBe(0)
            ->and(JournalVoucher::where('voucher_type', VoucherType::Depreciation->value)->value('branch_id'))->toBe($mainId);
    });
});

test('a run that recovered an advance cannot be cancelled after its staff member moved branch, and can once moved back', function () {
    provisionBranchHrTenant('br-hr-cancel-moved.tenant-test')->run(function () {
        branchHrOpenYear();
        $actor = branchHrOwner();
        $mainId = Branch::main()->id;
        $other = Branch::factory()->create();
        $staff = branchHrStaff('20000.00', $other, ['name' => 'Bikash']);

        // An advance given in the other branch, fully recovered by its payroll.
        StaffAdvancePoster::post(['staff_id' => $staff->id, 'date' => '2026-06-10', 'amount' => '5000', 'payment_mode' => 'cash'], $actor);

        $draft = PayrollDraft::generate([...branchHrPeriod(), 'branch_id' => $other->id], $actor);
        PayrollDraft::replace($draft, [...branchHrPeriod(), 'lines' => [
            ['staff_id' => $staff->id, 'advance_recovery' => '5000', 'items' => [['pay_component_id' => branchHrSalaryComponent(), 'amount' => '20000.00']]],
        ]], $actor);
        $run = PayrollPoster::post($draft, $actor);

        expect(StaffBalances::advance($staff->id)->toString())->toBe('0.00');

        // A zero balance lets the staff member move; cancelling now would
        // re-open the advance in the branch they left.
        $staff->update(['branch_id' => $mainId]);

        expect(fn () => PayrollPoster::cancel($run, $actor, 'Wrong month'))
            ->toThrow(InvalidArgumentException::class, 'Move them back to that branch first')
            ->and($run->fresh()->status)->toBe('posted')
            ->and($run->fresh()->reversal_journal_voucher_id)->toBeNull();

        // Moved back: the cancel goes through and the advance re-opens where
        // it was given, every branch of the reversal balanced.
        $staff->update(['branch_id' => $other->id]);

        $cancelled = PayrollPoster::cancel($run, $actor, 'Wrong month');
        $reversal = JournalVoucher::findOrFail($cancelled->reversal_journal_voucher_id);

        expect($cancelled->status)->toBe('cancelled')
            ->and(StaffBalances::advance($staff->id)->toString())->toBe('5000.00')
            ->and($reversal->lines->pluck('branch_id')->unique()->values()->all())->toBe([$other->id])
            ->and(branchHrNetByBranch($reversal->lines))->toBe([$other->id => '0.00']);
    });
});

test('a restricted user only sees, depreciates and disposes the fixed assets of their own branches', function () {
    $domain = 'br-hr-asset-access.tenant-test';
    $tenant = provisionBranchHrTenant($domain);

    [$mainAssetId, $ownAssetId] = $tenant->run(function (): array {
        branchHrOpenYear();
        $owner = branchHrOwner();
        $other = Branch::factory()->create();
        $user = branchHrRestrictedUser([$other], $other);
        $user->forceFill(['email' => 'restricted@example.com'])->save();

        return [
            FixedAsset::post(branchHrAssetData(['asset_name' => 'Head Office Van']), $owner)->id,
            FixedAsset::post(branchHrAssetData(['asset_name' => 'Branch Van', 'branch_id' => $other->id]), $owner)->id,
        ];
    });

    test()->post("http://{$domain}/login", ['email' => 'restricted@example.com', 'password' => 'password']);

    test()->get("http://{$domain}/fixed-assets")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('fixedAssets', 1)
            ->where('fixedAssets.0.id', $ownAssetId));

    // The manual run covers only their branch, and says so when nothing is left.
    test()->post("http://{$domain}/fixed-assets/post-depreciation")->assertSessionHasNoErrors();
    test()->post("http://{$domain}/fixed-assets/post-depreciation")
        ->assertSessionHas('status', 'No depreciation was due for the branches you can access - their assets are already posted for this fiscal year or fully depreciated.');

    $tenant->run(function () use ($mainAssetId, $ownAssetId) {
        expect(DB::table('fixed_asset_depreciations')->where('fixed_asset_id', $ownAssetId)->count())->toBe(1)
            ->and(DB::table('fixed_asset_depreciations')->where('fixed_asset_id', $mainAssetId)->count())->toBe(0);
    });

    // Another branch's asset does not exist for them; their own can be disposed.
    test()->post("http://{$domain}/fixed-assets/{$mainAssetId}/dispose", ['disposal_date' => '2026-12-31', 'disposal_amount' => '1000', 'disposal_mode' => 'cash'])
        ->assertNotFound();

    test()->post("http://{$domain}/fixed-assets/{$ownAssetId}/dispose", ['disposal_date' => '2026-12-31', 'disposal_amount' => '70000', 'disposal_mode' => 'cash'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $tenant->run(function () use ($mainAssetId, $ownAssetId) {
        expect(DB::table('fixed_assets')->where('id', $ownAssetId)->value('status'))->toBe('disposed')
            ->and(DB::table('fixed_assets')->where('id', $mainAssetId)->value('status'))->toBe('active');
    });
});
