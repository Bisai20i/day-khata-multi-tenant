<?php

use App\Models\CompanySetting;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

test('the settings row created on first use carries the database defaults', function () {
    $tenant = Tenant::create(['company_name' => 'Acme Co']);

    $tenant->run(function () {
        CompanySetting::query()->delete();

        $settings = CompanySetting::current();

        expect($settings->default_vat_rate)->not->toBeNull()
            ->and((string) $settings->default_vat_rate)->toBe('13.00')
            ->and($settings->company_name)->toBe('My Company');
    });

    $tenant->delete();
});

test('an existing settings row is returned as stored', function () {
    $tenant = Tenant::create(['company_name' => 'Acme Co']);

    $tenant->run(function () {
        CompanySetting::current()->update(['default_vat_rate' => '10.00']);

        expect((string) CompanySetting::current()->default_vat_rate)->toBe('10.00');
    });

    $tenant->delete();
});
