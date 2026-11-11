<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\PayComponent;
use App\Models\PayrollLine;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\StaffAdvance;
use App\Models\StaffMember;
use App\Models\StaffPayItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * HTTP-level coverage of the HR routes: entitlement and permission gates, the
 * happy path through the controllers, the manual-voucher guard on the control
 * accounts and the Balance Sheet result. The posting rules themselves are in
 * PayrollLedgerTest.
 */
function hrHttpTenant(string $domain, ?array $modules = null): Tenant
{
    $tenant = Tenant::create([
        'company_name' => 'Acme Co',
        'enabled_modules' => $modules ?? config('permissions.default_modules'),
    ]);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function hrHttpLogin(string $domain, string $email = 'owner@example.com'): void
{
    test()->post("http://{$domain}/login", ['email' => $email, 'password' => 'password']);
}

function hrHttpSeed(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    User::factory()->create(['email' => 'owner@example.com']);
}

/**
 * The Salary Payable row on the Balance Sheet carries the amount still owed.
 * (assertSee would pass regardless: the sheet lists every account, zero
 * balances included.)
 */
function hrHttpAssertSalaryPayable(string $domain, string $credit): void
{
    test()->get("http://{$domain}/reports/balance-sheet")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/BalanceSheet')
            ->where('heads', function (Collection $heads) use ($credit) {
                $rows = collect($heads)->flatMap(fn (array $head) => collect($head['groups'])->flatMap(
                    fn (array $group) => [...$group['accounts'], ...collect($group['subgroups'])->flatMap(fn (array $sub) => $sub['accounts'])->all()],
                ));
                $row = $rows->firstWhere('code', 'LIA22');

                return $row !== null && $row['credit'] === $credit && $row['debit'] === '0.00';
            }));
}

test('a tenant without the hr module cannot reach any HR page', function () {
    $domain = 'hr-not-entitled.tenant-test';
    $tenant = hrHttpTenant($domain, array_values(array_diff(config('permissions.default_modules'), ['hr'])));
    $tenant->run(fn () => hrHttpSeed());

    hrHttpLogin($domain);

    foreach (['/hr/staff', '/hr/payroll', '/hr/salary-payments', '/hr/staff-advances', '/hr/pay-components', '/hr/reports/staff-balances'] as $uri) {
        test()->get("http://{$domain}{$uri}")->assertForbidden();
    }
});

test('every HR route is forbidden without its permission', function () {
    $domain = 'hr-http-403.tenant-test';
    $tenant = hrHttpTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        hrHttpSeed();
        $staff = StaffMember::factory()->create();
        $ids = [
            'staff' => $staff->id,
            'run' => PayrollRun::factory()->create(['created_by' => User::first()->id])->id,
            'component' => PayComponent::first()->id,
        ];
    });

    $all = [
        'hr_staff.view', 'hr_staff.create', 'hr_staff.edit', 'pay_components.view', 'pay_components.manage',
        'payroll.view', 'payroll.create', 'payroll.manage', 'payroll.cancel',
        'payroll_payments.view', 'payroll_payments.create', 'payroll_payments.cancel',
        'staff_advances.view', 'staff_advances.create', 'staff_advances.cancel',
        'hr_reports.view', 'hr_reports.export',
    ];

    $routes = [
        ['get', '/hr/staff', 'hr_staff.view'],
        ['get', '/hr/staff/create', 'hr_staff.create'],
        ['post', '/hr/staff', 'hr_staff.create'],
        ['get', "/hr/staff/{$ids['staff']}/edit", 'hr_staff.view'],
        ['put', "/hr/staff/{$ids['staff']}", 'hr_staff.edit'],
        ['get', "/hr/staff/{$ids['staff']}/statement", 'hr_reports.view'],
        ['get', '/hr/pay-components', 'pay_components.view'],
        ['post', '/hr/pay-components', 'pay_components.manage'],
        ['put', "/hr/pay-components/{$ids['component']}", 'pay_components.manage'],
        ['get', '/hr/payroll', 'payroll.view'],
        ['get', '/hr/payroll/create', 'payroll.create'],
        ['post', '/hr/payroll', 'payroll.create'],
        ['get', "/hr/payroll/{$ids['run']}", 'payroll.view'],
        ['put', "/hr/payroll/{$ids['run']}", 'payroll.create'],
        ['delete', "/hr/payroll/{$ids['run']}", 'payroll.create'],
        ['post', "/hr/payroll/{$ids['run']}/post", 'payroll.manage'],
        ['post', "/hr/payroll/{$ids['run']}/cancel", 'payroll.cancel'],
        ['get', '/hr/salary-payments', 'payroll_payments.view'],
        ['post', '/hr/salary-payments', 'payroll_payments.create'],
        ['get', '/hr/staff-advances', 'staff_advances.view'],
        ['post', '/hr/staff-advances', 'staff_advances.create'],
        ['get', '/hr/reports/payroll-register', 'hr_reports.view'],
        ['get', '/hr/reports/payroll-register/export', 'hr_reports.export'],
        ['get', '/hr/reports/staff-balances', 'hr_reports.view'],
        ['get', '/hr/reports/staff-balances/export', 'hr_reports.export'],
    ];

    $users = [];

    foreach ($routes as [$method, $uri, $permission]) {
        $email = 'no-'.str_replace('.', '-', $permission).'@example.com';

        // One role/user per withheld permission, reused by the routes that
        // share it. actingAs (not the login form) keeps this matrix clear of
        // the login throttle, which would answer 429 after five attempts.
        $users[$permission] ??= $tenant->run(fn () => User::factory()->create([
            'email' => $email,
            'role_id' => roleWithPermissions(array_values(array_diff($all, [$permission])), 'Role '.$permission)->id,
            'is_owner' => false,
        ]));

        auth()->forgetGuards();
        test()->actingAs($users[$permission], 'web');
        tenancy()->end();

        test()->{$method}("http://{$domain}{$uri}", [])->assertForbidden();
    }
});

