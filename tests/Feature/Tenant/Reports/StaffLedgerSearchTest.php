<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\StaffMember;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionStaffLedgerTenant(string $domain): Tenant
{
    $tenant = Tenant::create([
        'company_name' => 'Staff Ledger Test Co',
        'enabled_modules' => config('permissions.default_modules'),
    ]);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginStaffLedgerUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('general ledger report includes staff members in staffList prop and can be searched', function () {
    $domain = 'report-ledger-staff-list.tenant-test';
    $tenant = provisionStaffLedgerTenant($domain);

    $staffId = null;

    $tenant->run(function () use (&$staffId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);
        Account::factory()->create(['code' => 'AS-100', 'name' => 'Main Cash']);

        $staff = StaffMember::create([
            'name' => 'Ram Bahadur',
            'staff_code' => 'STF-0001',
            'designation' => 'Senior Accountant',
            'department' => 'Finance',
            'is_active' => true,
        ]);
        $staffId = $staff->id;
    });

    loginStaffLedgerUser($domain);

    $this->get("http://{$domain}/reports/ledger")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/Ledger')
            ->has('accounts')
            ->has('staffList', 1)
            ->where('staffList.0.id', $staffId)
            ->where('staffList.0.name', 'Ram Bahadur')
            ->where('staffList.0.staff_code', 'STF-0001')
            ->where('staff', null)
        );

    $tenant->delete();
});

test('general ledger page loads staff salary and advance ledgers when staff_id is passed', function () {
    $domain = 'report-ledger-staff-view.tenant-test';
    $tenant = provisionStaffLedgerTenant($domain);

    $staffId = null;

    $tenant->run(function () use (&$staffId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);

        $staff = StaffMember::create([
            'name' => 'Sita Sharma',
            'staff_code' => 'STF-0002',
            'designation' => 'Sales Officer',
            'department' => 'Sales',
            'is_active' => true,
        ]);
        $staffId = $staff->id;
    });

    loginStaffLedgerUser($domain);

    $this->get("http://{$domain}/reports/ledger?staff_id={$staffId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/Ledger')
            ->where('staffId', $staffId)
            ->where('staff.name', 'Sita Sharma')
            ->where('accountId', null)
            ->where('account', null)
            ->has('staffSalaryEntries')
            ->has('staffAdvanceEntries')
            ->where('staffOpeningSalary', '0.00')
            ->where('staffOpeningAdvance', '0.00')
            ->where('staffClosingSalary', '0.00')
            ->where('staffClosingAdvance', '0.00')
        );

    $tenant->delete();
});

test('general ledger reflects staff advance and running balance', function () {
    $domain = 'report-ledger-staff-advance.tenant-test';
    $tenant = provisionStaffLedgerTenant($domain);

    $staffId = null;

    $tenant->run(function () use (&$staffId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);

        $staff = StaffMember::create([
            'name' => 'Hari Prasad',
            'staff_code' => 'STF-0003',
            'designation' => 'Warehouse Manager',
            'is_active' => true,
        ]);
        $staffId = $staff->id;
    });

    loginStaffLedgerUser($domain);

    // Issue a staff advance
    $this->post("http://{$domain}/hr/staff-advances", [
        'staff_id' => $staffId,
        'date' => '2024-08-10',
        'amount' => '5000.00',
        'payment_mode' => 'cash',
        'narration' => 'Festival advance',
    ])->assertRedirect();

    // Query ledger for staff
    $this->get("http://{$domain}/reports/ledger?staff_id={$staffId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/Ledger')
            ->where('staffId', $staffId)
            ->has('staffAdvanceEntries', 1)
            ->where('staffClosingAdvance', '5000.00')
        );

    $tenant->delete();
});

test('general ledger staff export and print routes return valid responses', function () {
    $domain = 'report-ledger-staff-export.tenant-test';
    $tenant = provisionStaffLedgerTenant($domain);

    $staffId = null;

    $tenant->run(function () use (&$staffId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);

        $staff = StaffMember::create([
            'name' => 'Gita KC',
            'staff_code' => 'STF-0004',
            'designation' => 'HR Specialist',
            'is_active' => true,
        ]);
        $staffId = $staff->id;
    });

    loginStaffLedgerUser($domain);

    $this->get("http://{$domain}/reports/ledger/print?staff_id={$staffId}")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->get("http://{$domain}/reports/ledger/export?staff_id={$staffId}")
        ->assertOk()
        ->assertHeader('content-disposition');

    $tenant->delete();
});

test('hr staff statement page returns staffOptions and statement data', function () {
    $domain = 'hr-statement-options.tenant-test';
    $tenant = provisionStaffLedgerTenant($domain);

    $staffId = null;

    $tenant->run(function () use (&$staffId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => '2081/82', 'start_date' => '2024-07-16', 'end_date' => '2025-07-15', 'status' => FiscalYearStatus::Open]);

        $staff = StaffMember::create([
            'name' => 'Kamal Thapa',
            'staff_code' => 'STF-0005',
            'designation' => 'Driver',
            'is_active' => true,
        ]);
        $staffId = $staff->id;
    });

    loginStaffLedgerUser($domain);

    $this->get("http://{$domain}/hr/staff/{$staffId}/statement")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Hr/Reports/StaffStatement')
            ->where('staff.id', $staffId)
            ->has('staffOptions', 1)
            ->where('staffOptions.0.value', $staffId)
        );

    $tenant->delete();
});
