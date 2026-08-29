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

test('day book lists vouchers within the range and excludes vouchers outside it', function () {
    $domain = 'report-day-book.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $sales = Account::where('code', 'INI20')->firstOrFail();

        // In range (2026-02-01..2026-04-01).
        JournalVoucher::post(
            ['date' => '2026-03-01', 'narration' => 'In-range cash sale'],
            [
                ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 1000],
            ],
            $admin,
        );

        // Out of range.
        JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Out-of-range cash sale'],
            [
                ['account_id' => $cash->id, 'debit' => 500, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 500],
            ],
            $admin,
        );
    });

    loginAccountingReportTestUser($domain);

    $this->get("http://{$domain}/reports/day-book?from=2026-02-01&to=2026-04-01")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/DayBook')
            ->has('vouchers', 1)
            ->where('vouchers.0.narration', 'In-range cash sale')
            ->where('totalDebit', 1000)
            ->where('totalCredit', 1000));

    $tenant->delete();
});

test('cash book computes an exact opening balance carried from before the range and a correct running balance within it', function () {
    $domain = 'report-cash-book.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $sales = Account::where('code', 'INI20')->firstOrFail();
        $purchases = Account::where('code', 'EXE8')->firstOrFail();

        // Before the range: opening balance should be exactly 1000.
        JournalVoucher::post(
            ['date' => '2026-01-01', 'narration' => 'Pre-range cash sale'],
            [
                ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 1000],
            ],
            $admin,
        );

        // Within the range: 1000 - 400 = 600, then 600 + 200 = 800.
        JournalVoucher::post(
            ['date' => '2026-02-15', 'narration' => 'In-range cash purchase'],
            [
                ['account_id' => $purchases->id, 'debit' => 400, 'credit' => 0],
                ['account_id' => $cash->id, 'debit' => 0, 'credit' => 400],
            ],
            $admin,
        );

        JournalVoucher::post(
            ['date' => '2026-02-20', 'narration' => 'In-range cash sale'],
            [
                ['account_id' => $cash->id, 'debit' => 200, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 200],
            ],
            $admin,
        );
    });

    loginAccountingReportTestUser($domain);

    $this->get("http://{$domain}/reports/cash-book?from=2026-02-01&to=2026-03-01")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/CashBook')
            ->where('openingBalance', 1000)
            ->has('entries', 2)
            ->where('entries.0.balance', 600)
            ->where('entries.1.balance', 800)
            ->where('closingBalance', 800));

    $tenant->delete();
});

test('bank book scopes to the selected account only and excludes the Cash In Hand account from its picker', function () {
    $domain = 'report-bank-book.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $bankAccountId = null;

    $tenant->run(function () use (&$bankAccountId) {
        $admin = User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $bank = Account::where('code', 'LIA20')->firstOrFail();
        $bankAccountId = $bank->id;

        JournalVoucher::post(
            ['date' => '2026-02-10', 'narration' => 'Bank-side entry'],
            [
                ['account_id' => $bank->id, 'debit' => 300, 'credit' => 0],
                ['account_id' => $cash->id, 'debit' => 0, 'credit' => 300],
            ],
            $admin,
        );
    });

    loginAccountingReportTestUser($domain);

    $this->get("http://{$domain}/reports/bank-book?account_id={$bankAccountId}&from=2026-02-01&to=2026-03-01")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/BankBook')
            ->where('accountId', $bankAccountId)
            ->has('entries', 1)
            ->where('entries.0.debit', 300)
            ->where('entries.0.balance', 300)
            ->where('closingBalance', 300));

    // The Cash In Hand account itself must never appear in the bank picker.
    $this->get("http://{$domain}/reports/bank-book")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/BankBook')
            ->where('accounts', fn ($accounts) => ! collect($accounts)->pluck('code')->contains('AS1')));

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
