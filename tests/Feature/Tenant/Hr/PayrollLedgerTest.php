<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\PayComponent;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\StaffMember;
use App\Models\StaffPayItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchBalancer;
use App\Support\Hr\HrAccounts;
use App\Support\Hr\PayrollDraft;
use App\Support\Hr\PayrollPoster;
use App\Support\Hr\SalaryPaymentPoster;
use App\Support\Hr\StaffAdvancePoster;
use App\Support\Hr\StaffBalances;
use App\Support\Hr\StaffPayItemSync;
use App\Support\Money\Money;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * Engine-level coverage of the HR payroll module (plans/11-hr-staff-payroll.md):
 * what each posting writes to the ledger, the Payable / Receivable control
 * accounts, and the rules that keep the staff sub-ledger and the ledger equal.
 * Routes, permissions and the Balance Sheet page are in HrHttpTest.
 */
function hrTenant(): Tenant
{
    return Tenant::create(['company_name' => 'Acme Co']);
}

function hrOpenYear(): User
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

    return User::factory()->create();
}

function hrComponent(string $code): int
{
    return (int) PayComponent::where('code', $code)->value('id');
}

/**
 * A staff member on the payroll with a recurring Salary (and optional
 * Allowance) from the start of 2026.
 */
function hrStaffMember(string $salary = '30000.00', ?string $allowance = null, array $attributes = []): StaffMember
{
    $staff = StaffMember::factory()->create($attributes);

    StaffPayItem::factory()->create(['staff_id' => $staff->id, 'amount' => $salary]);

    if ($allowance !== null) {
        StaffPayItem::factory()->create(['staff_id' => $staff->id, 'pay_component_id' => hrComponent('ALLOWANCE'), 'amount' => $allowance]);
    }

    return $staff;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function hrDraft(User $actor, array $overrides = []): PayrollRun
{
    return PayrollDraft::generate([
        'period_label' => 'Asar 2083',
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'date' => '2026-06-30',
        ...$overrides,
    ], $actor);
}

/**
 * Total debit and credit posted to an account across every voucher.
 *
 * @return array{debit: string, credit: string}
 */
function hrLedger(string $code): array
{
    $lines = JournalVoucherLine::where('account_id', Account::where('code', $code)->value('id'))->get();

    return [
        'debit' => Money::sum($lines->pluck('debit'))->toString(),
        'credit' => Money::sum($lines->pluck('credit'))->toString(),
    ];
}

function hrCashPayment(PayrollLine $line, string $amount, string $date = '2026-07-05'): array
{
    return [
        'date' => $date,
        'payment_mode' => 'cash',
        'allocations' => [['payroll_line_id' => $line->id, 'amount' => $amount]],
    ];
}

test('every tenant is seeded with the HR accounts and the three starter pay components', function () {
    hrTenant()->run(function () {
        foreach (['LIA22' => 'Salary Payable', 'ASA24' => 'Staff Advances Receivable', 'EXE25' => 'Salaries & Wages', 'EXE26' => 'Staff Bonus', 'EXE27' => 'Staff Allowances'] as $code => $name) {
            expect(Account::where('code', $code)->value('name'))->toBe($name);
            expect(in_array($code, Account::SYSTEM_CODES, true))->toBeTrue();
        }

        expect(PayComponent::orderBy('sort_order')->pluck('code')->all())->toBe(['SALARY', 'BONUS', 'ALLOWANCE']);
        expect(PayComponent::where('code', 'BONUS')->first()->expenseAccount->code)->toBe('EXE26');

        // Payable is a liability and Receivable an asset: that is what puts them
        // in the right place on the Balance Sheet.
        expect(Account::where('code', 'LIA22')->first()->group->accountHead->name)->toBe('Liabilities');
        expect(Account::where('code', 'ASA24')->first()->group->accountHead->name)->toBe('Assets');
    });
});

test('the HR voucher types are not manually cancellable', function () {
    foreach ([VoucherType::Payroll, VoucherType::SalaryPayment, VoucherType::StaffAdvance] as $type) {
        expect(in_array($type, VoucherType::manuallyCancellableTypes(), true))->toBeFalse();
    }
});

test('a draft is prefilled from pay items in force and skips inactive staff and staff without pay', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $paid = hrStaffMember('30000.00', '5000.00', ['name' => 'Asha']);
        hrStaffMember('20000.00', null, ['name' => 'Inactive Ira', 'is_active' => false]);
        StaffMember::factory()->create(['name' => 'No Pay Nabin']);

        $run = hrDraft($actor);

        expect($run->status)->toBe('draft');
        expect($run->lines)->toHaveCount(1);
        expect($run->lines->first()->staff_id)->toBe($paid->id);
        expect($run->total_gross)->toBe('35000.00');
        expect($run->total_net)->toBe('35000.00');
        expect($run->journal_voucher_id)->toBeNull();
    });
});

