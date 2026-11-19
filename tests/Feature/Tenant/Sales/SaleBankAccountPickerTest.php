<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\AccountSubgroup;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSaleBankAccountPickerTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginSaleBankAccountPickerUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

/**
 * Seeds genuine bank accounts across subgroups and groups as well as non-bank accounts.
 *
 * @return array<string, mixed>
 */
function seedBankAndNonBankAccounts(): array
{
    $bankAccountsSubgroup = AccountSubgroup::where('name', 'Bank Accounts')->firstOrFail();
    $currentAssetsGroup = AccountGroup::where('name', 'Current Assets')->firstOrFail();
    $liabilitiesHead = AccountHead::where('name', 'Liabilities')->firstOrFail();
    $fixedAssetsGroup = AccountGroup::where('name', 'Fixed Assets')->firstOrFail();

    $bankLoansGroup = AccountGroup::firstOrCreate([
        'name' => 'Bank Loans',
        'account_head_id' => $liabilitiesHead->id,
    ]);

    // 1. Under Current Assets -> Bank Accounts subgroup:
    $nabil = Account::create([
        'name' => 'Nabil Bank A/C',
        'account_subgroup_id' => $bankAccountsSubgroup->id,
    ]);

    // 2. Under Current Assets -> Bank Accounts subgroup:
    $nicAsia = Account::create([
        'name' => 'NIC ASIA A/C 001',
        'account_subgroup_id' => $bankAccountsSubgroup->id,
    ]);

    // 3. Under Current Assets group without subgroup:
    $globalIme = Account::create([
        'name' => 'Global IME Bank',
        'account_group_id' => $currentAssetsGroup->id,
    ]);

    // 4. Under Bank Loans group under Liabilities:
    $everest = Account::create([
        'name' => 'Everest Bank Overdraft',
        'account_group_id' => $bankLoansGroup->id,
    ]);

    // Ensure non-bank accounts exist
    $cashInHand = Account::where('code', 'AS1')->firstOrFail();
    $stockInHand = Account::where('code', 'AS11')->firstOrFail();
    $vatPayable = Account::where('code', 'LIA20')->firstOrFail();
    $tdsPayable = Account::where('code', 'LIA21')->firstOrFail();
    $salaryPayable = Account::where('code', 'LIA22')->firstOrFail();
    $profitLoss = Account::where('code', 'CA2')->firstOrFail();

    // Customer ledger under Sundry Debtors
    $customer = Customer::factory()->create(['name' => 'Test Customer']);
    $customerAccount = Account::where('name', 'Test Customer')->firstOrFail();

    // Supplier ledger under Sundry Creditors
    $supplier = Supplier::factory()->create(['name' => 'Test Supplier']);
    $supplierAccount = Account::where('name', 'Test Supplier')->firstOrFail();

    // Fixed asset account under Fixed Assets group
    $fixedAsset = Account::create([
        'name' => 'Office Furniture',
        'account_group_id' => $fixedAssetsGroup->id,
    ]);

    return [
        'banks' => [
            'nabil' => $nabil,
            'nicAsia' => $nicAsia,
            'globalIme' => $globalIme,
            'everest' => $everest,
        ],
        'nonBanks' => [
            'cashInHand' => $cashInHand,
            'stockInHand' => $stockInHand,
            'vatPayable' => $vatPayable,
            'tdsPayable' => $tdsPayable,
            'salaryPayable' => $salaryPayable,
            'profitLoss' => $profitLoss,
            'customerAccount' => $customerAccount,
            'supplierAccount' => $supplierAccount,
            'fixedAsset' => $fixedAsset,
        ],
        'customer' => $customer,
        'supplier' => $supplier,
    ];
}

