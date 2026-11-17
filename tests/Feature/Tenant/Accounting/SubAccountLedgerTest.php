<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\AccountSubgroup;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSubAccountTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Sub-Ledger Corp']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginSubAccountTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);
}

test('an account can be created with parent_account_id and inherits group or subgroup when omitted', function () {
    $domain = 'subacc-create.tenant-test';
    $tenant = provisionSubAccountTestTenant($domain);

    $parentGroupId = null;
    $parentSubgroupId = null;
    $parentGroupAccountId = null;
    $parentSubgroupAccountId = null;

    $tenant->run(function () use (&$parentGroupId, &$parentSubgroupId, &$parentGroupAccountId, &$parentSubgroupAccountId) {
        User::factory()->create([
            'email' => 'admin@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);

        $group = AccountGroup::query()->where('name', 'Sales Accounts')->firstOrFail();
        $parentGroupId = $group->id;
        $parentGroupAccount = Account::create([
            'account_group_id' => $group->id,
            'name' => 'Main Sales',
            'code' => 'MS-100',
        ]);
        $parentGroupAccountId = $parentGroupAccount->id;

        $subgroup = AccountSubgroup::query()->where('name', 'Sundry Debtors')->firstOrFail();
        $parentSubgroupId = $subgroup->id;
        $parentSubgroupAccount = Account::create([
            'account_subgroup_id' => $subgroup->id,
            'name' => 'Main Debtors',
            'code' => 'MD-200',
        ]);
        $parentSubgroupAccountId = $parentSubgroupAccount->id;
    });

    loginSubAccountTestUser($domain);

    // 1. Create sub-account under group-based parent
    $responseGroupChild = test()->post("http://{$domain}/accounts", [
        'parent_account_id' => $parentGroupAccountId,
        'name' => 'Retail Sales Counter',
        'code' => 'RS-101',
    ]);
    $responseGroupChild->assertRedirect("http://{$domain}/accounts");

    // 2. Create sub-account under subgroup-based parent
    $responseSubgroupChild = test()->post("http://{$domain}/accounts", [
        'parent_account_id' => $parentSubgroupAccountId,
        'name' => 'Debtor Branch North',
        'code' => 'DB-201',
    ]);
    $responseSubgroupChild->assertRedirect("http://{$domain}/accounts");

    $tenant->run(function () use ($parentGroupAccountId, $parentGroupId, $parentSubgroupAccountId, $parentSubgroupId) {
        $groupChild = Account::where('code', 'RS-101')->firstOrFail();
        expect($groupChild->parent_account_id)->toBe($parentGroupAccountId);
        expect($groupChild->account_group_id)->toBe($parentGroupId);
        expect($groupChild->account_subgroup_id)->toBeNull();
        expect($groupChild->parent)->not->toBeNull();
        expect($groupChild->parent->id)->toBe($parentGroupAccountId);

        $subgroupChild = Account::where('code', 'DB-201')->firstOrFail();
        expect($subgroupChild->parent_account_id)->toBe($parentSubgroupAccountId);
        expect($subgroupChild->account_group_id)->toBeNull();
        expect($subgroupChild->account_subgroup_id)->toBe($parentSubgroupId);
        expect($subgroupChild->parent)->not->toBeNull();
        expect($subgroupChild->parent->id)->toBe($parentSubgroupAccountId);
    });

    $tenant->delete();
});

test('parent and subAccounts relationships and allSubAccountIds return correct hierarchy', function () {
    $domain = 'subacc-hierarchy.tenant-test';
    $tenant = provisionSubAccountTestTenant($domain);

    $tenant->run(function () {
        $group = AccountGroup::query()->where('name', 'Sales Accounts')->firstOrFail();

        $parent = Account::create([
            'account_group_id' => $group->id,
            'name' => 'Parent Account',
            'code' => 'P-01',
        ]);

        $child1 = Account::create([
            'account_group_id' => $group->id,
            'parent_account_id' => $parent->id,
            'name' => 'Child Account 1',
            'code' => 'C-01',
        ]);

        $child2 = Account::create([
            'account_group_id' => $group->id,
            'parent_account_id' => $parent->id,
            'name' => 'Child Account 2',
            'code' => 'C-02',
        ]);

        $grandchild = Account::create([
            'account_group_id' => $group->id,
            'parent_account_id' => $child1->id,
            'name' => 'Grandchild Account 1',
            'code' => 'GC-01',
        ]);

        // Relationships
        expect($child1->parent->id)->toBe($parent->id);
        expect($child2->parent->id)->toBe($parent->id);
        expect($grandchild->parent->id)->toBe($child1->id);

        $subAccountIds = $parent->subAccounts->pluck('id')->sort()->values()->all();
        $expectedSubAccountIds = collect([$child1->id, $child2->id])->sort()->values()->all();
        expect($subAccountIds)->toBe($expectedSubAccountIds);

        // allSubAccountIds helper method
        $allFromParent = $parent->allSubAccountIds();
        sort($allFromParent);
        $expectedAllFromParent = [$child1->id, $child2->id, $grandchild->id];
        sort($expectedAllFromParent);
        expect($allFromParent)->toBe($expectedAllFromParent);

        expect($child1->allSubAccountIds())->toBe([$grandchild->id]);
        expect($child2->allSubAccountIds())->toBe([]);
        expect($grandchild->allSubAccountIds())->toBe([]);
    });

    $tenant->delete();
});

test('self-parenting is rejected during update', function () {
    $domain = 'subacc-self-parent.tenant-test';
    $tenant = provisionSubAccountTestTenant($domain);

    $accountId = null;
    $tenant->run(function () use (&$accountId) {
        User::factory()->create([
            'email' => 'admin@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);

        $group = AccountGroup::query()->where('name', 'Sales Accounts')->firstOrFail();
        $account = Account::create([
            'account_group_id' => $group->id,
            'name' => 'Independent Account',
            'code' => 'IND-01',
        ]);
        $accountId = $account->id;
    });

    loginSubAccountTestUser($domain);

    // Controller validation rejects setting parent to oneself
    $response = test()->put("http://{$domain}/accounts/{$accountId}", [
        'name' => 'Independent Account Renamed',
        'parent_account_id' => $accountId,
    ]);
    $response->assertSessionHasErrors('parent_account_id');

    // Model saving guard also throws InvalidArgumentException
    $tenant->run(function () use ($accountId) {
        $account = Account::findOrFail($accountId);
        expect(fn () => $account->update(['parent_account_id' => $account->id]))
            ->toThrow(InvalidArgumentException::class, 'An account cannot have itself or one of its sub-accounts as parent.');
    });

    $tenant->delete();
});

test('parent_type account requires parent_account_id to be selected', function () {
    $domain = 'subacc-parent-req.tenant-test';
    $tenant = provisionSubAccountTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create([
            'email' => 'admin@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);
    });

    loginSubAccountTestUser($domain);

    $response = test()->post("http://{$domain}/accounts", [
        'parent_type' => 'account',
        'parent_account_id' => null,
        'name' => 'Orphan Sub Account',
    ]);

    $response->assertSessionHasErrors([
        'parent_account_id' => 'Please select a parent account.',
    ]);

    $tenant->delete();
});

test('descendant parenting is rejected during update', function () {
    $domain = 'subacc-circular.tenant-test';
    $tenant = provisionSubAccountTestTenant($domain);

    $parentId = null;
    $childId = null;
    $grandchildId = null;

    $tenant->run(function () use (&$parentId, &$childId, &$grandchildId) {
        User::factory()->create([
            'email' => 'admin@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);

        $group = AccountGroup::query()->where('name', 'Sales Accounts')->firstOrFail();

        $parent = Account::create([
            'account_group_id' => $group->id,
            'name' => 'Parent Account Circular',
            'code' => 'PAC-01',
        ]);
        $parentId = $parent->id;

        $child = Account::create([
            'account_group_id' => $group->id,
            'parent_account_id' => $parent->id,
            'name' => 'Child Account Circular',
            'code' => 'CAC-01',
        ]);
        $childId = $child->id;

        $grandchild = Account::create([
            'account_group_id' => $group->id,
            'parent_account_id' => $child->id,
            'name' => 'Grandchild Account Circular',
            'code' => 'GAC-01',
        ]);
        $grandchildId = $grandchild->id;
    });

    loginSubAccountTestUser($domain);

    // Controller validation rejects setting parent to a child or grandchild
    $responseChild = test()->put("http://{$domain}/accounts/{$parentId}", [
        'name' => 'Parent Account Circular Renamed',
        'parent_type' => 'account',
        'parent_account_id' => $childId,
    ]);
    $responseChild->assertSessionHasErrors([
        'parent_account_id' => 'An account cannot have itself or one of its sub-accounts as parent.',
    ]);

    $responseGrandchild = test()->put("http://{$domain}/accounts/{$parentId}", [
        'name' => 'Parent Account Circular Renamed',
        'parent_type' => 'account',
        'parent_account_id' => $grandchildId,
    ]);
    $responseGrandchild->assertSessionHasErrors([
        'parent_account_id' => 'An account cannot have itself or one of its sub-accounts as parent.',
    ]);

    // Model saving guard also throws InvalidArgumentException
    $tenant->run(function () use ($parentId, $childId, $grandchildId) {
        $parent = Account::findOrFail($parentId);
        expect(fn () => $parent->update(['parent_account_id' => $childId]))
            ->toThrow(InvalidArgumentException::class, 'An account cannot have itself or one of its sub-accounts as parent.');

        expect(fn () => $parent->update(['parent_account_id' => $grandchildId]))
            ->toThrow(InvalidArgumentException::class, 'An account cannot have itself or one of its sub-accounts as parent.');
    });

    $tenant->delete();
});

test('deletion blocker prevents deleting an account that has sub-accounts', function () {
    $domain = 'subacc-delete-blocker.tenant-test';
    $tenant = provisionSubAccountTestTenant($domain);

    $parentId = null;
    $childId = null;

    $tenant->run(function () use (&$parentId, &$childId) {
        User::factory()->create([
            'email' => 'admin@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);

        $group = AccountGroup::query()->where('name', 'Sales Accounts')->firstOrFail();

        $parent = Account::create([
            'account_group_id' => $group->id,
            'name' => 'Parent With Children',
            'code' => 'P-DEL-01',
        ]);
        $parentId = $parent->id;

        $child = Account::create([
            'account_group_id' => $group->id,
            'parent_account_id' => $parent->id,
            'name' => 'Active Sub Account',
            'code' => 'C-DEL-01',
        ]);
        $childId = $child->id;

        // Model level check
        expect($parent->deletionBlocker())->toBe('This account has sub-accounts and cannot be deleted.');
        expect(fn () => $parent->delete())
            ->toThrow(InvalidArgumentException::class, 'This account has sub-accounts and cannot be deleted.');
    });

    loginSubAccountTestUser($domain);

    // HTTP DELETE on parent account fails
    $response = test()->delete("http://{$domain}/accounts/{$parentId}");
    $response->assertSessionHasErrors('account');

    $tenant->run(function () use ($parentId, $childId) {
        expect(Account::whereKey($parentId)->exists())->toBeTrue();

        // Delete child account first
        $child = Account::findOrFail($childId);
        $child->delete();

        // Parent should now have no sub-accounts and no deletion blocker
        $parent = Account::findOrFail($parentId);
        expect($parent->deletionBlocker())->toBeNull();
    });

    // Parent can now be deleted successfully
    $responseAfterChildDeleted = test()->delete("http://{$domain}/accounts/{$parentId}");
    $responseAfterChildDeleted->assertRedirect("http://{$domain}/accounts");

    $tenant->run(function () use ($parentId) {
        expect(Account::whereKey($parentId)->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('journal vouchers can be posted directly to parent account and to child account', function () {
    $domain = 'subacc-journal-post.tenant-test';
    $tenant = provisionSubAccountTestTenant($domain);

    $tenant->run(function () {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);

        FiscalYear::create([
            'name' => 'FY 2083/84',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open,
        ]);

        $group = AccountGroup::query()->where('name', 'Sales Accounts')->firstOrFail();
        $salesAccount = Account::where('code', 'INI20')->firstOrFail();

        $parent = Account::create([
            'account_group_id' => $group->id,
            'name' => 'Regional Office Cash',
            'code' => 'ROC-01',
        ]);

        $child = Account::create([
            'account_group_id' => $group->id,
            'parent_account_id' => $parent->id,
            'name' => 'Branch Counter Cash',
            'code' => 'BCC-01',
        ]);

        // 1. Post voucher to parent account directly
        $voucherParent = JournalVoucher::post(
            ['date' => '2026-02-01', 'narration' => 'Post to parent account'],
            [
                ['account_id' => $parent->id, 'debit' => 1500, 'credit' => 0],
                ['account_id' => $salesAccount->id, 'debit' => 0, 'credit' => 1500],
            ],
            $admin,
        );

        // 2. Post voucher to child account directly
        $voucherChild = JournalVoucher::post(
            ['date' => '2026-02-02', 'narration' => 'Post to child account'],
            [
                ['account_id' => $child->id, 'debit' => 800, 'credit' => 0],
                ['account_id' => $salesAccount->id, 'debit' => 0, 'credit' => 800],
            ],
            $admin,
        );

        expect($voucherParent->exists)->toBeTrue();
        expect($voucherChild->exists)->toBeTrue();

        // Verify journal lines
        expect(JournalVoucherLine::where('account_id', $parent->id)->count())->toBe(1);
        expect(JournalVoucherLine::where('account_id', $child->id)->count())->toBe(1);

        expect($parent->hasAnyLedgerLine())->toBeTrue();
        expect($child->hasAnyLedgerLine())->toBeTrue();
    });

    $tenant->delete();
});

test('index lists accounts with parent eager-loaded and provides parentAccounts', function () {
    $domain = 'subacc-index.tenant-test';
    $tenant = provisionSubAccountTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create([
            'email' => 'admin@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);

        $group = AccountGroup::query()->where('name', 'Sales Accounts')->firstOrFail();
        $parent = Account::create([
            'account_group_id' => $group->id,
            'name' => 'Main Account For Index',
            'code' => 'MA-INDEX',
        ]);

        Account::create([
            'account_group_id' => $group->id,
            'parent_account_id' => $parent->id,
            'name' => 'Child Account For Index',
            'code' => 'CA-INDEX',
        ]);
    });

    loginSubAccountTestUser($domain);

    $response = test()->get("http://{$domain}/accounts");
    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Tenant/Accounting/Accounts/Index')
        ->has('parentAccounts')
        ->has('accounts')
    );

    $tenant->delete();
});

test('general ledger report rolls up sub-account entries when include_sub_accounts is enabled', function () {
    $domain = 'subacc-report.tenant-test';
    $tenant = provisionSubAccountTestTenant($domain);

    $parentId = null;
    $childId = null;

    $tenant->run(function () use (&$parentId, &$childId) {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);

        FiscalYear::create([
            'name' => 'FY 2083/84',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open,
        ]);

        $group = AccountGroup::query()->where('name', 'Sales Accounts')->firstOrFail();
        $salesAccount = Account::where('code', 'INI20')->firstOrFail();

        $parent = Account::create([
            'account_group_id' => $group->id,
            'name' => 'Furniture & Fixture',
            'code' => 'FF-01',
        ]);
        $parentId = $parent->id;

        $child = Account::create([
            'account_group_id' => $group->id,
            'parent_account_id' => $parent->id,
            'name' => 'Office Table',
            'code' => 'FF-TAB',
        ]);
        $childId = $child->id;

        JournalVoucher::post(
            ['date' => '2026-02-01', 'narration' => 'Purchase of general furniture'],
            [
                ['account_id' => $parent->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $salesAccount->id, 'debit' => 0, 'credit' => 1000],
            ],
            $admin,
        );

        JournalVoucher::post(
            ['date' => '2026-02-02', 'narration' => 'Purchase of office table'],
            [
                ['account_id' => $child->id, 'debit' => 500, 'credit' => 0],
                ['account_id' => $salesAccount->id, 'debit' => 0, 'credit' => 500],
            ],
            $admin,
        );
    });

    loginSubAccountTestUser($domain);

    // 1. With include_sub_accounts = true (default)
    $responseIncluded = test()->get("http://{$domain}/reports/ledger?account_id={$parentId}&include_sub_accounts=1");
    $responseIncluded->assertOk()->assertInertia(fn ($page) => $page
        ->component('Tenant/Reports/Ledger')
        ->where('includeSubAccounts', true)
        ->has('subAccounts', 1)
        ->where('subAccounts.0.id', $childId)
        ->has('entries', 2)
    );

    // 2. With include_sub_accounts = false
    $responseExcluded = test()->get("http://{$domain}/reports/ledger?account_id={$parentId}&include_sub_accounts=0");
    $responseExcluded->assertOk()->assertInertia(fn ($page) => $page
        ->component('Tenant/Reports/Ledger')
        ->where('includeSubAccounts', false)
        ->has('entries', 1)
    );

    $tenant->delete();
});