test('posting a run debits one expense per component and credits salary payable', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember('30000.00', '5000.00');
        $run = hrDraft($actor);

        // A one-off bonus is added on the run, not on the recurring pay.
        PayrollDraft::replace($run, [
            'period_label' => 'Asar 2083', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'date' => '2026-06-30',
            'lines' => [[
                'staff_id' => $staff->id,
                'items' => [
                    ['pay_component_id' => hrComponent('SALARY'), 'amount' => '30000.00'],
                    ['pay_component_id' => hrComponent('ALLOWANCE'), 'amount' => '5000.00'],
                    ['pay_component_id' => hrComponent('BONUS'), 'amount' => '2000.00'],
                ],
            ]],
        ]);

        $run = PayrollPoster::post($run, $actor);

        expect($run->status)->toBe('posted');
        expect($run->total_gross)->toBe('37000.00');

        $voucher = $run->journalVoucher;
        expect($voucher->voucher_type)->toBe(VoucherType::Payroll);
        expect($run->run_number)->toBe($voucher->voucher_number);
        expect($run->fiscal_year_id)->toBe($voucher->fiscal_year_id);

        expect(hrLedger('EXE25'))->toBe(['debit' => '30000.00', 'credit' => '0.00']);
        expect(hrLedger('EXE27'))->toBe(['debit' => '5000.00', 'credit' => '0.00']);
        expect(hrLedger('EXE26'))->toBe(['debit' => '2000.00', 'credit' => '0.00']);
        expect(hrLedger('LIA22'))->toBe(['debit' => '0.00', 'credit' => '37000.00']);

        $lines = $voucher->lines;
        expect(Money::sum($lines->pluck('debit'))->toString())->toBe(Money::sum($lines->pluck('credit'))->toString());

        expect(StaffBalances::payable($staff->id)->toString())->toBe('37000.00');
    });
});

test('a posted run cannot be edited or posted twice', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        hrStaffMember();
        $run = PayrollPoster::post(hrDraft($actor), $actor);

        expect(fn () => PayrollPoster::post($run, $actor))->toThrow(InvalidArgumentException::class, 'Only a draft');
        expect(fn () => PayrollDraft::discard($run))->toThrow(InvalidArgumentException::class, 'Only a draft');
    });
});

test('a staff member cannot be paid twice for the same period', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        hrStaffMember();
        hrDraft($actor);

        expect(fn () => hrDraft($actor))->toThrow(InvalidArgumentException::class, 'No eligible staff');

        // A different period is fine.
        expect(hrDraft($actor, ['period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'date' => '2026-07-31']))->toBeInstanceOf(PayrollRun::class);
    });
});

test('a discarded or cancelled run frees its staff for that period again', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        hrStaffMember();

        PayrollDraft::discard(hrDraft($actor));
        expect(PayrollRun::count())->toBe(0);

        $run = PayrollPoster::post(hrDraft($actor), $actor);
        PayrollPoster::cancel($run, $actor, 'Wrong period');

        expect(hrDraft($actor))->toBeInstanceOf(PayrollRun::class);
    });
});

test('an advance posts to the receivable and a payroll recovery reduces it', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember('30000.00', '5000.00');

        $advance = StaffAdvancePoster::post([
            'staff_id' => $staff->id, 'date' => '2026-06-10', 'amount' => '10000', 'payment_mode' => 'cash',
        ], $actor);

        expect($advance->journalVoucher->voucher_type)->toBe(VoucherType::StaffAdvance);
        expect(hrLedger('ASA24'))->toBe(['debit' => '10000.00', 'credit' => '0.00']);
        expect(hrLedger('AS1')['credit'])->toBe('10000.00');
        expect(StaffBalances::advance($staff->id)->toString())->toBe('10000.00');

        $draft = hrDraft($actor);
        PayrollDraft::replace($draft, [
            'period_label' => 'Asar 2083', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'date' => '2026-06-30',
            'lines' => [[
                'staff_id' => $staff->id,
                'advance_recovery' => '4000',
                'items' => [
                    ['pay_component_id' => hrComponent('SALARY'), 'amount' => '30000.00'],
                    ['pay_component_id' => hrComponent('ALLOWANCE'), 'amount' => '5000.00'],
                ],
            ]],
        ]);

        $run = PayrollPoster::post($draft, $actor);

        expect($run->total_gross)->toBe('35000.00');
        expect($run->total_recovery)->toBe('4000.00');
        expect($run->total_net)->toBe('31000.00');
        // Net is what is owed; the recovered part leaves the receivable.
        expect(hrLedger('LIA22'))->toBe(['debit' => '0.00', 'credit' => '31000.00']);
        expect(hrLedger('ASA24'))->toBe(['debit' => '10000.00', 'credit' => '4000.00']);

        expect(StaffBalances::advance($staff->id)->toString())->toBe('6000.00');
        expect(StaffBalances::payable($staff->id)->toString())->toBe('31000.00');
    });
});

