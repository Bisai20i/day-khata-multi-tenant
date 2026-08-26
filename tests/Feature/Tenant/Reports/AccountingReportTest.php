<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionAccountingReportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAccountingReportTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

/**
 * Posts a 1000 cash sale and a 400 cash purchase into FY1, giving:
 * Cash (AS1) = 600 debit, Sales (INI20) = 1000 credit, Purchases (EXE8) =
 * 400 debit, net profit = 600.
 */
function postAccountingReportFixture(FiscalYear $fy1, User $actor): void
{
    $cash = Account::where('code', 'AS1')->firstOrFail();
    $sales = Account::where('code', 'INI20')->firstOrFail();
    $purchases = Account::where('code', 'EXE8')->firstOrFail();

    JournalVoucher::post(
        ['date' => '2026-03-01', 'narration' => 'Cash sale'],
        [
            ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $sales->id, 'debit' => 0, 'credit' => 1000],
        ],
        $actor,
    );

    JournalVoucher::post(
        ['date' => '2026-04-01', 'narration' => 'Cash purchase'],
        [
            ['account_id' => $purchases->id, 'debit' => 400, 'credit' => 0],
            ['account_id' => $cash->id, 'debit' => 0, 'credit' => 400],
        ],
        $actor,
    );
}

test('trial balance is always in balance and survives year-end closing', function () {
    $domain = 'report-trial-balance.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $fy1Id = null;
    $fy2Id = null;
    $tenant->run(function () use (&$fy1Id, &$fy2Id) {
        $admin = User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fy1Id = $fy1->id;
        $fy2Id = $fy2->id;

        postAccountingReportFixture($fy1, $admin);
    });

    loginAccountingReportTestUser($domain);

    $this->get("http://{$domain}/reports/trial-balance?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/TrialBalance')
            ->where('totalDebit', 1000)
            ->where('totalCredit', 1000));

    // Close FY1 into FY2, then confirm FY1's trial balance still shows the
    // real activity (not zeroed out by the ClosingEntry voucher posted into
    // FY1 itself).
    $tenant->run(function () use ($fy1Id, $fy2Id) {
        $admin = User::where('email', 'owner@example.com')->firstOrFail();
        FiscalYear::find($fy1Id)->close(FiscalYear::find($fy2Id), $admin);
    });

    $this->get("http://{$domain}/reports/trial-balance?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totalDebit', 1000)
            ->where('totalCredit', 1000));

    $tenant->delete();
});

test('income statement nets income and expenses, and survives year-end closing', function () {
    $domain = 'report-income-statement.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $fy1Id = null;
    $fy2Id = null;
    $tenant->run(function () use (&$fy1Id, &$fy2Id) {
        $admin = User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fy1Id = $fy1->id;
        $fy2Id = $fy2->id;

        postAccountingReportFixture($fy1, $admin);
    });

    loginAccountingReportTestUser($domain);

    $this->get("http://{$domain}/reports/income-statement?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/IncomeStatement')
            ->where('totalIncome', 1000)
            ->where('totalExpenses', 400)
            ->where('netProfit', 600));

    // Regression: without excluding the ClosingEntry voucher, a closed
    // year's income statement would wrongly report a net profit of 0.
    $tenant->run(function () use ($fy1Id, $fy2Id) {
        $admin = User::where('email', 'owner@example.com')->firstOrFail();
        FiscalYear::find($fy1Id)->close(FiscalYear::find($fy2Id), $admin);
    });

    $this->get("http://{$domain}/reports/income-statement?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('netProfit', 600));

    $tenant->delete();
});

test('balance sheet balances while the fiscal year is open (via the current-year-earnings line) and after closing', function () {
    $domain = 'report-balance-sheet.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $fy1Id = null;
    $fy2Id = null;
    $tenant->run(function () use (&$fy1Id, &$fy2Id) {
        $admin = User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fy1Id = $fy1->id;
        $fy2Id = $fy2->id;

        postAccountingReportFixture($fy1, $admin);
    });

    loginAccountingReportTestUser($domain);

    // While FY1 is still open: Cash (Assets) = 600, nothing swept into
    // Capital yet, so the report must add the 600-profit "current year
    // earnings" virtual line to balance.
    $this->get("http://{$domain}/reports/balance-sheet?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/BalanceSheet')
            ->where('totalAssets', 600)
            ->where('currentYearEarnings', 600)
            ->where('totalLiabilitiesAndCapital', 600));

    // After closing: the real ClosingEntry voucher has posted the same 600
    // into "Profit & Loss" (a Capital account) within FY1 itself - the
    // virtual line must disappear and the real balance must still hold.
    $tenant->run(function () use ($fy1Id, $fy2Id) {
        $admin = User::where('email', 'owner@example.com')->firstOrFail();
        FiscalYear::find($fy1Id)->close(FiscalYear::find($fy2Id), $admin);
    });

    $this->get("http://{$domain}/reports/balance-sheet?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('currentYearEarnings', 0)
            ->where('totalAssets', 600)
            ->where('totalLiabilitiesAndCapital', 600));

    $tenant->delete();
});

test('the three accounting report routes render their expected components with no fiscal year yet', function () {
    $domain = 'report-routes-no-fy.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginAccountingReportTestUser($domain);

    $this->get("http://{$domain}/reports/trial-balance")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Reports/TrialBalance')->where('fiscalYearId', null));

    $this->get("http://{$domain}/reports/income-statement")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Reports/IncomeStatement')->where('fiscalYearId', null));

    $this->get("http://{$domain}/reports/balance-sheet")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Reports/BalanceSheet')->where('fiscalYearId', null));

    $tenant->delete();
});