test('staff can be added with recurring pay and listed', function () {
    $domain = 'hr-http-staff.tenant-test';
    $tenant = hrHttpTenant($domain);
    $tenant->run(fn () => hrHttpSeed());

    hrHttpLogin($domain);

    $salary = $tenant->run(fn () => PayComponent::where('code', 'SALARY')->value('id'));

    test()->post("http://{$domain}/hr/staff", [
        'name' => 'Asha Gurung',
        'designation' => 'Cashier',
        'pay_effective_from' => '2026-01-01',
        'pay_items' => [['pay_component_id' => $salary, 'amount' => '28000']],
    ])->assertRedirect();

    $tenant->run(function () use ($salary) {
        $staff = StaffMember::where('name', 'Asha Gurung')->firstOrFail();

        expect($staff->staff_code)->toStartWith('STF-');
        expect($staff->is_active)->toBeTrue();
        // A new staff member's home branch is Main until the form offers a picker.
        expect((int) $staff->branch_id)->toBe(Branch::main()->id);
        expect(StaffPayItem::where('staff_id', $staff->id)->where('pay_component_id', $salary)->value('amount'))->toBe('28000.00');
    });

    test()->get("http://{$domain}/hr/staff")->assertOk()->assertSee('Asha Gurung');

    // A third decimal is refused rather than rounded.
    test()->post("http://{$domain}/hr/staff", [
        'name' => 'Bad Pay',
        'pay_effective_from' => '2026-01-01',
        'pay_items' => [['pay_component_id' => $salary, 'amount' => '100.123']],
    ])->assertSessionHasErrors('pay_items.0.amount');
});