test('an advance recovery above the outstanding advance is refused', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember();
        StaffAdvancePoster::post(['staff_id' => $staff->id, 'date' => '2026-06-10', 'amount' => '1000', 'payment_mode' => 'cash'], $actor);

        $draft = hrDraft($actor);

        expect(fn () => PayrollDraft::replace($draft, [
            'period_label' => 'Asar 2083', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'date' => '2026-06-30',
            'lines' => [[
                'staff_id' => $staff->id,
                'advance_recovery' => '1000.01',
                'items' => [['pay_component_id' => hrComponent('SALARY'), 'amount' => '30000.00']],
            ]],
        ]))->toThrow(InvalidArgumentException::class, 'more than their outstanding advance');
    });
});

test('an advance that was partly recovered cannot be cancelled until the run is', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember();
        $advance = StaffAdvancePoster::post(['staff_id' => $staff->id, 'date' => '2026-06-10', 'amount' => '5000', 'payment_mode' => 'cash'], $actor);

        $draft = hrDraft($actor);
        PayrollDraft::replace($draft, [
            'period_label' => 'Asar 2083', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'date' => '2026-06-30',
            'lines' => [['staff_id' => $staff->id, 'advance_recovery' => '2000', 'items' => [['pay_component_id' => hrComponent('SALARY'), 'amount' => '30000.00']]]],
        ]);
        $run = PayrollPoster::post($draft, $actor);

        expect(fn () => StaffAdvancePoster::cancel($advance, $actor, 'Mistake'))->toThrow(InvalidArgumentException::class, 'already been recovered');

        PayrollPoster::cancel($run, $actor, 'Redo');
        expect(StaffBalances::advance($staff->id)->toString())->toBe('5000.00');

        StaffAdvancePoster::cancel($advance, $actor, 'Mistake');
        expect(StaffBalances::advance($staff->id)->toString())->toBe('0.00');
        expect(hrLedger('ASA24')['debit'])->toBe(hrLedger('ASA24')['credit']);
    });
});

test('a salary payment settles part of a payslip and over-payment is refused', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember('30000.00');
        $run = PayrollPoster::post(hrDraft($actor), $actor);
        $line = $run->lines->first();

        $payment = SalaryPaymentPoster::post(hrCashPayment($line, '12000'), $actor);

        expect($payment->journalVoucher->voucher_type)->toBe(VoucherType::SalaryPayment);
        expect($payment->amount)->toBe('12000.00');
        expect(hrLedger('LIA22'))->toBe(['debit' => '12000.00', 'credit' => '30000.00']);
        expect(StaffBalances::payable($staff->id)->toString())->toBe('18000.00');
        expect(PayrollLine::outstandingAmounts([$line])[$line->id]->toString())->toBe('18000.00');

        expect(fn () => SalaryPaymentPoster::post(hrCashPayment($line, '18000.01'), $actor))
            ->toThrow(InvalidArgumentException::class, 'still owed');

        // The rest, exactly.
        SalaryPaymentPoster::post(hrCashPayment($line, '18000'), $actor);
        expect(StaffBalances::payable($staff->id)->toString())->toBe('0.00');
    });
});

test('a salary payment cannot be dated before its payroll run, and a bank payment needs a bank', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        hrStaffMember();
        $line = PayrollPoster::post(hrDraft($actor), $actor)->lines->first();

        expect(fn () => SalaryPaymentPoster::post(hrCashPayment($line, '100', '2026-06-29'), $actor))
            ->toThrow(InvalidArgumentException::class, 'after this payment');

        expect(fn () => SalaryPaymentPoster::post([...hrCashPayment($line, '100'), 'payment_mode' => 'bank'], $actor))
            ->toThrow(InvalidArgumentException::class, 'bank account is required');
    });
});

test('cancelling a payment reopens the payslip', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember();
        $line = PayrollPoster::post(hrDraft($actor), $actor)->lines->first();
        $payment = SalaryPaymentPoster::post(hrCashPayment($line, '30000'), $actor);

        expect(StaffBalances::payable($staff->id)->toString())->toBe('0.00');

        SalaryPaymentPoster::cancel($payment, $actor, 'Bounced');

        expect($payment->fresh()->status)->toBe('cancelled');
        expect(StaffBalances::payable($staff->id)->toString())->toBe('30000.00');
        expect(fn () => SalaryPaymentPoster::cancel($payment, $actor, 'Again'))->toThrow(InvalidArgumentException::class, 'already been cancelled');
    });
});

