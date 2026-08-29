<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
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

function provisionLedgerControllerTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginLedgerControllerTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the fiscal years, journal vouchers, and account ledger pages render their expected components', function () {
    $domain = 'ledger-pages-render.tenant-test';
    $tenant = provisionLedgerControllerTestTenant($domain);

    $accountId = null;
    $tenant->run(function () use (&$accountId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $accountId = Account::where('code', 'AS1')->value('id');
    });

    loginLedgerControllerTestUser($domain);

    $this->get("http://{$domain}/fiscal-years")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Accounting/FiscalYears/Index'));

    $this->get("http://{$domain}/journal-vouchers")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Accounting/JournalVouchers/Index'));

    $this->get("http://{$domain}/accounts/{$accountId}/ledger")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Accounting/Accounts/Ledger'));

    $tenant->delete();
});

test('the first fiscal year a tenant creates opens automatically, subsequent ones start closed', function () {
    $domain = 'fy-store-first-open.tenant-test';
    $tenant = provisionLedgerControllerTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginLedgerControllerTestUser($domain);

    $this->post("http://{$domain}/fiscal-years", [
        'name' => 'FY1',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ])->assertRedirect("http://{$domain}/fiscal-years");

    $this->post("http://{$domain}/fiscal-years", [
        'name' => 'FY2',
        'start_date' => '2027-01-01',
        'end_date' => '2027-12-31',
    ])->assertRedirect("http://{$domain}/fiscal-years");

    $tenant->run(function () {
        expect(FiscalYear::where('name', 'FY1')->value('status'))->toBe(FiscalYearStatus::Open)
            ->and(FiscalYear::where('name', 'FY2')->value('status'))->toBe(FiscalYearStatus::Closed);
    });

    $tenant->delete();
});

test('closing a fiscal year is rejected for a non-admin user', function () {
    $domain = 'fy-close-role-gate.tenant-test';
    $tenant = provisionLedgerControllerTestTenant($domain);

    $fiscalYearId = null;
    $nextId = null;
    $tenant->run(function () use (&$fiscalYearId, &$nextId) {
        User::factory()->create([
            'email' => 'owner@example.com',
            'role_id' => Role::where('slug', 'staff')->value('id'),
        ]);

        $fiscalYearId = FiscalYear::create([
            'name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open,
        ])->id;
        $nextId = FiscalYear::create([
            'name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed,
        ])->id;
    });

    loginLedgerControllerTestUser($domain);

    $this->post("http://{$domain}/fiscal-years/{$fiscalYearId}/close", [
        'next_fiscal_year_id' => $nextId,
    ])->assertForbidden();

    $tenant->delete();
});

test('an authenticated user can post a balanced journal voucher through the store route', function () {
    $domain = 'jv-store-http.tenant-test';
    $tenant = provisionLedgerControllerTestTenant($domain);

    $cashId = null;
    $salesId = null;
    $tenant->run(function () use (&$cashId, &$salesId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $cashId = Account::where('code', 'AS1')->value('id');
        $salesId = Account::where('code', 'INI20')->value('id');
    });

    loginLedgerControllerTestUser($domain);

    $this->post("http://{$domain}/journal-vouchers", [
        'date' => '2026-06-01',
        'narration' => 'Cash sale',
        'lines' => [
            ['account_id' => $cashId, 'debit' => 250, 'credit' => 0],
            ['account_id' => $salesId, 'debit' => 0, 'credit' => 250],
        ],
    ])->assertRedirect("http://{$domain}/journal-vouchers");

    $tenant->run(function () {
        expect(JournalVoucher::query()->count())->toBe(1);
    });

    $tenant->delete();
});

test('posting an unbalanced journal voucher through the store route fails validation', function () {
    $domain = 'jv-store-unbalanced-http.tenant-test';
    $tenant = provisionLedgerControllerTestTenant($domain);

    $cashId = null;
    $salesId = null;
    $tenant->run(function () use (&$cashId, &$salesId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $cashId = Account::where('code', 'AS1')->value('id');
        $salesId = Account::where('code', 'INI20')->value('id');
    });

    loginLedgerControllerTestUser($domain);

    $this->post("http://{$domain}/journal-vouchers", [
        'date' => '2026-06-01',
        'narration' => 'Bad voucher',
        'lines' => [
            ['account_id' => $cashId, 'debit' => 250, 'credit' => 0],
            ['account_id' => $salesId, 'debit' => 0, 'credit' => 200],
        ],
    ])->assertSessionHasErrors('lines');

    $tenant->run(function () {
        expect(JournalVoucher::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('posting a correction into a closed fiscal year through the store route is rejected for a non-admin', function () {
    $domain = 'jv-store-closed-non-admin.tenant-test';
    $tenant = provisionLedgerControllerTestTenant($domain);

    $closedId = null;
    $cashId = null;
    $salesId = null;
    $tenant->run(function () use (&$closedId, &$cashId, &$salesId) {
        User::factory()->create([
            'email' => 'owner@example.com',
            'role_id' => Role::where('slug', 'staff')->value('id'),
        ]);
        $closedId = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed])->id;
        FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);
        $cashId = Account::where('code', 'AS1')->value('id');
        $salesId = Account::where('code', 'INI20')->value('id');
    });

    loginLedgerControllerTestUser($domain);

    $this->post("http://{$domain}/journal-vouchers", [
        'fiscal_year_id' => $closedId,
        'reason' => 'Missed expense',
        'date' => '2026-06-01',
        'narration' => 'Correction',
        'lines' => [
            ['account_id' => $cashId, 'debit' => 0, 'credit' => 50],
            ['account_id' => $salesId, 'debit' => 50, 'credit' => 0],
        ],
    ])->assertSessionHasErrors('lines');

    $tenant->run(function () {
        expect(JournalVoucher::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('an admin can post a correction into a closed fiscal year through the store route and it rolls forward', function () {
    $domain = 'jv-store-closed-admin.tenant-test';
    $tenant = provisionLedgerControllerTestTenant($domain);

    $closedId = null;
    $openId = null;
    $cashId = null;
    $salesId = null;
    $tenant->run(function () use (&$closedId, &$openId, &$cashId, &$salesId) {
        User::factory()->create([
            'email' => 'owner@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);
        $closedId = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed])->id;
        $openId = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open])->id;
        $cashId = Account::where('code', 'AS1')->value('id');
        $salesId = Account::where('code', 'INI20')->value('id');
    });

    loginLedgerControllerTestUser($domain);

    $this->post("http://{$domain}/journal-vouchers", [
        'fiscal_year_id' => $closedId,
        'reason' => 'Duplicate sale recorded in error',
        'date' => '2026-06-15',
        'narration' => 'Reverse duplicate sale',
        'lines' => [
            ['account_id' => $salesId, 'debit' => 100, 'credit' => 0],
            ['account_id' => $cashId, 'debit' => 0, 'credit' => 100],
        ],
    ])->assertRedirect("http://{$domain}/journal-vouchers");

    $tenant->run(function () use (&$closedId, &$openId) {
        expect(JournalVoucher::where('fiscal_year_id', $closedId)->count())->toBe(1)
            ->and(JournalVoucher::where('fiscal_year_id', $openId)
                ->where('voucher_type', VoucherType::RollForwardAdjustment)
                ->exists())->toBeTrue();
    });

    $tenant->delete();
});
