<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionReportLedgerTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Ledger Report Test Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginReportLedgerUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('ledger report page renders successfully with accounts list', function () {
    $domain = 'report-ledger-index.tenant-test';
    $tenant = provisionReportLedgerTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);
        Account::factory()->create(['code' => 'AS-100', 'name' => 'Main Cash']);
    });

    loginReportLedgerUser($domain);

    $this->get("http://{$domain}/reports/ledger")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/Ledger')
            ->has('accounts')
            ->has('fiscalYears')
        );

    $tenant->delete();
});

test('ledger report calculates opening balance and sequential transactions', function () {
    $domain = 'report-ledger-data.tenant-test';
    $tenant = provisionReportLedgerTenant($domain);

    $accountId = null;

    $tenant->run(function () use (&$accountId) {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $fy = FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);

        $cash = Account::factory()->create(['code' => 'AS-101', 'name' => 'Cash in Vault']);
        $revenue = Account::factory()->create(['code' => 'INC-101', 'name' => 'General Revenue']);
        $accountId = $cash->id;

        // Opening voucher: Rs 1000 debit
        $ov = JournalVoucher::create([
            'voucher_type' => VoucherType::OpeningBalance,
            'voucher_number' => 1,
            'fiscal_year_id' => $fy->id,
            'date' => '2024-07-16',
            'narration' => 'Opening balance',
            'created_by' => $user->id,
        ]);
        JournalVoucherLine::create(['journal_voucher_id' => $ov->id, 'account_id' => $cash->id, 'debit' => '1000.00', 'credit' => '0.00']);

        // Transaction voucher: Rs 500 debit
        $jv = JournalVoucher::create([
            'voucher_type' => VoucherType::Journal,
            'voucher_number' => 2,
            'fiscal_year_id' => $fy->id,
            'date' => '2024-08-01',
            'narration' => 'Cash received',
            'created_by' => $user->id,
        ]);
        JournalVoucherLine::create(['journal_voucher_id' => $jv->id, 'account_id' => $cash->id, 'debit' => '500.00', 'credit' => '0.00']);
        JournalVoucherLine::create(['journal_voucher_id' => $jv->id, 'account_id' => $revenue->id, 'debit' => '0.00', 'credit' => '500.00']);
    });

    loginReportLedgerUser($domain);

    $res = $this->get("http://{$domain}/reports/ledger?account_id={$accountId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/Ledger')
            ->where('openingBalance', '1000.00')
            ->where('closingBalance', '1500.00')
            ->has('entries', 1)
        );

    $tenant->delete();
});

test('ledger report print and export routes stream pdf and xlsx', function () {
    $domain = 'report-ledger-exports.tenant-test';
    $tenant = provisionReportLedgerTenant($domain);

    $accountId = null;

    $tenant->run(function () use (&$accountId) {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $fy = FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);
        $cash = Account::factory()->create(['code' => 'AS-102', 'name' => 'Petty Cash']);
        $accountId = $cash->id;
    });

    loginReportLedgerUser($domain);

    $this->get("http://{$domain}/reports/ledger/print?account_id={$accountId}")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->get("http://{$domain}/reports/ledger/export?account_id={$accountId}")
        ->assertOk()
        ->assertHeader('content-disposition');

    $tenant->delete();
});
