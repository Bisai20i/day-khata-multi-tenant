<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * See FiscalYearArchiveTest.php's identical afterEach() docblock - tenancy
 * has no automatic "end of request" hook outside a real process boundary.
 */
afterEach(function () {
    tenancy()->end();
});

function provisionFiscalYearStoreTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    tenancy()->initialize($tenant);
    User::factory()->create([
        'email' => 'boss@example.com',
        'password' => 'password',
        'role_id' => Role::query()->where('slug', 'admin')->value('id'),
    ]);
    tenancy()->end();

    test()->post("http://{$domain}/login", [
        'email' => 'boss@example.com',
        'password' => 'password',
    ]);

    return $tenant;
}

test('a new fiscal year always runs from Shrawan 1 to the end of Ashad', function () {
    $domain = 'fy-store-shrawan.tenant-test';
    $tenant = provisionFiscalYearStoreTestTenant($domain);

    test()->post("http://{$domain}/fiscal-years", ['bs_year' => 2082])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('tenant.fiscal-years.index'));

    $tenant->run(function () {
        $fiscalYear = FiscalYear::query()->sole();

        expect($fiscalYear->name)->toBe('2082/83')
            ->and($fiscalYear->start_date->toDateString())->toBe('2025-07-17')
            ->and($fiscalYear->end_date->toDateString())->toBe('2026-07-16')
            ->and($fiscalYear->status)->toBe(FiscalYearStatus::Open);
    });

    $tenant->delete();
});

test('arbitrary start and end dates sent with the request are ignored', function () {
    $domain = 'fy-store-ignore-dates.tenant-test';
    $tenant = provisionFiscalYearStoreTestTenant($domain);

    test()->post("http://{$domain}/fiscal-years", [
        'bs_year' => 2082,
        'name' => 'FY 2082/83',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ])->assertSessionHasNoErrors();

    $tenant->run(function () {
        $fiscalYear = FiscalYear::query()->sole();

        expect($fiscalYear->name)->toBe('FY 2082/83')
            ->and($fiscalYear->start_date->toDateString())->toBe('2025-07-17')
            ->and($fiscalYear->end_date->toDateString())->toBe('2026-07-16');
    });

    $tenant->delete();
});

test('a fiscal year cannot be created without a valid BS start year', function (mixed $bsYear) {
    $domain = 'fy-store-invalid.tenant-test';
    $tenant = provisionFiscalYearStoreTestTenant($domain);

    test()->post("http://{$domain}/fiscal-years", [
        'bs_year' => $bsYear,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ])->assertSessionHasErrors('bs_year');

    $tenant->run(fn () => expect(FiscalYear::query()->count())->toBe(0));

    $tenant->delete();
})->with([null, 1999, 2090, 'abc']);

test('creating the same fiscal year twice is rejected as an overlap', function () {
    $domain = 'fy-store-duplicate.tenant-test';
    $tenant = provisionFiscalYearStoreTestTenant($domain);

    test()->post("http://{$domain}/fiscal-years", ['bs_year' => 2082])->assertSessionHasNoErrors();
    test()->post("http://{$domain}/fiscal-years", ['bs_year' => 2082])->assertSessionHasErrors('bs_year');

    $tenant->run(fn () => expect(FiscalYear::query()->count())->toBe(1));

    $tenant->delete();
});