test('payroll goes from draft to posted to paid through the routes and shows on the balance sheet', function () {
    $domain = 'hr-http-flow.tenant-test';
    $tenant = hrHttpTenant($domain);
    $staffId = null;
    $tenant->run(function () use (&$staffId) {
        hrHttpSeed();
        $staff = StaffMember::factory()->create(['name' => 'Rita Sharma']);
        StaffPayItem::factory()->create(['staff_id' => $staff->id, 'amount' => '40000.00']);
        $staffId = $staff->id;
    });

    hrHttpLogin($domain);

    test()->post("http://{$domain}/hr/payroll", [
        'period_label' => 'Asar 2083',
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'date' => '2026-06-30',
    ])->assertRedirect();

    $runId = $tenant->run(fn () => PayrollRun::firstOrFail()->id);

    test()->get("http://{$domain}/hr/payroll/{$runId}")->assertOk()->assertSee('Rita Sharma');
    test()->post("http://{$domain}/hr/payroll/{$runId}/post")->assertSessionHasNoErrors();

    $lineId = $tenant->run(function () use ($runId) {
        $run = PayrollRun::findOrFail($runId);
        expect($run->status)->toBe('posted');

        return PayrollLine::where('payroll_run_id', $runId)->value('id');
    });

    // Unpaid salary is a Payable on the Balance Sheet: the whole net is owed.
    hrHttpAssertSalaryPayable($domain, '40000.00');

    test()->post("http://{$domain}/hr/salary-payments", [
        'date' => '2026-07-05',
        'payment_mode' => 'cash',
        'allocations' => [['payroll_line_id' => $lineId, 'amount' => '15000']],
    ])->assertSessionHasNoErrors();

    // Part-paid: only the unpaid remainder stays a Payable.
    hrHttpAssertSalaryPayable($domain, '25000.00');

    test()->post("http://{$domain}/hr/salary-payments", [
        'date' => '2026-07-06',
        'payment_mode' => 'cash',
        'allocations' => [['payroll_line_id' => $lineId, 'amount' => '25000.01']],
    ])->assertSessionHasErrors('allocations');

    test()->get("http://{$domain}/hr/reports/staff-balances?as_of=2026-12-31")
        ->assertOk()
        ->assertSee('Rita Sharma')
        ->assertSee('25000.00');

    test()->get("http://{$domain}/hr/reports/payroll-register")->assertOk()->assertSee('Rita Sharma');
    test()->get("http://{$domain}/hr/staff/{$staffId}/statement")->assertOk()->assertSee('Salary payment');

    // Cancelling a payment needs its own permission.
    $paymentId = $tenant->run(fn () => PayrollPayment::firstOrFail()->id);
    $limited = $tenant->run(fn () => User::factory()->create([
        'email' => 'no-payment-cancel@example.com',
        'role_id' => roleWithPermissions(['payroll_payments.view', 'payroll_payments.create'], 'No payment cancel')->id,
        'is_owner' => false,
    ]));
    auth()->forgetGuards();
    test()->actingAs($limited, 'web');
    tenancy()->end();

    test()->post("http://{$domain}/hr/salary-payments/{$paymentId}/cancel", ['reason' => 'Not allowed'])->assertForbidden();
});

test('a staff advance is given and cancelled through the routes', function () {
    $domain = 'hr-http-advance.tenant-test';
    $tenant = hrHttpTenant($domain);
    $staffId = null;
    $tenant->run(function () use (&$staffId) {
        hrHttpSeed();
        $staffId = StaffMember::factory()->create()->id;
    });

    hrHttpLogin($domain);

    test()->post("http://{$domain}/hr/staff-advances", [
        'staff_id' => $staffId,
        'date' => '2026-06-10',
        'amount' => '3000',
        'payment_mode' => 'cash',
    ])->assertSessionHasNoErrors();

    $advanceId = $tenant->run(fn () => StaffAdvance::firstOrFail()->id);

    test()->post("http://{$domain}/hr/staff-advances/{$advanceId}/cancel", ['reason' => 'Entered twice'])->assertSessionHasNoErrors();

    $tenant->run(fn () => expect(StaffAdvance::findOrFail($advanceId)->status)->toBe('cancelled'));

    // A reason is mandatory.
    test()->post("http://{$domain}/hr/staff-advances/{$advanceId}/cancel", [])->assertSessionHasErrors('reason');

    // Cancelling an advance needs its own permission.
    $limited = $tenant->run(fn () => User::factory()->create([
        'email' => 'no-advance-cancel@example.com',
        'role_id' => roleWithPermissions(['staff_advances.view', 'staff_advances.create'], 'No advance cancel')->id,
        'is_owner' => false,
    ]));
    auth()->forgetGuards();
    test()->actingAs($limited, 'web');
    tenancy()->end();

    test()->post("http://{$domain}/hr/staff-advances/{$advanceId}/cancel", ['reason' => 'Not allowed'])->assertForbidden();
});

test('the salary payable control account cannot be hand-posted from a journal or cash voucher', function () {
    $domain = 'hr-http-control.tenant-test';
    $tenant = hrHttpTenant($domain);
    $ids = [];
    $tenant->run(function () use (&$ids) {
        hrHttpSeed();
        $ids = [
            'payable' => Account::where('code', 'LIA22')->value('id'),
            'cash' => Account::where('code', 'AS1')->value('id'),
        ];
    });

    hrHttpLogin($domain);

    test()->post("http://{$domain}/journal-vouchers", [
        'date' => '2026-06-10',
        'narration' => 'Manual salary',
        'lines' => [
            ['account_id' => $ids['payable'], 'debit' => '0', 'credit' => '100'],
            ['account_id' => $ids['cash'], 'debit' => '100', 'credit' => '0'],
        ],
    ])->assertSessionHasErrors('lines');

    $tenant->run(fn () => expect(JournalVoucher::count())->toBe(0));
});

