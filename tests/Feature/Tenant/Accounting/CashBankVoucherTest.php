<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\PrintLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VoucherSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionCashBankVoucherTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function cashBankVoucherTestAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

test('a cash receipt debits Cash-In-Hand and credits every named account for the summed total', function () {
    $tenant = provisionCashBankVoucherTestTenant('cbv-cash-receipt.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = cashBankVoucherTestAdmin();
        $sales = Account::where('code', 'INI20')->firstOrFail();
        $indirectIncome = Account::where('code', 'INI30')->firstOrFail();

        $voucher = JournalVoucher::postCashBank([
            'voucher_type' => 'cash_receipt',
            'date' => '2026-06-01',
            'narration' => 'Misc cash receipts',
            'lines' => [
                ['account_id' => $sales->id, 'amount' => 300],
                ['account_id' => $indirectIncome->id, 'amount' => 200],
            ],
        ], $actor);

        expect($voucher->voucher_type)->toBe(VoucherType::CashReceipt)
            ->and($voucher->voucher_number)->toBe(1);

        $byAccount = $voucher->lines->keyBy('account_id');
        $cash = Account::where('code', 'AS1')->firstOrFail();

        expect($byAccount[$cash->id]->debit)->toBe('500.00')
            ->and($byAccount[$cash->id]->credit)->toBe('0.00')
            ->and($byAccount[$sales->id]->credit)->toBe('300.00')
            ->and($byAccount[$indirectIncome->id]->credit)->toBe('200.00');
    });

    $tenant->delete();
});

test('a cash payment credits Cash-In-Hand and debits every named account', function () {
    $tenant = provisionCashBankVoucherTestTenant('cbv-cash-payment.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = cashBankVoucherTestAdmin();
        $expense = Account::where('code', 'EXE20')->firstOrFail();

        $voucher = JournalVoucher::postCashBank([
            'voucher_type' => 'cash_payment',
            'date' => '2026-06-01',
            'narration' => 'Office expense paid in cash',
            'lines' => [['account_id' => $expense->id, 'amount' => 150]],
        ], $actor);

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $byAccount = $voucher->lines->keyBy('account_id');

        expect($byAccount[$cash->id]->credit)->toBe('150.00')
            ->and($byAccount[$expense->id]->debit)->toBe('150.00');
    });

    $tenant->delete();
});

test('a bank receipt uses the chosen bank account rather than Cash-In-Hand', function () {
    $tenant = provisionCashBankVoucherTestTenant('cbv-bank-receipt.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = cashBankVoucherTestAdmin();
        $sales = Account::where('code', 'INI20')->firstOrFail();
        $bank = Account::factory()->create(['name' => 'Bank Ltd', 'code' => null]);

        $voucher = JournalVoucher::postCashBank([
            'voucher_type' => 'bank_receipt',
            'date' => '2026-06-01',
            'narration' => 'Bank interest',
            'bank_account_id' => $bank->id,
            'lines' => [['account_id' => $sales->id, 'amount' => 75]],
        ], $actor);

        $byAccount = $voucher->lines->keyBy('account_id');

        expect($voucher->voucher_type)->toBe(VoucherType::BankReceipt)
            ->and($byAccount[$bank->id]->debit)->toBe('75.00')
            ->and($byAccount[$sales->id]->credit)->toBe('75.00');
    });

    $tenant->delete();
});

test('a contra voucher moves a single amount from one account to another with no other accounts involved', function () {
    $tenant = provisionCashBankVoucherTestTenant('cbv-contra.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = cashBankVoucherTestAdmin();
        $cash = Account::where('code', 'AS1')->firstOrFail();
        $bank = Account::factory()->create(['name' => 'Bank Ltd', 'code' => null]);

        $voucher = JournalVoucher::postCashBank([
            'voucher_type' => 'contra',
            'date' => '2026-06-01',
            'narration' => 'Deposit cash into the bank',
            'from_account_id' => $cash->id,
            'to_account_id' => $bank->id,
            'amount' => 1000,
        ], $actor);

        $byAccount = $voucher->lines->keyBy('account_id');

        expect($voucher->voucher_type)->toBe(VoucherType::Contra)
            ->and($voucher->lines)->toHaveCount(2)
            ->and($byAccount[$bank->id]->debit)->toBe('1000.00')
            ->and($byAccount[$cash->id]->credit)->toBe('1000.00');
    });

    $tenant->delete();
});

