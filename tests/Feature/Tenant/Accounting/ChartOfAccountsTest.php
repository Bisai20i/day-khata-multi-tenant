<?php

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\AccountSubgroup;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Tenancy has no automatic "end of request" hook outside of a real PHP-FPM
 * style process boundary, so within a single test process the tenant
 * connection stays the default connection after an HTTP call. Revert to the
 * central connection after every test so RefreshDatabase's teardown rolls
 * back the connection it actually started a transaction on.
 */
afterEach(function () {
    tenancy()->end();
});

function provisionChartTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginChartTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('provisioning seeds the default chart of accounts', function () {
    $domain = 'coa-seed.tenant-test';
    $tenant = provisionChartTestTenant($domain);

    $tenant->run(function () {
        expect(AccountHead::query()->pluck('name')->sort()->values()->all())
            ->toBe(['Assets', 'Capital', 'Expenses', 'Income', 'Liabilities']);

        expect(AccountSubgroup::query()->where('name', 'Sundry Debtors')->exists())->toBeTrue();
        expect(AccountSubgroup::query()->where('name', 'Sundry Creditors')->exists())->toBeTrue();
        expect(Account::query()->where('code', 'AS1')->where('name', 'Cash In Hand')->exists())->toBeTrue();
        expect(Account::query()->where('code', 'INI20')->where('name', 'Sales Account')->exists())->toBeTrue();
    });

    $tenant->delete();
});

test('an account cannot be created without a group or a subgroup', function () {
    $domain = 'coa-orphan.tenant-test';
    $tenant = provisionChartTestTenant($domain);

    $tenant->run(function () {
        expect(fn () => Account::create(['name' => 'Orphan Account']))
            ->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('an account cannot be created with both a group and a subgroup', function () {
    $domain = 'coa-both-parents.tenant-test';
    $tenant = provisionChartTestTenant($domain);

    $tenant->run(function () {
        $subgroup = AccountSubgroup::query()->where('name', 'Sundry Debtors')->firstOrFail();

        expect(fn () => Account::create([
            'account_group_id' => $subgroup->account_group_id,
            'account_subgroup_id' => $subgroup->id,
            'name' => 'Ambiguous Account',
        ]))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('an authenticated user can create an account group under an existing head', function () {
    $domain = 'coa-group-store.tenant-test';
    $tenant = provisionChartTestTenant($domain);

    $headId = null;
    $tenant->run(function () use (&$headId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $headId = AccountHead::query()->where('name', 'Assets')->value('id');
    });

    loginChartTestUser($domain);

    $response = $this->post("http://{$domain}/account-groups", [
        'account_head_id' => $headId,
        'name' => 'Investments',
    ]);

    $response->assertRedirect("http://{$domain}/account-groups");

    $tenant->run(function () use ($headId) {
        expect(
            AccountGroup::query()
                ->where('account_head_id', $headId)
                ->where('name', 'Investments')
                ->exists()
        )->toBeTrue();
    });

    $tenant->delete();
});

test('a duplicate account group name under the same head is rejected', function () {
    $domain = 'coa-group-duplicate.tenant-test';
    $tenant = provisionChartTestTenant($domain);

    $headId = null;
    $tenant->run(function () use (&$headId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $headId = AccountHead::query()->where('name', 'Assets')->value('id');
    });

    loginChartTestUser($domain);

    $response = $this->post("http://{$domain}/account-groups", [
        'account_head_id' => $headId,
        'name' => 'Current Assets',
    ]);

    $response->assertSessionHasErrors('name');

    $tenant->delete();
});

test('an authenticated user can create a leaf account directly under a group', function () {
    $domain = 'coa-account-under-group.tenant-test';
    $tenant = provisionChartTestTenant($domain);

    $groupId = null;
    $tenant->run(function () use (&$groupId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $groupId = AccountGroup::query()->where('name', 'Sales Accounts')->value('id');
    });

    loginChartTestUser($domain);

    $response = $this->post("http://{$domain}/accounts", [
        'account_group_id' => $groupId,
        'name' => 'Export Sales',
        'code' => 'INI99',
    ]);

    $response->assertRedirect("http://{$domain}/accounts");

    $tenant->run(function () use ($groupId) {
        $account = Account::query()->where('code', 'INI99')->firstOrFail();
        expect($account->account_group_id)->toBe($groupId);
        expect($account->account_subgroup_id)->toBeNull();
    });

    $tenant->delete();
});

test('creating a leaf account without a group or subgroup fails validation', function () {
    $domain = 'coa-account-missing-parent.tenant-test';
    $tenant = provisionChartTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginChartTestUser($domain);

    $response = $this->post("http://{$domain}/accounts", [
        'name' => 'Nowhere Account',
    ]);

    $response->assertSessionHasErrors(['account_group_id', 'account_subgroup_id']);

    $tenant->delete();
});
