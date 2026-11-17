<?php

use App\Models\Account;
use App\Models\AccountSubgroup;
use App\Models\StaffMember;
use App\Models\Tenant;
use App\Support\Hr\HrAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function staffLedgerTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create([
        'company_name' => 'Acme Staff Ledger Corp',
    ]);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('creating a staff member auto-creates a ledger account under Salary Payable parented to LIA22', function () {
    $tenant = staffLedgerTestTenant('staff-create-ledger.tenant-test');

    $tenant->run(function () {
        $parentAccount = Account::where('code', HrAccounts::SALARY_PAYABLE)->firstOrFail();
        $subgroup = AccountSubgroup::where('name', 'Salary Payable')->firstOrFail();

        $staff = StaffMember::factory()->create([
            'name' => 'Ram Bahadur Thapa',
            'mobile_no' => '9841234567',
            'address' => 'Kathmandu, Bagmati',
        ]);

        expect($staff->account_id)->not()->toBeNull();

        $account = $staff->account;
        expect($account)->not()->toBeNull()
            ->and($account->id)->toBe($staff->account_id)
            ->and($account->name)->toBe('Ram Bahadur Thapa')
            ->and($account->phone)->toBe('9841234567')
            ->and($account->address)->toBe('Kathmandu, Bagmati')
            ->and($account->parent_account_id)->toBe($parentAccount->id)
            ->and($account->account_subgroup_id)->toBe($subgroup->id)
            ->and($account->parent->id)->toBe($parentAccount->id);

        expect($parentAccount->subAccounts()->pluck('id')->all())->toContain($account->id);
    });
});

test('updating staff member name, mobile_no, and address updates the linked account', function () {
    $tenant = staffLedgerTestTenant('staff-update-ledger.tenant-test');

    $tenant->run(function () {
        $staff = StaffMember::factory()->create([
            'name' => 'Initial Name',
            'mobile_no' => '9801111111',
            'address' => 'Pokhara, Kaski',
            'designation' => 'Junior Accountant',
        ]);

        $account = $staff->account;
        expect($account->name)->toBe('Initial Name')
            ->and($account->phone)->toBe('9801111111')
            ->and($account->address)->toBe('Pokhara, Kaski');

        $staff->update([
            'name' => 'Updated Name',
            'mobile_no' => '9802222222',
            'address' => 'Lalitpur, Bagmati',
        ]);

        $account->refresh();
        expect($account->name)->toBe('Updated Name')
            ->and($account->phone)->toBe('9802222222')
            ->and($account->address)->toBe('Lalitpur, Bagmati');

        // Updating unrelated field does not change account attributes
        $staff->update([
            'designation' => 'Senior Accountant',
        ]);

        $account->refresh();
        expect($account->name)->toBe('Updated Name')
            ->and($account->phone)->toBe('9802222222')
            ->and($account->address)->toBe('Lalitpur, Bagmati');
    });
});

test('deleting an account linked to a staff member is blocked by deletionBlocker', function () {
    $tenant = staffLedgerTestTenant('staff-delete-blocker.tenant-test');

    $tenant->run(function () {
        $staff = StaffMember::factory()->create([
            'name' => 'Sita Sharma',
        ]);

        $account = $staff->account;
        expect($account)->not()->toBeNull();

        $blocker = $account->deletionBlocker();
        expect($blocker)->not()->toBeNull()
            ->and($blocker)->toContain('hr staff');
    });
});

test('creating a staff member when Salary Payable subgroup does not exist auto-creates the subgroup under Current Liabilities', function () {
    $tenant = staffLedgerTestTenant('staff-auto-subgroup.tenant-test');

    $tenant->run(function () {
        // Remove the seeded subgroup if present
        AccountSubgroup::where('name', 'Salary Payable')->delete();
        expect(AccountSubgroup::where('name', 'Salary Payable')->exists())->toBeFalse();

        $staff = StaffMember::factory()->create([
            'name' => 'Nabin Shrestha',
        ]);

        $recreatedSubgroup = AccountSubgroup::where('name', 'Salary Payable')->first();
        expect($recreatedSubgroup)->not()->toBeNull()
            ->and($recreatedSubgroup->accountGroup->name)->toBe('Current Liabilities');

        $account = $staff->account;
        expect($account)->not()->toBeNull()
            ->and($account->account_subgroup_id)->toBe($recreatedSubgroup->id);
    });
});