test('a run with a live payment cannot be cancelled, and cancelling it afterwards clears the payable', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember();
        $run = PayrollPoster::post(hrDraft($actor), $actor);
        $payment = SalaryPaymentPoster::post(hrCashPayment($run->lines->first(), '10000'), $actor);

        expect(fn () => PayrollPoster::cancel($run, $actor, 'Wrong'))->toThrow(InvalidArgumentException::class, 'Cancel those payments first');

        SalaryPaymentPoster::cancel($payment, $actor, 'Wrong');
        $cancelled = PayrollPoster::cancel($run, $actor, 'Wrong');

        expect($cancelled->status)->toBe('cancelled');
        expect($cancelled->reversal_journal_voucher_id)->not->toBeNull();
        expect(StaffBalances::payable($staff->id)->toString())->toBe('0.00');
        // Original and reversal net to nothing on every account.
        expect(hrLedger('LIA22')['debit'])->toBe(hrLedger('LIA22')['credit']);
        expect(hrLedger('EXE25')['debit'])->toBe(hrLedger('EXE25')['credit']);
    });
});

test('the staff sub-ledger agrees with the ledger control accounts', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $first = hrStaffMember('30000.00', '5000.00');
        hrStaffMember('20000.00');
        StaffAdvancePoster::post(['staff_id' => $first->id, 'date' => '2026-06-10', 'amount' => '7000', 'payment_mode' => 'cash'], $actor);

        $run = PayrollPoster::post(hrDraft($actor), $actor);
        SalaryPaymentPoster::post(hrCashPayment($run->lines->first(), '5000'), $actor);

        $report = StaffBalances::reconciliation(FiscalYear::first(), '2026-12-31');

        expect($report['payable']['staff'])->toBe('50000.00');
        expect($report['payable']['difference'])->toBe('0.00');
        expect($report['advance']['ledger'])->toBe('7000.00');
        expect($report['advance']['difference'])->toBe('0.00');
    });
});

test('posting a run dated outside the open fiscal year is refused and leaves the draft untouched', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        hrStaffMember();
        $run = hrDraft($actor, ['period_start' => '2027-01-01', 'period_end' => '2027-01-31', 'date' => '2027-01-31']);

        expect(fn () => PayrollPoster::post($run, $actor))->toThrow(InvalidArgumentException::class, 'outside fiscal year');

        $run->refresh();
        expect($run->status)->toBe('draft');
        expect($run->journal_voucher_id)->toBeNull();
        expect(hrLedger('LIA22'))->toBe(['debit' => '0.00', 'credit' => '0.00']);
    });
});

test('a salary payment across two branches needs the clearing account, and each branch can be paid on its own', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $mainId = Branch::main()->id;
        $otherId = Branch::factory()->create()->id;
        hrStaffMember('30000.00', null, ['branch_id' => $mainId]);
        hrStaffMember('20000.00', null, ['branch_id' => $otherId]);

        $first = PayrollPoster::post(hrDraft($actor, ['branch_id' => $mainId]), $actor);
        $second = PayrollPoster::post(hrDraft($actor, ['branch_id' => $otherId]), $actor);

        // A run generated for a branch holds that branch's staff, and carries
        // the branch on the run and on every payslip.
        expect($first->lines)->toHaveCount(1);
        expect($second->lines)->toHaveCount(1);
        expect((int) $first->branch_id)->toBe($mainId);
        expect((int) $second->branch_id)->toBe($otherId);
        expect((int) $second->lines->first()->branch_id)->toBe($otherId);

        Account::query()->where('code', BranchAccounts::CLEARING_ACCOUNT_CODE)->delete();

        // Settling both branches' payslips at once needs Inter-Branch Clearing
        // lines (plan 12, BR09). This tenant has no clearing account, which
        // is a form error (InvalidArgumentException), not a 500. The full
        // cross-branch payment is covered in Branches/BranchHrAssetsTest.
        expect(fn () => SalaryPaymentPoster::post([
            'date' => '2026-07-05',
            'payment_mode' => 'cash',
            'allocations' => [
                ['payroll_line_id' => $first->lines->first()->id, 'amount' => '100'],
                ['payroll_line_id' => $second->lines->first()->id, 'amount' => '100'],
            ],
        ], $actor))->toThrow(InvalidArgumentException::class, BranchBalancer::CLEARING_ACCOUNT_NAME);

        // Each branch can still be paid on its own, and the payment inherits it.
        expect((int) SalaryPaymentPoster::post(hrCashPayment($first->lines->first(), '100'), $actor)->branch_id)->toBe($mainId);
        expect((int) SalaryPaymentPoster::post(hrCashPayment($second->lines->first(), '100'), $actor)->branch_id)->toBe($otherId);
    });
});