test('amounts beyond the ledger column and inverted report date ranges are refused instead of failing in the database', function () {
    $domain = 'hr-http-bounds.tenant-test';
    $tenant = hrHttpTenant($domain);
    $ids = [];
    $tenant->run(function () use (&$ids) {
        hrHttpSeed();
        $ids = [
            'staff' => StaffMember::factory()->create()->id,
            'run' => PayrollRun::factory()->create(['created_by' => User::first()->id])->id,
            'component' => PayComponent::where('code', 'SALARY')->value('id'),
        ];
    });

    hrHttpLogin($domain);

    test()->post("http://{$domain}/hr/staff-advances", [
        'staff_id' => $ids['staff'],
        'date' => '2026-06-10',
        'amount' => '100000000000000000000',
        'payment_mode' => 'cash',
    ])->assertSessionHasErrors('amount');

    test()->post("http://{$domain}/hr/salary-payments", [
        'date' => '2026-07-05',
        'payment_mode' => 'cash',
        'allocations' => [['payroll_line_id' => 1, 'amount' => '100000000000000000000']],
    ])->assertSessionHasErrors('allocations.0.amount');

    test()->put("http://{$domain}/hr/payroll/{$ids['run']}", [
        'period_label' => 'Asar 2083',
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'date' => '2026-06-30',
        'lines' => [[
            'staff_id' => $ids['staff'],
            'advance_recovery' => '100000000000000000000',
            'items' => [['pay_component_id' => $ids['component'], 'amount' => '1000']],
        ]],
    ])->assertSessionHasErrors('lines.0.advance_recovery');

    test()->get("http://{$domain}/hr/reports/payroll-register?from=2026-06-30&to=2026-06-01")->assertSessionHasErrors('to');
    test()->get("http://{$domain}/hr/payroll?from=2026-06-30&to=2026-06-01")->assertSessionHasErrors('to');
});

test('creating and renaming a pay component writes activity log rows', function () {
    $domain = 'hr-http-component-log.tenant-test';
    $tenant = hrHttpTenant($domain);
    $expenseAccountId = null;
    $tenant->run(function () use (&$expenseAccountId) {
        hrHttpSeed();
        $expenseAccountId = PayComponent::where('code', 'SALARY')->value('expense_account_id');
    });

    hrHttpLogin($domain);

    test()->post("http://{$domain}/hr/pay-components", [
        'code' => 'overtime',
        'name' => 'Overtime',
        'expense_account_id' => $expenseAccountId,
    ])->assertSessionHasNoErrors();

    $componentId = $tenant->run(fn () => PayComponent::where('code', 'OVERTIME')->firstOrFail()->id);

    test()->put("http://{$domain}/hr/pay-components/{$componentId}", [
        'name' => 'Overtime Pay',
        'expense_account_id' => $expenseAccountId,
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    $tenant->run(function () use ($componentId) {
        $subjectType = (new PayComponent)->getMorphClass();

        $created = ActivityLog::where('subject_type', $subjectType)->where('subject_id', $componentId)->where('action', 'created')->first();
        expect($created)->not->toBeNull()
            ->and($created->user_id)->not->toBeNull();

        $updated = ActivityLog::where('subject_type', $subjectType)->where('subject_id', $componentId)->where('action', 'updated')->first();
        expect($updated)->not->toBeNull()
            ->and($updated->changes)->toHaveKey('name');
    });
});

test('the staff statement can be limited to a date range and carries the earlier balance in as an opening balance', function () {
    $domain = 'hr-http-statement.tenant-test';
    $tenant = hrHttpTenant($domain);
    $staffId = null;
    $tenant->run(function () use (&$staffId) {
        hrHttpSeed();
        $staff = StaffMember::factory()->create(['name' => 'Rita Sharma']);
        StaffPayItem::factory()->create(['staff_id' => $staff->id, 'amount' => '40000.00']);
        $staffId = $staff->id;
    });

    hrHttpLogin($domain);

    test()->post("http://{$domain}/hr/payroll", [
        'period_label' => 'Asar 2083',
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'date' => '2026-06-30',
    ])->assertRedirect();
    $runId = $tenant->run(fn () => PayrollRun::firstOrFail()->id);
    test()->post("http://{$domain}/hr/payroll/{$runId}/post")->assertSessionHasNoErrors();
    $lineId = $tenant->run(fn () => PayrollLine::where('payroll_run_id', $runId)->value('id'));

    test()->post("http://{$domain}/hr/staff-advances", [
        'staff_id' => $staffId, 'date' => '2026-06-10', 'amount' => '3000', 'payment_mode' => 'cash',
    ])->assertSessionHasNoErrors();
    test()->post("http://{$domain}/hr/staff-advances", [
        'staff_id' => $staffId, 'date' => '2026-07-10', 'amount' => '1000', 'payment_mode' => 'cash',
    ])->assertSessionHasNoErrors();
    test()->post("http://{$domain}/hr/salary-payments", [
        'date' => '2026-07-05',
        'payment_mode' => 'cash',
        'allocations' => [['payroll_line_id' => $lineId, 'amount' => '15000']],
    ])->assertSessionHasNoErrors();

    // No dates: every posted document, running from zero - as before the filter existed.
    test()->get("http://{$domain}/hr/staff/{$staffId}/statement")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Hr/Reports/StaffStatement')
            ->where('filters', ['from' => null, 'to' => null])
            ->where('openingSalary', '0.00')
            ->where('openingAdvance', '0.00')
            ->has('salaryEntries', 2)
            ->where('salaryEntries.0.balance', '40000.00')
            ->where('salaryEntries.1.balance', '25000.00')
            ->has('advanceEntries', 2)
            ->where('advanceEntries.1.balance', '4000.00'));

    // From July: June's payroll and advance are carried in, and the balance continues from them.
    test()->get("http://{$domain}/hr/staff/{$staffId}/statement?from=2026-07-01&to=2026-07-31")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters', ['from' => '2026-07-01', 'to' => '2026-07-31'])
            ->where('openingSalary', '40000.00')
            ->where('openingAdvance', '3000.00')
            ->has('salaryEntries', 1)
            ->where('salaryEntries.0.description', 'Salary payment (cash)')
            ->where('salaryEntries.0.balance', '25000.00')
            ->has('advanceEntries', 1)
            ->where('advanceEntries.0.balance', '4000.00'));

    // A window with no activity shows just the opening balance.
    test()->get("http://{$domain}/hr/staff/{$staffId}/statement?from=2026-08-01")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('openingSalary', '25000.00')
            ->where('openingAdvance', '4000.00')
            ->has('salaryEntries', 0)
            ->has('advanceEntries', 0));

    // Only an end date: nothing is carried in, later documents are left out.
    test()->get("http://{$domain}/hr/staff/{$staffId}/statement?to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('openingSalary', '0.00')
            ->has('salaryEntries', 1)
            ->where('salaryEntries.0.balance', '40000.00')
            ->has('advanceEntries', 1));

    test()->get("http://{$domain}/hr/staff/{$staffId}/statement?from=2026-07-31&to=2026-07-01")->assertSessionHasErrors('to');
});