test('sales create page offers only genuine bank accounts in bankAccounts prop and excludes all non-bank ledgers', function () {
    $domain = 'sale-bank-picker-create.tenant-test';
    $tenant = provisionSaleBankAccountPickerTenant($domain);

    $accounts = null;
    $tenant->run(function () use (&$accounts) {
        User::factory()->create(['email' => 'owner@example.com']);
        $accounts = seedBankAndNonBankAccounts();
    });

    loginSaleBankAccountPickerUser($domain);

    $response = $this->get("http://{$domain}/sales");

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Sales/Index')
            ->has('bankAccounts')
            ->where('bankAccounts', function ($bankAccounts) use ($accounts) {
                $names = collect($bankAccounts)->pluck('name');
                $ids = collect($bankAccounts)->pluck('id');

                // Assert genuine bank accounts are offered
                expect($names)->toContain('Nabil Bank A/C')
                    ->toContain('NIC ASIA A/C 001')
                    ->toContain('Global IME Bank')
                    ->toContain('Everest Bank Overdraft');

                expect($ids)->toContain($accounts['banks']['nabil']->id)
                    ->toContain($accounts['banks']['nicAsia']->id)
                    ->toContain($accounts['banks']['globalIme']->id)
                    ->toContain($accounts['banks']['everest']->id);

                // Assert non-bank ledgers are excluded
                expect($names)->not->toContain('Cash In Hand')
                    ->not->toContain('Stock in Hand')
                    ->not->toContain('Vat Payable')
                    ->not->toContain('TDS Payable')
                    ->not->toContain('Salary Payable')
                    ->not->toContain('Profit & Loss')
                    ->not->toContain('Test Customer')
                    ->not->toContain('Test Supplier')
                    ->not->toContain('Office Furniture');

                expect($ids)->not->toContain($accounts['nonBanks']['cashInHand']->id)
                    ->not->toContain($accounts['nonBanks']['stockInHand']->id)
                    ->not->toContain($accounts['nonBanks']['vatPayable']->id)
                    ->not->toContain($accounts['nonBanks']['tdsPayable']->id)
                    ->not->toContain($accounts['nonBanks']['salaryPayable']->id)
                    ->not->toContain($accounts['nonBanks']['profitLoss']->id)
                    ->not->toContain($accounts['nonBanks']['customerAccount']->id)
                    ->not->toContain($accounts['nonBanks']['supplierAccount']->id)
                    ->not->toContain($accounts['nonBanks']['fixedAsset']->id);

                return true;
            })
        );

    $tenant->delete();
});

test('pos page offers only genuine bank accounts in bankAccounts prop and excludes non-bank ledgers', function () {
    $domain = 'sale-bank-picker-pos.tenant-test';
    $tenant = provisionSaleBankAccountPickerTenant($domain);

    $accounts = null;
    $tenant->run(function () use (&$accounts) {
        User::factory()->create(['email' => 'owner@example.com']);
        $accounts = seedBankAndNonBankAccounts();
    });

    loginSaleBankAccountPickerUser($domain);

    $response = $this->get("http://{$domain}/pos");

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Sales/Pos')
            ->has('bankAccounts')
            ->where('bankAccounts', function ($bankAccounts) use ($accounts) {
                $names = collect($bankAccounts)->pluck('name');
                $ids = collect($bankAccounts)->pluck('id');

                // Assert genuine bank accounts are offered
                expect($names)->toContain('Nabil Bank A/C')
                    ->toContain('NIC ASIA A/C 001')
                    ->toContain('Global IME Bank')
                    ->toContain('Everest Bank Overdraft');

                expect($ids)->toContain($accounts['banks']['nabil']->id)
                    ->toContain($accounts['banks']['nicAsia']->id)
                    ->toContain($accounts['banks']['globalIme']->id)
                    ->toContain($accounts['banks']['everest']->id);

                // Assert non-bank ledgers are excluded
                expect($names)->not->toContain('Cash In Hand')
                    ->not->toContain('Stock in Hand')
                    ->not->toContain('Vat Payable')
                    ->not->toContain('TDS Payable')
                    ->not->toContain('Salary Payable')
                    ->not->toContain('Profit & Loss')
                    ->not->toContain('Test Customer')
                    ->not->toContain('Test Supplier')
                    ->not->toContain('Office Furniture');

                expect($ids)->not->toContain($accounts['nonBanks']['cashInHand']->id)
                    ->not->toContain($accounts['nonBanks']['stockInHand']->id)
                    ->not->toContain($accounts['nonBanks']['vatPayable']->id)
                    ->not->toContain($accounts['nonBanks']['tdsPayable']->id)
                    ->not->toContain($accounts['nonBanks']['salaryPayable']->id)
                    ->not->toContain($accounts['nonBanks']['profitLoss']->id)
                    ->not->toContain($accounts['nonBanks']['customerAccount']->id)
                    ->not->toContain($accounts['nonBanks']['supplierAccount']->id)
                    ->not->toContain($accounts['nonBanks']['fixedAsset']->id);

                return true;
            })
        );

    $tenant->delete();
});