test('changing recurring pay closes the old amount and keeps history', function () {
    hrTenant()->run(function () {
        hrOpenYear();
        $staff = hrStaffMember('30000.00');
        $salary = hrComponent('SALARY');
        $allowance = hrComponent('ALLOWANCE');

        StaffPayItemSync::sync($staff, [
            ['pay_component_id' => $salary, 'amount' => '35000'],
            ['pay_component_id' => $allowance, 'amount' => '2000'],
        ], '2026-07-01');

        $inForce = fn (string $date) => StaffPayItem::where('staff_id', $staff->id)->inForceOn($date)->pluck('amount', 'pay_component_id')->all();

        expect($inForce('2026-06-30'))->toBe([$salary => '30000.00']);
        expect($inForce('2026-07-31'))->toBe([$salary => '35000.00', $allowance => '2000.00']);
        expect(StaffPayItem::where('staff_id', $staff->id)->count())->toBe(3);

        // Removing a component closes it; an unchanged one is left alone.
        StaffPayItemSync::sync($staff, [['pay_component_id' => $salary, 'amount' => '35000']], '2026-08-01');

        expect($inForce('2026-08-15'))->toBe([$salary => '35000.00']);
        expect(StaffPayItem::where('staff_id', $staff->id)->count())->toBe(3);
    });
});

test('a system control account cannot be used as a payment or advance bank account', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember();

        expect(fn () => StaffAdvancePoster::post([
            'staff_id' => $staff->id, 'date' => '2026-06-10', 'amount' => '100', 'payment_mode' => 'bank',
            'bank_account_id' => HrAccounts::id(HrAccounts::STAFF_ADVANCES_RECEIVABLE),
        ], $actor))->toThrow(InvalidArgumentException::class, 'can only be changed from the HR module');
    });
});

test('a staff member cannot be in two runs whose periods overlap, even when not identical', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember();
        $june = hrDraft($actor);

        // 15 June - 14 July shares half of June with the draft above.
        expect(fn () => hrDraft($actor, ['period_label' => 'Mid', 'period_start' => '2026-06-15', 'period_end' => '2026-07-14', 'date' => '2026-07-14']))
            ->toThrow(InvalidArgumentException::class, 'No eligible staff for this period');

        // The adjoining month is fine, but cannot be stretched back into June.
        $july = hrDraft($actor, ['period_label' => 'Shrawan', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'date' => '2026-07-31']);

        expect(fn () => PayrollDraft::replace($july, [
            'period_label' => 'Shrawan', 'period_start' => '2026-06-30', 'period_end' => '2026-07-31', 'date' => '2026-07-31',
            'lines' => [['staff_id' => $staff->id, 'items' => [['pay_component_id' => hrComponent('SALARY'), 'amount' => '30000.00']]]],
        ]))->toThrow(InvalidArgumentException::class, 'overlaps this one');

        PayrollDraft::discard($july);

        // Two drafts that slipped past the draft check (written side by side)
        // are still caught when either is posted.
        $rogue = PayrollRun::create([
            'period_label' => 'Rogue', 'period_start' => '2026-06-15', 'period_end' => '2026-07-14', 'date' => '2026-07-14',
            'status' => PayrollRun::STATUS_DRAFT, 'branch_id' => $staff->branch_id, 'created_by' => $actor->id,
        ]);
        $rogueLine = $rogue->lines()->create(['staff_id' => $staff->id, 'branch_id' => $staff->branch_id, 'gross' => '30000.00', 'advance_recovery' => '0.00', 'net' => '30000.00']);
        $rogueLine->items()->create(['pay_component_id' => hrComponent('SALARY'), 'amount' => '30000.00']);

        expect(fn () => PayrollPoster::post($june, $actor))->toThrow(InvalidArgumentException::class, 'which overlaps this period');
        expect($june->fresh()->status)->toBe('draft');
        expect(hrLedger('LIA22'))->toBe(['debit' => '0.00', 'credit' => '0.00']);
    });
});

