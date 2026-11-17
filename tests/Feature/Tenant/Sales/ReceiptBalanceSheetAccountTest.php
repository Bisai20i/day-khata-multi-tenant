<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\AccountUnderHead;
use App\Support\Branches\BranchAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionBalanceSheetAccountTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginBalanceSheetAccountAdmin(string $domain): User
{
    $admin = User::factory()->create([
        'email' => 'admin@example.com',
        'role_id' => Role::where('slug', 'admin')->value('id'),
    ]);

    test()->post("http://{$domain}/login", [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    return $admin;
}

test('AccountUnderHead accepts accounts under any of the specified heads and rejects others', function () {
    $domain = 'rule-bs-head.tenant-test';
    $tenant = provisionBalanceSheetAccountTenant($domain);

    $tenant->run(function () {
        $assetAccount = Account::where('code', 'AS1')->firstOrFail();
        $expenseAccount = Account::where('code', 'EXE8')->firstOrFail();

        $liabilitiesHead = AccountHead::where('name', 'Liabilities')->firstOrFail();
        $liabGroup = AccountGroup::where('account_head_id', $liabilitiesHead->id)->firstOrFail();
        $overdraftAccount = Account::create(['account_group_id' => $liabGroup->id, 'name' => 'Bank Overdraft']);

        $capitalHead = AccountHead::where('name', 'Capital')->firstOrFail();
        $capitalGroup = AccountGroup::where('account_head_id', $capitalHead->id)->firstOrFail();
        $capitalAccount = Account::create(['account_group_id' => $capitalGroup->id, 'name' => 'Owner Capital']);

        $rule = new AccountUnderHead(['Assets', 'Liabilities', 'Capital']);

        $passes = fn ($accountId): bool => Validator::make(['account_id' => $accountId], ['account_id' => [$rule]])->passes();

        expect($passes($assetAccount->id))->toBeTrue()
            ->and($passes($overdraftAccount->id))->toBeTrue()
            ->and($passes($capitalAccount->id))->toBeTrue()
            ->and($passes($expenseAccount->id))->toBeFalse();
    });

    $tenant->delete();
});

test('settlementAccountQuery includes balance sheet accounts and excludes party and control accounts', function () {
    $domain = 'receipt-settlement-query.tenant-test';
    $tenant = provisionBalanceSheetAccountTenant($domain);

    $tenant->run(function () {
        $customer = Customer::factory()->create();
        $supplier = Supplier::factory()->create();

        $liabilitiesHead = AccountHead::where('name', 'Liabilities')->firstOrFail();
        $liabGroup = AccountGroup::where('account_head_id', $liabilitiesHead->id)->firstOrFail();
        $overdraft = Account::create(['account_group_id' => $liabGroup->id, 'name' => 'Bank OD']);

        $expenseAccount = Account::where('code', 'EXE8')->firstOrFail();

        $eligibleIds = Receipt::settlementAccountQuery()->pluck('id')->all();

        // Overdraft under Liabilities is eligible
        expect($eligibleIds)->toContain($overdraft->id);

        // Cash under Assets is eligible
        $cashId = Account::where('code', 'AS1')->value('id');
        expect($eligibleIds)->toContain($cashId);

        // Party accounts are excluded
        expect($eligibleIds)->not->toContain($customer->account_id);
        expect($eligibleIds)->not->toContain($supplier->account_id);

        // P&L / Expense accounts are excluded
        expect($eligibleIds)->not->toContain($expenseAccount->id);

        // Control accounts are excluded
        $controlIds = Account::whereIn('code', BranchAccounts::CONTROL_CODES)->pluck('id')->all();
        foreach ($controlIds as $controlId) {
            expect($eligibleIds)->not->toContain($controlId);
        }
    });

    $tenant->delete();
});

test('receipts index offers balance sheet accounts for bank selection', function () {
    $domain = 'receipt-index-accounts.tenant-test';
    $tenant = provisionBalanceSheetAccountTenant($domain);

    $overdraftId = null;

    $tenant->run(function () use ($domain, &$overdraftId) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        loginBalanceSheetAccountAdmin($domain);

        $liabilitiesHead = AccountHead::where('name', 'Liabilities')->firstOrFail();
        $liabGroup = AccountGroup::where('account_head_id', $liabilitiesHead->id)->firstOrFail();
        $overdraft = Account::create(['account_group_id' => $liabGroup->id, 'name' => 'Overdraft Account']);
        $overdraftId = $overdraft->id;
    });

    $this->get("http://{$domain}/receipts")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Sales/Receipts/Index')
            ->where('accounts', fn ($accounts) => collect($accounts)->pluck('id')->contains($overdraftId))
        );

    $tenant->delete();
});

