<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Eloquent\ModelNotFoundException;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionFiscalYearTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('a fiscal year cannot have its start date on or after its end date', function () {
    $tenant = provisionFiscalYearTestTenant('fy-bad-range.tenant-test');

    $tenant->run(function () {
        expect(fn () => FiscalYear::create([
            'name' => 'FY1',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-01',
            'status' => FiscalYearStatus::Closed,
        ]))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('overlapping fiscal year date ranges are rejected', function () {
    $tenant = provisionFiscalYearTestTenant('fy-overlap.tenant-test');

    $tenant->run(function () {
        FiscalYear::create([
            'name' => 'FY1',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Closed,
        ]);

        expect(fn () => FiscalYear::create([
            'name' => 'FY2',
            'start_date' => '2026-06-01',
            'end_date' => '2027-05-31',
            'status' => FiscalYearStatus::Closed,
        ]))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('only one fiscal year may be open at a time', function () {
    $tenant = provisionFiscalYearTestTenant('fy-one-open.tenant-test');

    $tenant->run(function () {
        FiscalYear::create([
            'name' => 'FY1',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open,
        ]);

        expect(fn () => FiscalYear::create([
            'name' => 'FY2',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => FiscalYearStatus::Open,
        ]))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('FiscalYear::current throws when no fiscal year is open', function () {
    $tenant = provisionFiscalYearTestTenant('fy-none-open.tenant-test');

    $tenant->run(function () {
        expect(fn () => FiscalYear::current())->toThrow(ModelNotFoundException::class);
    });

    $tenant->delete();
});

test('FiscalYear::current resolves the open fiscal year', function () {
    $tenant = provisionFiscalYearTestTenant('fy-current.tenant-test');

    $tenant->run(function () {
        FiscalYear::create([
            'name' => 'FY1',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Closed,
        ]);

        $open = FiscalYear::create([
            'name' => 'FY2',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => FiscalYearStatus::Open,
        ]);

        expect(FiscalYear::current()->id)->toBe($open->id);
    });

    $tenant->delete();
});