test('a payroll run is generated for one branch, and each payslip keeps its staff member\'s branch', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $local = hrStaffMember('30000.00', null, ['name' => 'Asha']);
        $other = hrStaffMember('20000.00', null, ['name' => 'Bikash', 'branch_id' => Branch::factory()->create()->id]);

        // No branch on the header means the Main branch, which is Asha's.
        $run = hrDraft($actor);
        expect((int) $run->branch_id)->toBe(Branch::main()->id);
        expect($run->lines)->toHaveCount(1);
        expect($run->lines->first()->staff_id)->toBe($local->id);

        // Since BR09 a run may also pay staff of other branches: each payslip
        // keeps its staff member's own branch, which is where it will post.
        $run = PayrollDraft::replace($run, [
            'period_label' => 'Asar 2083', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'date' => '2026-06-30',
            'lines' => [
                ['staff_id' => $local->id, 'items' => [['pay_component_id' => hrComponent('SALARY'), 'amount' => '30000.00']]],
                ['staff_id' => $other->id, 'items' => [['pay_component_id' => hrComponent('SALARY'), 'amount' => '20000.00']]],
            ],
        ]);

        expect((int) $run->branch_id)->toBe(Branch::main()->id);
        expect((int) PayrollLine::where('payroll_run_id', $run->id)->where('staff_id', $local->id)->value('branch_id'))->toBe(Branch::main()->id);
        expect((int) PayrollLine::where('payroll_run_id', $run->id)->where('staff_id', $other->id)->value('branch_id'))->toBe((int) $other->branch_id);

        // Moved to another branch after the draft was saved: refused at posting.
        $local->update(['branch_id' => Branch::factory()->create()->id]);

        expect(fn () => PayrollPoster::post($run, $actor))->toThrow(InvalidArgumentException::class, 'different branch');
        expect($run->fresh()->status)->toBe('draft');
    });
});

test('a run with no branch in the header is stored as Main and its payslips and payment are stamped Main', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $mainId = Branch::main()->id;
        hrStaffMember('30000.00');

        // No branch_id in the header: the draft writes Main's id itself, not
        // via the column default, and never reads it as "no branch".
        $draft = hrDraft($actor);

        expect((int) DB::table('hr_payroll_runs')->where('id', $draft->id)->value('branch_id'))->toBe($mainId);
        expect(DB::table('hr_payroll_lines')->where('payroll_run_id', $draft->id)->pluck('branch_id')->map(fn ($id) => (int) $id)->all())->toBe([$mainId]);

        $run = PayrollPoster::post($draft, $actor);

        expect($run->status)->toBe('posted');

        $payment = SalaryPaymentPoster::post(hrCashPayment($run->lines->first(), '100'), $actor);

        expect((int) $payment->branch_id)->toBe($mainId);
    });
});

test('a staff advance is stamped with the staff member\'s home branch', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $otherId = Branch::factory()->create()->id;
        $staff = hrStaffMember('30000.00', null, ['branch_id' => $otherId]);

        $advance = StaffAdvancePoster::post([
            'staff_id' => $staff->id, 'date' => '2026-06-10', 'amount' => '1000', 'payment_mode' => 'cash',
        ], $actor);

        expect((int) $advance->branch_id)->toBe($otherId);
    });
});

test('a back-dated pay change is refused and a same-day correction updates in place', function () {
    hrTenant()->run(function () {
        hrOpenYear();
        $staff = hrStaffMember('30000.00');
        $salary = hrComponent('SALARY');
        $allowance = hrComponent('ALLOWANCE');
        $inForce = fn (string $date) => StaffPayItem::where('staff_id', $staff->id)->inForceOn($date)->pluck('amount', 'pay_component_id')->all();

        StaffPayItemSync::sync($staff, [['pay_component_id' => $salary, 'amount' => '35000']], '2026-07-01');

        // Before the 1 July change: would rewrite history already in place.
        expect(fn () => StaffPayItemSync::sync($staff, [['pay_component_id' => $salary, 'amount' => '40000']], '2026-05-01'))
            ->toThrow(InvalidArgumentException::class, "Pay changes must take effect after the staff member's previous change (2026-07-01).");

        // A component added in August and removed in September cannot be
        // re-added inside its closed August row (two amounts on one day).
        StaffPayItemSync::sync($staff, [['pay_component_id' => $salary, 'amount' => '35000'], ['pay_component_id' => $allowance, 'amount' => '2000']], '2026-08-01');
        StaffPayItemSync::sync($staff, [['pay_component_id' => $salary, 'amount' => '35000']], '2026-09-01');

        expect(fn () => StaffPayItemSync::sync($staff, [['pay_component_id' => $salary, 'amount' => '35000'], ['pay_component_id' => $allowance, 'amount' => '2500']], '2026-08-15'))
            ->toThrow(InvalidArgumentException::class, '(2026-08-31)');

        // Nothing was written by the refused changes.
        expect($inForce('2026-06-15'))->toBe([$salary => '30000.00']);
        expect($inForce('2026-08-15'))->toBe([$salary => '35000.00', $allowance => '2000.00']);
        expect($inForce('2026-09-15'))->toBe([$salary => '35000.00']);

        // Same day as the current salary row: corrected in place, no new row.
        StaffPayItemSync::sync($staff, [['pay_component_id' => $salary, 'amount' => '36000']], '2026-07-01');

        expect($inForce('2026-07-15'))->toBe([$salary => '36000.00']);
        expect($inForce('2026-06-15'))->toBe([$salary => '30000.00']);
        expect(StaffPayItem::where('staff_id', $staff->id)->where('pay_component_id', $salary)->count())->toBe(2);
        expect(StaffPayItem::where('staff_id', $staff->id)->count())->toBe(3);
    });
});