test('receipt can be banked into a liability overdraft account', function () {
    $domain = 'receipt-post-liability.tenant-test';
    $tenant = provisionBalanceSheetAccountTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create();
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $customer = Customer::factory()->create();

        $liabilitiesHead = AccountHead::where('name', 'Liabilities')->firstOrFail();
        $liabGroup = AccountGroup::where('account_head_id', $liabilitiesHead->id)->firstOrFail();
        $overdraft = Account::create(['account_group_id' => $liabGroup->id, 'name' => 'Commercial Bank OD']);

        $receipt = Receipt::post([
            'customer_id' => $customer->id,
            'date' => '2026-06-15',
            'amount' => '500.00',
            'payment_mode' => 'bank',
            'bank_account_id' => $overdraft->id,
        ], $admin);

        expect($receipt->status)->toBe('posted')
            ->and($receipt->bank_account_id)->toBe($overdraft->id);

        $lines = $receipt->journalVoucher->lines;
        $overdraftLine = $lines->firstWhere('account_id', $overdraft->id);
        $customerLine = $lines->firstWhere('account_id', $customer->account_id);

        expect($overdraftLine->debit)->toBe('500.00')
            ->and($customerLine->credit)->toBe('500.00');
    });

    $tenant->delete();
});

test('receipt endpoint accepts bank account under liabilities and refuses account under expenses', function () {
    $domain = 'receipt-store-validation.tenant-test';
    $tenant = provisionBalanceSheetAccountTenant($domain);

    $customerId = null;
    $overdraftId = null;
    $expenseId = null;

    $tenant->run(function () use ($domain, &$customerId, &$overdraftId, &$expenseId) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        loginBalanceSheetAccountAdmin($domain);

        $customer = Customer::factory()->create();
        $customerId = $customer->id;

        $liabilitiesHead = AccountHead::where('name', 'Liabilities')->firstOrFail();
        $liabGroup = AccountGroup::where('account_head_id', $liabilitiesHead->id)->firstOrFail();
        $overdraft = Account::create(['account_group_id' => $liabGroup->id, 'name' => 'Bank Overdraft Facility']);
        $overdraftId = $overdraft->id;

        $expenseId = Account::where('code', 'EXE8')->value('id');
    });

    // Refuses expense account
    $this->post("http://{$domain}/receipts", [
        'customer_id' => $customerId,
        'date' => '2026-06-15',
        'amount' => '250.00',
        'payment_mode' => 'bank',
        'bank_account_id' => $expenseId,
    ])->assertSessionHasErrors('bank_account_id');

    // Accepts liability overdraft account
    $this->post("http://{$domain}/receipts", [
        'customer_id' => $customerId,
        'date' => '2026-06-15',
        'amount' => '250.00',
        'payment_mode' => 'bank',
        'bank_account_id' => $overdraftId,
    ])->assertSessionHasNoErrors()
        ->assertRedirect("http://{$domain}/receipts");

    $tenant->delete();
});

test('payment endpoint accepts bank account under liabilities and refuses account under expenses', function () {
    $domain = 'payment-store-validation.tenant-test';
    $tenant = provisionBalanceSheetAccountTenant($domain);

    $supplierId = null;
    $overdraftId = null;
    $expenseId = null;

    $tenant->run(function () use ($domain, &$supplierId, &$overdraftId, &$expenseId) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        loginBalanceSheetAccountAdmin($domain);

        $supplier = Supplier::factory()->create();
        $supplierId = $supplier->id;

        $liabilitiesHead = AccountHead::where('name', 'Liabilities')->firstOrFail();
        $liabGroup = AccountGroup::where('account_head_id', $liabilitiesHead->id)->firstOrFail();
        $overdraft = Account::create(['account_group_id' => $liabGroup->id, 'name' => 'Credit Line Account']);
        $overdraftId = $overdraft->id;

        $expenseId = Account::where('code', 'EXE8')->value('id');
    });

    // Refuses expense account
    $this->post("http://{$domain}/payments", [
        'supplier_id' => $supplierId,
        'date' => '2026-06-15',
        'amount' => '300.00',
        'payment_mode' => 'bank',
        'bank_account_id' => $expenseId,
    ])->assertSessionHasErrors('bank_account_id');

    // Accepts liability overdraft account
    $this->post("http://{$domain}/payments", [
        'supplier_id' => $supplierId,
        'date' => '2026-06-15',
        'amount' => '300.00',
        'payment_mode' => 'bank',
        'bank_account_id' => $overdraftId,
    ])->assertSessionHasNoErrors()
        ->assertRedirect("http://{$domain}/payments");

    $tenant->delete();
});
