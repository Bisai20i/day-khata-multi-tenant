<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
    Carbon::setTestNow();
});

/**
 * "Today" for every test below: AD 2026-07-20, which is BS 2083-04-04 -
 * inside the 2083/84 fiscal year (Shrawan 1, 2083 = AD 2026-07-17 through
 * Ashad end, 2084 = AD 2027-07-16). Verified against App\Support\NepaliCalendar
 * (itself cross-checked against the legacy day_khata app's conversion table)
 * before writing this test.
 */
function freezeAutoStartFiscalYearToday(): void
{
    Carbon::setTestNow(Carbon::create(2026, 7, 20));
}

function provisionAutoStartTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function createAdminActor(): User
{
    $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();

    return User::factory()->create(['role_id' => $adminRole->id]);
}

test('it rolls a tenant whose open fiscal year has run past Shrawan 1 onto the next one', function () {
    freezeAutoStartFiscalYearToday();

    $tenant = provisionAutoStartTestTenant('autostart-rollover.tenant-test');

    $tenant->run(function () {
        createAdminActor();

        FiscalYear::create([
            'name' => '2082/83',
            'start_date' => '2025-07-17',
            'end_date' => '2026-07-16',
            'status' => FiscalYearStatus::Open,
        ]);
    });

    $this->artisan('fiscal-year:auto-start')->assertSuccessful();

    $tenant->run(function () {
        $open = FiscalYear::query()->where('status', FiscalYearStatus::Open)->firstOrFail();
        $closed = FiscalYear::query()->where('name', '2082/83')->firstOrFail();

        expect($open->name)->toBe('2083/84')
            ->and($open->start_date->toDateString())->toBe('2026-07-17')
            ->and($open->end_date->toDateString())->toBe('2027-07-16')
            ->and($closed->status)->toBe(FiscalYearStatus::Closed);
    });

    $tenant->delete();
});

test('it takes no action when the open fiscal year already covers the current period', function () {
    freezeAutoStartFiscalYearToday();

    $tenant = provisionAutoStartTestTenant('autostart-current.tenant-test');

    $tenant->run(function () {
        createAdminActor();

        FiscalYear::create([
            'name' => '2083/84',
            'start_date' => '2026-07-17',
            'end_date' => '2027-07-16',
            'status' => FiscalYearStatus::Open,
        ]);
    });

    $this->artisan('fiscal-year:auto-start')->assertSuccessful();

    $tenant->run(function () {
        expect(FiscalYear::query()->count())->toBe(1);
        expect(FiscalYear::query()->where('status', FiscalYearStatus::Open)->firstOrFail()->name)->toBe('2083/84');
    });

    $tenant->delete();
});

test('it takes no action for a tenant that has never had a fiscal year', function () {
    freezeAutoStartFiscalYearToday();

    $tenant = provisionAutoStartTestTenant('autostart-none.tenant-test');

    $this->artisan('fiscal-year:auto-start')->assertSuccessful();

    $tenant->run(function () {
        expect(FiscalYear::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('it rolls over every active tenant independently in a single run', function () {
    freezeAutoStartFiscalYearToday();

    $stale = provisionAutoStartTestTenant('autostart-multi-stale.tenant-test');
    $stale->run(function () {
        createAdminActor();

        FiscalYear::create([
            'name' => '2082/83',
            'start_date' => '2025-07-17',
            'end_date' => '2026-07-16',
            'status' => FiscalYearStatus::Open,
        ]);
    });

    $current = provisionAutoStartTestTenant('autostart-multi-current.tenant-test');
    $current->run(function () {
        createAdminActor();

        FiscalYear::create([
            'name' => '2083/84',
            'start_date' => '2026-07-17',
            'end_date' => '2027-07-16',
            'status' => FiscalYearStatus::Open,
        ]);
    });

    $this->artisan('fiscal-year:auto-start')->assertSuccessful();

    $stale->run(function () {
        expect(FiscalYear::query()->where('status', FiscalYearStatus::Open)->firstOrFail()->name)->toBe('2083/84');
    });

    $current->run(function () {
        expect(FiscalYear::query()->count())->toBe(1);
    });

    $stale->delete();
    $current->delete();
});