test('a draft uses the latest-starting pay row when legacy data has two in force', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember('30000.00');
        StaffPayItem::factory()->create(['staff_id' => $staff->id, 'amount' => '32000.00', 'effective_from' => '2026-04-01']);

        $run = hrDraft($actor);

        expect($run->lines)->toHaveCount(1);
        expect($run->total_gross)->toBe('32000.00');
    });
});

test('reconciliation as at a date before a cancellation still agrees with the ledger', function () {
    $this->travelTo(Carbon::parse('2026-09-15 06:00:00'));

    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember('30000.00');
        $advance = StaffAdvancePoster::post(['staff_id' => $staff->id, 'date' => '2026-06-10', 'amount' => '5000', 'payment_mode' => 'cash'], $actor);
        $run = PayrollPoster::post(hrDraft($actor), $actor);

        // Both reversals are dated today, 15 September.
        PayrollPoster::cancel($run, $actor, 'Wrong month');
        StaffAdvancePoster::cancel($advance, $actor, 'Not given');

        $july = StaffBalances::reconciliation(FiscalYear::first(), '2026-07-31');

        expect($july['payable'])->toBe(['ledger' => '30000.00', 'staff' => '30000.00', 'difference' => '0.00']);
        expect($july['advance'])->toBe(['ledger' => '5000.00', 'staff' => '5000.00', 'difference' => '0.00']);
        expect(StaffBalances::payable($staff->id, '2026-07-31')->toString())->toBe('30000.00');

        $after = StaffBalances::reconciliation(FiscalYear::first(), '2026-09-30');

        expect($after['payable'])->toBe(['ledger' => '0.00', 'staff' => '0.00', 'difference' => '0.00']);
        expect($after['advance'])->toBe(['ledger' => '0.00', 'staff' => '0.00', 'difference' => '0.00']);
        expect(StaffBalances::payable($staff->id)->toString())->toBe('0.00');
        expect(StaffBalances::advance($staff->id)->toString())->toBe('0.00');
    });
});

test('a run with no advance recovery posts even when the staff advance balance is below zero', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember('30000.00');

        // Hand-written May run that "recovered" an advance never given, so the
        // staff member's advance balance reads -500.00.
        $may = PayrollRun::create([
            'period_label' => 'Baisakh', 'period_start' => '2026-05-01', 'period_end' => '2026-05-31', 'date' => '2026-05-31',
            'status' => PayrollRun::STATUS_POSTED, 'branch_id' => $staff->branch_id, 'created_by' => $actor->id,
        ]);
        $may->lines()->create(['staff_id' => $staff->id, 'branch_id' => $staff->branch_id, 'gross' => '500.00', 'advance_recovery' => '500.00', 'net' => '0.00']);

        expect(StaffBalances::advance($staff->id)->toString())->toBe('-500.00');

        $run = PayrollPoster::post(hrDraft($actor), $actor);

        expect($run->status)->toBe('posted');
        expect(hrLedger('LIA22'))->toBe(['debit' => '0.00', 'credit' => '30000.00']);
    });
});

test('a run number is unique within its fiscal year', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        hrStaffMember();
        hrStaffMember('20000.00');
        $first = PayrollPoster::post(hrDraft($actor), $actor);

        expect(fn () => PayrollRun::factory()->create([
            'fiscal_year_id' => $first->fiscal_year_id,
            'run_number' => $first->run_number,
        ]))->toThrow(QueryException::class);
    });
});