test('the bank pickers on salary payments and staff advances leave out party ledger and HR control accounts', function () {
    $domain = 'hr-http-bank-picker.tenant-test';
    $tenant = hrHttpTenant($domain);
    $ids = [];
    $tenant->run(function () use (&$ids) {
        hrHttpSeed();
        $customer = Customer::factory()->create();
        expect($customer->account->subgroup->name)->toBe('Sundry Debtors');
        // Accounts added from the chart screen may have no code; NOT IN would drop NULLs.
        $uncoded = Account::create([
            'account_group_id' => AccountGroup::where('name', 'Current Assets')->value('id'),
            'account_subgroup_id' => null,
            'name' => 'Petty Bank Uncoded',
        ]);
        $ids = [
            'uncoded' => $uncoded->id,
            'party' => $customer->account_id,
            'cash' => Account::where('code', 'AS1')->value('id'),
            'advances' => Account::where('code', 'ASA24')->value('id'),
            'staff' => StaffMember::factory()->create()->id,
        ];
    });

    hrHttpLogin($domain);

    foreach (['salary-payments', 'staff-advances'] as $page) {
        test()->get("http://{$domain}/hr/{$page}")
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia->where('bankAccounts', function ($accounts) use ($ids) {
                $accountIds = collect($accounts)->pluck('id');

                return $accountIds->contains($ids['cash'])
                    && $accountIds->contains($ids['uncoded'])
                    && ! $accountIds->contains($ids['party'])
                    && ! $accountIds->contains($ids['advances']);
            }));
    }

    // The save side still refuses a party ledger as the bank account.
    test()->post("http://{$domain}/hr/staff-advances", [
        'staff_id' => $ids['staff'],
        'date' => '2026-06-10',
        'amount' => '3000',
        'payment_mode' => 'bank',
        'bank_account_id' => $ids['party'],
    ])->assertSessionHasErrors('bank_account_id');
});
