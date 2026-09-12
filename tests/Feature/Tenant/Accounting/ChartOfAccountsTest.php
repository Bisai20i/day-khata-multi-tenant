<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\AccountSubgroup;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Role;
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
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
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
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
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
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
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
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
    });

    loginChartTestUser($domain);

    $response = $this->post("http://{$domain}/accounts", [
        'name' => 'Nowhere Account',
    ]);

    $response->assertSessionHasErrors(['account_group_id', 'account_subgroup_id']);

    $tenant->delete();
});

test('a staff user gets a 403 on every chart of accounts write route', function () {
    $domain = 'coa-admin-gate.tenant-test';
    $tenant = provisionChartTestTenant($domain);

    $groupId = null;
    $subgroupId = null;
    $accountId = null;
    $headId = null;
    $tenant->run(function () use (&$groupId, &$subgroupId, &$accountId, &$headId) {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'staff')->value('id')]);
        $groupId = AccountGroup::query()->where('name', 'Sales Accounts')->value('id');
        $subgroupId = AccountSubgroup::query()->where('name', 'Sundry Debtors')->value('id');
        $accountId = Account::query()->where('code', 'AS1')->value('id');
        $headId = AccountHead::query()->where('name', 'Assets')->value('id');
    });

    loginChartTestUser($domain);

    // Reading stays open so staff can look an account up.
    $this->get("http://{$domain}/accounts")->assertOk();
    $this->get("http://{$domain}/account-groups")->assertOk();

    $this->post("http://{$domain}/account-groups", ['account_head_id' => $headId, 'name' => 'Investments'])->assertForbidden();
    $this->put("http://{$domain}/account-groups/{$groupId}", ['account_head_id' => $headId, 'name' => 'Renamed'])->assertForbidden();
    $this->delete("http://{$domain}/account-groups/{$groupId}")->assertForbidden();

    $this->post("http://{$domain}/account-subgroups", ['account_group_id' => $groupId, 'name' => 'New Subgroup'])->assertForbidden();
    $this->put("http://{$domain}/account-subgroups/{$subgroupId}", ['account_group_id' => $groupId, 'name' => 'Renamed'])->assertForbidden();
    $this->delete("http://{$domain}/account-subgroups/{$subgroupId}")->assertForbidden();

    $this->post("http://{$domain}/accounts", ['account_group_id' => $groupId, 'name' => 'Export Sales'])->assertForbidden();
    $this->put("http://{$domain}/accounts/{$accountId}", ['account_group_id' => $groupId, 'name' => 'Renamed'])->assertForbidden();
    $this->delete("http://{$domain}/accounts/{$accountId}")->assertForbidden();

    $tenant->run(function () {
        expect(AccountGroup::query()->where('name', 'Investments')->exists())->toBeFalse()
            ->and(Account::query()->where('name', 'Export Sales')->exists())->toBeFalse()
            ->and(Account::query()->where('code', 'AS1')->exists())->toBeTrue();
    });

    $tenant->delete();
});

test('an account group cannot be moved to a different head once its accounts have postings', function () {
    $domain = 'coa-group-head-locked.tenant-test';
    $tenant = provisionChartTestTenant($domain);

    $groupId = null;
    $otherHeadId = null;
    $sameHeadId = null;
    $tenant->run(function () use (&$groupId, &$otherHeadId, &$sameHeadId) {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create([
            'name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open,
        ]);

        $sales = Account::query()->where('code', 'INI20')->firstOrFail();
        $groupId = $sales->account_group_id;
        $sameHeadId = AccountGroup::query()->whereKey($groupId)->value('account_head_id');
        $otherHeadId = AccountHead::query()->where('name', 'Assets')->value('id');

        JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Cash sale'],
            [
                ['account_id' => Account::query()->where('code', 'AS1')->value('id'), 'debit' => 100, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 100],
            ],
            User::first(),
        );
    });

    loginChartTestUser($domain);

    $this->put("http://{$domain}/account-groups/{$groupId}", [
        'account_head_id' => $otherHeadId,
        'name' => 'Sales Accounts',
    ])->assertSessionHasErrors('account_head_id');

    // Renaming without moving heads is still allowed.
    $this->put("http://{$domain}/account-groups/{$groupId}", [
        'account_head_id' => $sameHeadId,
        'name' => 'Sales Accounts Renamed',
    ])->assertSessionHasNoErrors();

    $tenant->run(function () use ($groupId, $sameHeadId) {
        $group = AccountGroup::query()->findOrFail($groupId);

        expect($group->account_head_id)->toBe($sameHeadId)
            ->and($group->name)->toBe('Sales Accounts Renamed');
    });

    $tenant->delete();
});