test('a contra voucher rejects the same account on both sides', function () {
    $tenant = provisionCashBankVoucherTestTenant('cbv-contra-same-account.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = cashBankVoucherTestAdmin();
        $cash = Account::where('code', 'AS1')->firstOrFail();

        expect(fn () => JournalVoucher::postCashBank([
            'voucher_type' => 'contra',
            'date' => '2026-06-01',
            'narration' => 'Invalid',
            'from_account_id' => $cash->id,
            'to_account_id' => $cash->id,
            'amount' => 100,
        ], $actor))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('each cash/bank voucher type numbers its own gapless series, independent of the others', function () {
    $tenant = provisionCashBankVoucherTestTenant('cbv-own-series.tenant-test');

    $tenant->run(function () {
        $fy = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = cashBankVoucherTestAdmin();
        $sales = Account::where('code', 'INI20')->firstOrFail();

        JournalVoucher::postCashBank(['voucher_type' => 'cash_receipt', 'date' => '2026-06-01', 'narration' => 'A', 'lines' => [['account_id' => $sales->id, 'amount' => 10]]], $actor);
        JournalVoucher::postCashBank(['voucher_type' => 'cash_receipt', 'date' => '2026-06-02', 'narration' => 'B', 'lines' => [['account_id' => $sales->id, 'amount' => 10]]], $actor);
        JournalVoucher::postCashBank(['voucher_type' => 'cash_payment', 'date' => '2026-06-03', 'narration' => 'C', 'lines' => [['account_id' => $sales->id, 'amount' => 10]]], $actor);

        expect(VoucherSequence::nextNumberFor($fy, VoucherType::CashReceipt))->toBe(3)
            ->and(VoucherSequence::nextNumberFor($fy, VoucherType::CashPayment))->toBe(2)
            ->and(VoucherSequence::nextNumberFor($fy, VoucherType::BankReceipt))->toBe(1);
    });

    $tenant->delete();
});

test('an unknown voucher type is rejected by postCashBank', function () {
    $tenant = provisionCashBankVoucherTestTenant('cbv-unknown-type.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = cashBankVoucherTestAdmin();
        $sales = Account::where('code', 'INI20')->firstOrFail();

        expect(fn () => JournalVoucher::postCashBank([
            'voucher_type' => 'journal',
            'date' => '2026-06-01',
            'narration' => 'Wrong type',
            'lines' => [['account_id' => $sales->id, 'amount' => 10]],
        ], $actor))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a cash/bank voucher can be cancelled through the generic journal voucher cancel route, posting a Reversal', function () {
    $domain = 'cbv-cancel.tenant-test';
    $tenant = provisionCashBankVoucherTestTenant($domain);

    $voucherId = null;
    $tenant->run(function () use (&$voucherId) {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $sales = Account::where('code', 'INI20')->firstOrFail();

        $voucher = JournalVoucher::postCashBank([
            'voucher_type' => 'cash_receipt',
            'date' => '2026-06-01',
            'narration' => 'Misc receipt',
            'lines' => [['account_id' => $sales->id, 'amount' => 400]],
        ], User::first());

        $voucherId = $voucher->id;
    });

    test()->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/journal-vouchers/{$voucherId}/cancel", ['reason' => 'Entered in error'])
        ->assertRedirect();

    $tenant->run(function () use ($voucherId) {
        expect(JournalVoucher::find($voucherId)->status)->toBe('cancelled')
            ->and(JournalVoucher::where('voucher_type', VoucherType::Reversal)->exists())->toBeTrue();
    });

    $tenant->delete();
});

test('posting a cash/bank voucher over HTTP requires an account line and rejects a duplicate account', function () {
    $domain = 'cbv-http-store.tenant-test';
    $tenant = provisionCashBankVoucherTestTenant($domain);

    $salesId = null;
    $tenant->run(function () use (&$salesId) {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $salesId = Account::where('code', 'INI20')->value('id');
    });

    test()->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    // Same account named twice must be rejected by the `distinct` rule
    // (CONTRACTS C5) rather than silently summing.
    $this->post("http://{$domain}/journal-vouchers/cash-bank", [
        'voucher_type' => 'cash_receipt',
        'date' => '2026-06-01',
        'narration' => 'Duplicate account attempt',
        'lines' => [
            ['account_id' => $salesId, 'amount' => 100],
            ['account_id' => $salesId, 'amount' => 50],
        ],
    ])->assertSessionHasErrors('lines.0.account_id');

    $this->post("http://{$domain}/journal-vouchers/cash-bank", [
        'voucher_type' => 'cash_receipt',
        'date' => '2026-06-01',
        'narration' => 'Valid receipt',
        'lines' => [['account_id' => $salesId, 'amount' => 250]],
    ])->assertRedirect();

    $tenant->run(function () {
        expect(JournalVoucher::where('voucher_type', VoucherType::CashReceipt)->count())->toBe(1);
    });

    $tenant->delete();
});

test('a staff user gets a 403 on the cash/bank voucher store route', function () {
    $domain = 'cbv-staff-gate.tenant-test';
    $tenant = provisionCashBankVoucherTestTenant($domain);

    $salesId = null;
    $tenant->run(function () use (&$salesId) {
        User::factory()->create(['email' => 'staff@example.com', 'role_id' => Role::where('slug', 'staff')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $salesId = Account::where('code', 'INI20')->value('id');
    });

    test()->post("http://{$domain}/login", ['email' => 'staff@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/journal-vouchers/cash-bank", [
        'voucher_type' => 'cash_receipt',
        'date' => '2026-06-01',
        'narration' => 'Staff attempt',
        'lines' => [['account_id' => $salesId, 'amount' => 100]],
    ])->assertForbidden();

    $tenant->delete();
});

test('the journal voucher print route stamps a copy number and reprint shows a copy count', function () {
    $domain = 'cbv-print.tenant-test';
    $tenant = provisionCashBankVoucherTestTenant($domain);

    $voucherId = null;
    $tenant->run(function () use (&$voucherId) {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $sales = Account::where('code', 'INI20')->firstOrFail();

        $voucher = JournalVoucher::postCashBank([
            'voucher_type' => 'cash_receipt',
            'date' => '2026-06-01',
            'narration' => 'Misc receipt',
            'lines' => [['account_id' => $sales->id, 'amount' => 400]],
        ], User::first());

        $voucherId = $voucher->id;
    });

    test()->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/journal-vouchers/{$voucherId}/print")->assertOk();
    $this->get("http://{$domain}/journal-vouchers/{$voucherId}/print")->assertOk();

    $tenant->run(function () use ($voucherId) {
        expect(PrintLog::copiesPrinted(JournalVoucher::find($voucherId)))->toBe(2);
    });

    $tenant->delete();
});