test('a very long cancel reason is cut to the column size instead of breaking the cancel', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember('30000.00');
        $reason = str_repeat('न', 400);

        $run = PayrollPoster::post(hrDraft($actor), $actor);
        $payment = SalaryPaymentPoster::post(hrCashPayment($run->lines->first(), '1000'), $actor);
        $advance = StaffAdvancePoster::post(['staff_id' => $staff->id, 'date' => '2026-06-10', 'amount' => '500', 'payment_mode' => 'cash'], $actor);

        $documents = [
            SalaryPaymentPoster::cancel($payment, $actor, $reason),
            PayrollPoster::cancel($run, $actor, $reason),
            StaffAdvancePoster::cancel($advance, $actor, $reason),
        ];

        foreach ($documents as $document) {
            $document->refresh();

            expect($document->status)->toBe('cancelled');
            expect(mb_strlen($document->cancel_reason))->toBe(255);

            $reversal = JournalVoucher::findOrFail($document->reversal_journal_voucher_id);

            expect($reversal->narration)->toStartWith('Cancellation of ');
            expect(mb_strlen($reversal->narration))->toBeLessThanOrEqual(255);
        }
    });
});

test('one salary payment settles several payslips, merges repeated allocations and posts one balanced voucher', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $first = hrStaffMember('30000.00');
        $second = hrStaffMember('20000.00');
        $run = PayrollPoster::post(hrDraft($actor), $actor);
        $firstLine = $run->lines->firstWhere('staff_id', $first->id);
        $secondLine = $run->lines->firstWhere('staff_id', $second->id);

        $payment = SalaryPaymentPoster::post([
            'date' => '2026-07-05',
            'payment_mode' => 'cash',
            'allocations' => [
                ['payroll_line_id' => $firstLine->id, 'amount' => '8000'],
                ['payroll_line_id' => $secondLine->id, 'amount' => '5000.50'],
                ['payroll_line_id' => $firstLine->id, 'amount' => '22000'],
            ],
        ], $actor);

        expect($payment->amount)->toBe('35000.50');
        expect($payment->lines()->count())->toBe(2);
        expect($payment->lines()->where('payroll_line_id', $firstLine->id)->value('amount'))->toBe('30000.00');

        $voucherLines = $payment->journalVoucher->lines;
        expect($voucherLines)->toHaveCount(2);
        expect(Money::sum($voucherLines->pluck('debit'))->toString())->toBe('35000.50');
        expect(Money::sum($voucherLines->pluck('credit'))->toString())->toBe('35000.50');

        expect(StaffBalances::payable($first->id)->toString())->toBe('0.00');
        expect(StaffBalances::payable($second->id)->toString())->toBe('14999.50');

        // The second payslip is over-paid by a paisa within one request: nothing is saved.
        $before = $payment->newQuery()->count();
        expect(fn () => SalaryPaymentPoster::post([
            'date' => '2026-07-06',
            'payment_mode' => 'cash',
            'allocations' => [
                ['payroll_line_id' => $secondLine->id, 'amount' => '10000.00'],
                ['payroll_line_id' => $secondLine->id, 'amount' => '4999.51'],
            ],
        ], $actor))->toThrow(InvalidArgumentException::class, 'still owed');
        expect($payment->newQuery()->count())->toBe($before);
        expect(StaffBalances::payable($second->id)->toString())->toBe('14999.50');
    });
});

test('a payment or advance cannot be cancelled twice, and an advance can be cancelled while other advance still covers the recovery', function () {
    hrTenant()->run(function () {
        $actor = hrOpenYear();
        $staff = hrStaffMember('30000.00');
        $first = StaffAdvancePoster::post(['staff_id' => $staff->id, 'date' => '2026-06-05', 'amount' => '1000', 'payment_mode' => 'cash'], $actor);
        StaffAdvancePoster::post(['staff_id' => $staff->id, 'date' => '2026-06-06', 'amount' => '5000', 'payment_mode' => 'cash'], $actor);

        $draft = hrDraft($actor);
        PayrollDraft::replace($draft, [
            'period_label' => 'Asar 2083', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'date' => '2026-06-30',
            'lines' => [['staff_id' => $staff->id, 'advance_recovery' => '2000', 'items' => [['pay_component_id' => hrComponent('SALARY'), 'amount' => '30000.00']]]],
        ]);
        $run = PayrollPoster::post($draft, $actor);

        // 6000 given, 2000 recovered: 4000 is left, which covers cancelling the 1000 one.
        StaffAdvancePoster::cancel($first, $actor, 'Wrong staff');
        expect(StaffBalances::advance($staff->id)->toString())->toBe('3000.00');
        expect(fn () => StaffAdvancePoster::cancel($first, $actor, 'Again'))->toThrow(InvalidArgumentException::class, 'already been cancelled');

        $payment = SalaryPaymentPoster::post(hrCashPayment($run->lines->first(), '1000'), $actor);
        SalaryPaymentPoster::cancel($payment, $actor, 'Bounced');
        expect(fn () => SalaryPaymentPoster::cancel($payment, $actor, 'Again'))->toThrow(InvalidArgumentException::class, 'already been cancelled');
        expect(StaffBalances::payable($staff->id)->toString())->toBe('28000.00');
    });
});
