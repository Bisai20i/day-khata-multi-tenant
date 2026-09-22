<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/**
 * Every fixture year below ends on 2026-12-31, which is still ahead of the
 * suite's clock, so each close here is an early close and needs an admin
 * plus a reason (T11 task 7).
 */
const ACCOUNTING_REPORT_CLOSE_REASON = 'Closed early by the test fixture.';

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
 * The financial statements and the three books are admin-only (audit P1),
 * so every fixture user in this file is an admin unless a test is
 * specifically about the gate.
 */
function accountingReportTestAdmin(): User
{
    return User::factory()->create([
        'email' => 'owner@example.com',
        'role_id' => Role::where('slug', 'admin')->value('id'),
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

/**
 * Flattens the Trial Balance/Balance Sheet `heads` prop (head -> group ->
 * (subgroup, optional) -> accounts) into one list of account rows, keyed the
 * same way the controller's buildHierarchy() nests them.
 *
 * @param  array<int, array<string, mixed>>  $heads
 * @return Collection<int, array<string, mixed>>
 */
function flattenAccountingReportRows(array $heads): Collection
{
    return collect($heads)->flatMap(function (array $head) {
        return collect($head['groups'])->flatMap(function (array $group) {
            $rows = $group['accounts'];

            foreach ($group['subgroups'] as $subgroup) {
                $rows = [...$rows, ...$subgroup['accounts']];
            }

            return $rows;
        });
    });
}

test('trial balance is always in balance and survives year-end closing', function () {
    $domain = 'report-trial-balance.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $fy1Id = null;
    $fy2Id = null;
    $tenant->run(function () use (&$fy1Id, &$fy2Id) {
        $admin = accountingReportTestAdmin();
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
            ->where('totalDebit', '1000.00')
            ->where('totalCredit', '1000.00')
            ->where('inBalance', true));

    // Close FY1 into FY2, then confirm FY1's trial balance still shows the
    // real activity (not zeroed out by the sweep posted into FY1 itself).
    $tenant->run(function () use ($fy1Id, $fy2Id) {
        $admin = User::where('email', 'owner@example.com')->firstOrFail();
        FiscalYear::find($fy1Id)->close(FiscalYear::find($fy2Id), $admin, ACCOUNTING_REPORT_CLOSE_REASON);
    });

    $this->get("http://{$domain}/reports/trial-balance?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totalDebit', '1000.00')
            ->where('totalCredit', '1000.00')
            ->where('inBalance', true));

    $tenant->delete();
});

test('trial balance splits opening, period and closing columns for a window inside the year', function () {
    $domain = 'report-trial-balance-window.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $fy1Id = null;
    $tenant->run(function () use (&$fy1Id) {
        $admin = accountingReportTestAdmin();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy1Id = $fy1->id;

        postAccountingReportFixture($fy1, $admin);
    });

    loginAccountingReportTestUser($domain);

    // The 1000 sale is before the window, the 400 purchase is inside it.
    $this->get("http://{$domain}/reports/trial-balance?fiscal_year_id={$fy1Id}&from=2026-04-01&to=2026-04-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totalOpeningDebit', '1000.00')
            ->where('totalOpeningCredit', '1000.00')
            ->where('totalPeriodDebit', '400.00')
            ->where('totalPeriodCredit', '400.00')
            ->where('totalDebit', '1000.00')
            ->where('totalCredit', '1000.00')
            ->where('inBalance', true));

    // The last day of a window is inclusive on SQLite too, where a
    // `date`-cast column is stored as "Y-m-d H:i:s" and a plain
    // whereBetween() silently dropped it.
    $this->get("http://{$domain}/reports/trial-balance?fiscal_year_id={$fy1Id}&from=2026-04-01&to=2026-04-01")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totalPeriodDebit', '400.00'));

    $tenant->delete();
});

test('trial balance shows the carried-forward opening balance in Opening, not Period, for the default view of the year after a close', function () {
    // Audit T15-1. FiscalYear::postOpeningBalances() dates the Opening
    // Balance voucher at exactly $fiscalYear->start_date, and
    // resolveWindow() defaults `from` to that same start_date when no
    // filter is given (the default view). The old opening computation used
    // `date <= dayBefore($from)`, which landed one day before the fiscal
    // year even starts, so the carry-forward matched neither Opening nor
    // any real window and silently fell into Period instead - Opening
    // rendered 0.00 and Period was inflated by exactly the carried-forward
    // balance. The Cash Book's own accountBook() never had this bug (see
    // "the cash book of the year after a close..." above); this test pins
    // the same rule for Trial Balance.
    $domain = 'report-trial-balance-year-two.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $fy1Id = null;
    $fy2Id = null;
    $tenant->run(function () use (&$fy1Id, &$fy2Id) {
        $admin = accountingReportTestAdmin();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fy1Id = $fy1->id;
        $fy2Id = $fy2->id;

        postAccountingReportFixture($fy1, $admin);

        FiscalYear::find($fy1->id)->close(FiscalYear::find($fy2->id), $admin, ACCOUNTING_REPORT_CLOSE_REASON);
    });

    loginAccountingReportTestUser($domain);

    // No from/to: resolveWindow() defaults to FY2's own start_date, exactly
    // where the Opening Balance voucher (carrying forward FY1's 600 cash) is
    // dated.
    $this->get("http://{$domain}/reports/trial-balance?fiscal_year_id={$fy2Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/TrialBalance')
            ->where('totalOpeningDebit', '600.00')
            ->where('totalOpeningCredit', '600.00')
            ->where('totalPeriodDebit', '0.00')
            ->where('totalPeriodCredit', '0.00')
            ->where('totalDebit', '600.00')
            ->where('totalCredit', '600.00')
            ->where('inBalance', true));

    $tenant->delete();
});

test('income statement nets income and expenses, and survives year-end closing', function () {
    $domain = 'report-income-statement.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $fy1Id = null;
    $fy2Id = null;
    $tenant->run(function () use (&$fy1Id, &$fy2Id) {
        $admin = accountingReportTestAdmin();
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
            ->where('totalIncome', '1000.00')
            ->where('totalExpenses', '400.00')
            ->where('netProfit', '600.00')
            ->where('stock.posted', false)
            ->where('stock.opening', '0.00')
            ->where('stock.closing', '0.00'));

    // Regression: without excluding the P&L sweep, a closed year's income
    // statement would wrongly report a net profit of 0.
    $tenant->run(function () use ($fy1Id, $fy2Id) {
        $admin = User::where('email', 'owner@example.com')->firstOrFail();
        FiscalYear::find($fy1Id)->close(FiscalYear::find($fy2Id), $admin, ACCOUNTING_REPORT_CLOSE_REASON);
    });

    $this->get("http://{$domain}/reports/income-statement?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('netProfit', '600.00'));

    $tenant->delete();
});

test('income statement shows computed opening and closing stock and the gross profit they produce', function () {
    $domain = 'report-income-stock.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $fy1Id = null;
    $tenant->run(function () use (&$fy1Id) {
        $admin = accountingReportTestAdmin();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy1Id = $fy1->id;

        postAccountingReportFixture($fy1, $admin);

        // 40 units in at 10.00, 15 sold: 25 x 10.00 = 250.00 still on hand.
        $store = Store::factory()->create();
        $item = Item::factory()->create(['is_stockable' => true, 'purchase_rate' => '10.00']);
        $item->recordStockMovement(StockMovementType::Purchase, '40', '2026-04-01', $store->id, null, '10.0000', '400.00');
        $item->recordStockMovement(StockMovementType::Sale, '15', '2026-05-01', $store->id);
    });

    loginAccountingReportTestUser($domain);

    // Gross profit = (1000 sales + 250 closing stock) - (400 purchases + 0
    // opening stock) = 850, and net profit rises by the same 250 the stock
    // on hand represents.
    $this->get("http://{$domain}/reports/income-statement?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stock.opening', '0.00')
            ->where('stock.closing', '250.00')
            ->where('stock.posted', false)
            ->where('grossProfit', '850.00')
            ->where('totalIncome', '1250.00')
            ->where('netProfit', '850.00'));

    $tenant->delete();
});

test('balance sheet balances while the fiscal year is open (via the current-year-earnings line) and after closing', function () {
    $domain = 'report-balance-sheet.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $fy1Id = null;
    $fy2Id = null;
    $tenant->run(function () use (&$fy1Id, &$fy2Id) {
        $admin = accountingReportTestAdmin();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fy1Id = $fy1->id;
        $fy2Id = $fy2->id;

        postAccountingReportFixture($fy1, $admin);
    });

    loginAccountingReportTestUser($domain);

    // While FY1 is still open: Cash (Assets) = 600, nothing swept into
    // Capital yet, so the report must add the 600-profit "current year
    // earnings" line to balance.
    $this->get("http://{$domain}/reports/balance-sheet?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/BalanceSheet')
            ->where('totalAssets', '600.00')
            ->where('currentYearEarnings', '600.00')
            ->where('totalLiabilitiesAndCapital', '600.00')
            ->where('balanceWarning', null));

    // After closing: the real sweep has posted the same 600 into "Profit &
    // Loss" (a Capital account) within FY1 itself - the unswept line must
    // fall to zero and the real balance must still hold.
    $tenant->run(function () use ($fy1Id, $fy2Id) {
        $admin = User::where('email', 'owner@example.com')->firstOrFail();
        FiscalYear::find($fy1Id)->close(FiscalYear::find($fy2Id), $admin, ACCOUNTING_REPORT_CLOSE_REASON);
    });

    $this->get("http://{$domain}/reports/balance-sheet?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('currentYearEarnings', '0.00')
            ->where('totalAssets', '600.00')
            ->where('totalLiabilitiesAndCapital', '600.00'));

    $tenant->delete();
});

test('balance sheet shows stock in hand and still balances, before and after the year is closed', function () {
    $domain = 'report-balance-sheet-stock.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $fy1Id = null;
    $fy2Id = null;
    $tenant->run(function () use (&$fy1Id, &$fy2Id) {
        $admin = accountingReportTestAdmin();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fy1Id = $fy1->id;
        $fy2Id = $fy2->id;

        postAccountingReportFixture($fy1, $admin);

        $store = Store::factory()->create();
        $item = Item::factory()->create(['is_stockable' => true, 'purchase_rate' => '10.00']);
        $item->recordStockMovement(StockMovementType::Purchase, '40', '2026-04-01', $store->id, null, '10.0000', '400.00');
        $item->recordStockMovement(StockMovementType::Sale, '15', '2026-05-01', $store->id);
    });

    loginAccountingReportTestUser($domain);

    // Open year: stock is not in the ledger at all, so the report adds the
    // computed 250 to BOTH sides (asset and unswept earnings) and balances.
    // assertBalanced() would have thrown instead of rendering if it did not.
    $this->get("http://{$domain}/reports/balance-sheet?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stock.closing', '250.00')
            ->where('stock.posted', false)
            ->where('totalAssets', '850.00')
            ->where('currentYearEarnings', '850.00')
            ->where('totalLiabilitiesAndCapital', '850.00'));

    $tenant->run(function () use ($fy1Id, $fy2Id) {
        $admin = User::where('email', 'owner@example.com')->firstOrFail();
        FiscalYear::find($fy1Id)->close(FiscalYear::find($fy2Id), $admin, ACCOUNTING_REPORT_CLOSE_REASON);
    });

    // Closed year: the same 250 is now a posted Stock in Hand balance, the
    // unswept line is zero, and the totals are unchanged.
    $this->get("http://{$domain}/reports/balance-sheet?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stock.posted', true)
            ->where('stock.closing', '250.00')
            ->where('totalAssets', '850.00')
            ->where('currentYearEarnings', '0.00')
            ->where('totalLiabilitiesAndCapital', '850.00'));

    // And the new year opens holding it.
    $this->get("http://{$domain}/reports/balance-sheet?fiscal_year_id={$fy2Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totalAssets', '850.00')
            ->where('totalLiabilitiesAndCapital', '850.00'));

    $tenant->delete();
});

test('day book lists vouchers within the range and excludes vouchers outside it', function () {
    $domain = 'report-day-book.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $tenant->run(function () {
        $admin = accountingReportTestAdmin();
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
            ->where('totalDebit', '1000.00')
            ->where('totalCredit', '1000.00'));

    $tenant->delete();
});

test('cash book computes an exact opening balance carried from before the range and a correct running balance within it', function () {
    $domain = 'report-cash-book.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $tenant->run(function () {
        $admin = accountingReportTestAdmin();
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
            ->where('openingBalance', '1000.00')
            ->has('entries', 2)
            ->where('entries.0.balance', '600.00')
            ->where('entries.1.balance', '800.00')
            ->where('closingBalance', '800.00'));

    $tenant->delete();
});

test('the cash book of the year after a close opens at last year closing cash, not double it', function () {
    // Audit P0-18. The opening balance used to sum every line ever posted,
    // so FY2's own Opening Balance voucher (which restates FY1's closing
    // cash) landed on TOP of FY1's own lines and cash that ended FY1 at 600
    // opened FY2 at 1200.
    $domain = 'report-cash-book-year-two.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $fy1Id = null;
    $fy2Id = null;
    $tenant->run(function () use (&$fy1Id, &$fy2Id) {
        $admin = accountingReportTestAdmin();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fy1Id = $fy1->id;
        $fy2Id = $fy2->id;

        postAccountingReportFixture($fy1, $admin);

        FiscalYear::find($fy1->id)->close(FiscalYear::find($fy2->id), $admin, ACCOUNTING_REPORT_CLOSE_REASON);
    });

    loginAccountingReportTestUser($domain);

    // The whole of FY2, which holds nothing but the carry-forward.
    $this->get("http://{$domain}/reports/cash-book?fiscal_year_id={$fy2Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('openingBalance', '600.00')
            ->where('closingBalance', '600.00')
            // The Opening Balance voucher is counted in the opening figure,
            // never again as an entry.
            ->has('entries', 0));

    // A window starting mid-year sees the same opening balance.
    $this->get("http://{$domain}/reports/cash-book?fiscal_year_id={$fy2Id}&from=2027-06-01&to=2027-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('openingBalance', '600.00'));

    // FY1 itself is unchanged: it ends at 600.
    $this->get("http://{$domain}/reports/cash-book?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('openingBalance', '0.00')
            ->where('closingBalance', '600.00'));

    $tenant->delete();
});

test('bank book scopes to the selected account only and excludes the Cash In Hand account from its picker', function () {
    $domain = 'report-bank-book.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $bankAccountId = null;

    $tenant->run(function () use (&$bankAccountId) {
        $admin = accountingReportTestAdmin();
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
            ->where('entries.0.debit', '300.00')
            ->where('entries.0.balance', '300.00')
            ->where('closingBalance', '300.00'));

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
        accountingReportTestAdmin();
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

test('a non-admin cannot open the financial statements or the three books', function () {
    $domain = 'report-accounting-gate.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        // Deliberately no admin role: a counter user has no business seeing
        // the company's capital position (audit P1, missing role gates).
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginAccountingReportTestUser($domain);

    foreach (['trial-balance', 'income-statement', 'balance-sheet', 'day-book', 'cash-book', 'bank-book'] as $path) {
        $this->get("http://{$domain}/reports/{$path}")->assertForbidden();
    }

    $tenant->delete();
});

test('the day book filters to one voucher type when asked (T14)', function () {
    $domain = 'report-day-book-voucher-type-filter.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $tenant->run(function () {
        $fy = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = accountingReportTestAdmin();

        postAccountingReportFixture($fy, $actor);

        $sales = Account::where('code', 'INI20')->firstOrFail();
        $indirectIncome = Account::where('code', 'INI30')->firstOrFail();

        JournalVoucher::postCashBank([
            'voucher_type' => 'cash_receipt',
            'date' => '2026-05-01',
            'narration' => 'Misc cash receipt',
            'lines' => [['account_id' => $indirectIncome->id, 'amount' => 60]],
        ], $actor);
    });

    loginAccountingReportTestUser($domain);

    $this->get("http://{$domain}/reports/day-book?voucher_type=cash_receipt")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/DayBook')
            ->has('vouchers', 1)
            ->where('vouchers.0.voucherType', 'cash_receipt'));

    $this->get("http://{$domain}/reports/day-book")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('vouchers', 3));

    $tenant->delete();
});

test('trial balance still lists a brand-new account with no postings and an account whose period activity nets to zero (T15-4)', function () {
    // Audit T15-4. Before the fix, trialBalanceRows() only ever enumerated
    // accounts that already appeared in the opening/period balance maps, and
    // then dropped any row where both buckets net to zero - so a ledger
    // account with no postings at all, or one that saw real money move
    // through it but happened to net to exactly zero for the period, simply
    // never rendered. Legacy's `trailbalance()` runs a LEFT JOIN from the
    // full chart of accounts and shows every one of them by default.
    $domain = 'report-trial-balance-zero-balance.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $fy1Id = null;
    $fy2Id = null;
    $zeroAccountCode = 'ZERO-NEW';

    $tenant->run(function () use (&$fy1Id, &$fy2Id, $zeroAccountCode) {
        $admin = accountingReportTestAdmin();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fy1Id = $fy1->id;
        $fy2Id = $fy2->id;

        postAccountingReportFixture($fy1, $admin);
        FiscalYear::find($fy1->id)->close(FiscalYear::find($fy2->id), $admin, ACCOUNTING_REPORT_CLOSE_REASON);

        // Created after the close: carries no opening balance and has never
        // been posted to.
        Account::factory()->create(['code' => $zeroAccountCode, 'name' => 'Zero Activity Ledger']);

        // Cash's FY2 opening (600, carried forward) is nonzero, but its FY2
        // period activity nets to exactly zero - 300 came in, then the same
        // 300 went back out - even though real transactions were posted.
        $cash = Account::where('code', 'AS1')->firstOrFail();
        $otherIncome = Account::where('code', 'INI30')->firstOrFail();

        JournalVoucher::post(
            ['date' => '2027-02-01', 'narration' => 'Misc income received in cash'],
            [
                ['account_id' => $cash->id, 'debit' => 300, 'credit' => 0],
                ['account_id' => $otherIncome->id, 'debit' => 0, 'credit' => 300],
            ],
            $admin,
        );

        JournalVoucher::post(
            ['date' => '2027-03-01', 'narration' => 'Refund of the misc income'],
            [
                ['account_id' => $otherIncome->id, 'debit' => 300, 'credit' => 0],
                ['account_id' => $cash->id, 'debit' => 0, 'credit' => 300],
            ],
            $admin,
        );
    });

    loginAccountingReportTestUser($domain);

    $this->get("http://{$domain}/reports/trial-balance?fiscal_year_id={$fy2Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/TrialBalance')
            ->where('heads', function (array $heads) use ($zeroAccountCode) {
                $rows = flattenAccountingReportRows($heads);

                $cashRow = $rows->firstWhere('code', 'AS1');
                $zeroRow = $rows->firstWhere('code', $zeroAccountCode);

                return $cashRow !== null
                    && $cashRow['openingDebit'] === '600.00'
                    && $cashRow['openingCredit'] === '0.00'
                    && $cashRow['periodDebit'] === '300.00'
                    && $cashRow['periodCredit'] === '300.00'
                    && $cashRow['closingDebit'] === '600.00'
                    && $cashRow['closingCredit'] === '0.00'
                    && $zeroRow !== null
                    && $zeroRow['openingDebit'] === '0.00'
                    && $zeroRow['openingCredit'] === '0.00'
                    && $zeroRow['periodDebit'] === '0.00'
                    && $zeroRow['periodCredit'] === '0.00'
                    && $zeroRow['closingDebit'] === '0.00'
                    && $zeroRow['closingCredit'] === '0.00';
            }));

    $tenant->delete();
});

test('trial balance narrows to a single account when account_id is given (T15-5)', function () {
    // Audit T15-5. Legacy's `trailbalance()` accepts `?accno=` to narrow the
    // whole report to one ledger.
    $domain = 'report-trial-balance-account-filter.tenant-test';
    $tenant = provisionAccountingReportTestTenant($domain);

    $fy1Id = null;
    $cashId = null;

    $tenant->run(function () use (&$fy1Id, &$cashId) {
        $admin = accountingReportTestAdmin();
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy1Id = $fy1->id;

        postAccountingReportFixture($fy1, $admin);

        $cashId = Account::where('code', 'AS1')->value('id');
    });

    loginAccountingReportTestUser($domain);

    $this->get("http://{$domain}/reports/trial-balance?fiscal_year_id={$fy1Id}&account_id={$cashId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/TrialBalance')
            ->where('accountId', $cashId)
            ->where('heads', function (array $heads) {
                $rows = flattenAccountingReportRows($heads);

                // Exactly one row, and it is the one account asked for -
                // Sales (INI20) and Purchases (EXE8), which the unfiltered
                // fixture also touches, must not appear.
                return $rows->count() === 1 && $rows->first()['code'] === 'AS1';
            })
            ->where('totalOpeningDebit', '0.00')
            ->where('totalOpeningCredit', '0.00')
            ->where('totalPeriodDebit', '1000.00')
            ->where('totalPeriodCredit', '400.00')
            ->where('totalDebit', '600.00')
            ->where('totalCredit', '0.00'));

    // The account picker itself always lists every account, regardless of
    // which one (if any) is currently selected.
    $this->get("http://{$domain}/reports/trial-balance?fiscal_year_id={$fy1Id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accountId', null)
            ->where('accounts', fn ($accounts) => collect($accounts)->pluck('code')->contains('AS1')));

    $tenant->delete();
});