test('posting a sale with bank payment mode accepts genuine bank account and rejects non-bank account', function () {
    $domain = 'sale-bank-picker-post.tenant-test';
    $tenant = provisionSaleBankAccountPickerTenant($domain);

    $customerId = null;
    $itemId = null;
    $validBankAccountId = null;
    $stockInHandAccountId = null;
    $vatPayableAccountId = null;

    $tenant->run(function () use (
        &$customerId,
        &$itemId,
        &$validBankAccountId,
        &$stockInHandAccountId,
        &$vatPayableAccountId
    ) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create([
            'name' => 'FY1',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open,
        ]);
        CompanySetting::current()->update(['allow_negative_stock' => true]);

        $accounts = seedBankAndNonBankAccounts();
        $validBankAccountId = $accounts['banks']['nabil']->id;
        $stockInHandAccountId = $accounts['nonBanks']['stockInHand']->id;
        $vatPayableAccountId = $accounts['nonBanks']['vatPayable']->id;

        $customerId = $accounts['customer']->id;
        $itemId = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false])->id;
    });

    loginSaleBankAccountPickerUser($domain);

    // 1. Submit valid bank payment mode with genuine bank account
    $validResponse = $this->from("http://{$domain}/sales")->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'full',
        'date' => '2026-06-01',
        'payment_mode' => 'bank',
        'bank_account_id' => $validBankAccountId,
        'lines' => [
            ['item_id' => $itemId, 'quantity' => 1, 'rate' => 100],
        ],
        'expected_total' => '100.00',
    ]);

    $validResponse->assertSessionHasNoErrors();
    $validResponse->assertRedirect("http://{$domain}/sales");

    $tenant->run(function () use ($validBankAccountId) {
        $sale = Sale::query()->latest('id')->first();
        expect($sale)->not->toBeNull();
        expect($sale->payment_mode)->toBe('bank');
        expect($sale->bank_account_id)->toBe($validBankAccountId);
        expect(Sale::query()->count())->toBe(1);
    });

    // 2. Submit with non-bank account (Stock in Hand)
    $invalidResponseStock = $this->from("http://{$domain}/sales")->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'full',
        'date' => '2026-06-01',
        'payment_mode' => 'bank',
        'bank_account_id' => $stockInHandAccountId,
        'lines' => [
            ['item_id' => $itemId, 'quantity' => 1, 'rate' => 100],
        ],
        'expected_total' => '100.00',
    ]);

    $invalidResponseStock->assertSessionHasErrors('bank_account_id');

    // 3. Submit with another non-bank account (Vat Payable)
    $invalidResponseVat = $this->from("http://{$domain}/sales")->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'full',
        'date' => '2026-06-01',
        'payment_mode' => 'bank',
        'bank_account_id' => $vatPayableAccountId,
        'lines' => [
            ['item_id' => $itemId, 'quantity' => 1, 'rate' => 100],
        ],
        'expected_total' => '100.00',
    ]);

    $invalidResponseVat->assertSessionHasErrors('bank_account_id');

    $tenant->run(function () {
        expect(Sale::query()->count())->toBe(1);
    });

    $tenant->delete();
});
