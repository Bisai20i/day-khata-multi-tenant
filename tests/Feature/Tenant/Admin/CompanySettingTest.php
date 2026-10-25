<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VoucherSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionCompanySettingTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsCompanySettingAdmin(string $domain): User
{
    $admin = null;

    tenancy()->initialize(Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $domain))->firstOrFail());

    $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
    $admin = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => 'password',
        'role_id' => $adminRole->id,
    ]);

    tenancy()->end();

    test()->post("http://{$domain}/login", [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    return $admin;
}

function loginAsCompanySettingCashier(string $domain): User
{
    $cashier = null;

    tenancy()->initialize(Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $domain))->firstOrFail());

    $cashierRole = Role::query()->where('slug', 'cashier')->firstOrFail();
    $cashier = User::factory()->create([
        'email' => 'cashier@example.com',
        'password' => 'password',
        'role_id' => $cashierRole->id,
    ]);

    tenancy()->end();

    test()->post("http://{$domain}/login", [
        'email' => 'cashier@example.com',
        'password' => 'password',
    ]);

    return $cashier;
}

function baseTenantSettingsPayload(): array
{
    return [
        'company_name' => 'Acme Corporation Pvt. Ltd.',
        'address' => 'Kathmandu, Nepal',
        'phone' => '9800000000',
        'email' => 'info@acme.test',
        'pan_vat_number' => '123456789',
        'invoice_footer_note' => 'Thank you for shopping with us!',
        'print_paper_size' => 'a4',
        'default_vat_rate' => '13.00',
        'allow_negative_stock' => false,
        'default_store_id' => null,
        'sale_full_prefix' => 'SL',
        'sale_abbreviated_prefix' => 'SLA',
        'sale_pan_prefix' => 'SLP',
        'active_invoice_type' => 'full',
        'purchase_prefix' => 'PU',
        'sale_return_prefix' => 'SR',
        'purchase_return_prefix' => 'PR',
    ];
}

test('a tenant user with settings.manage can view /settings', function () {
    $domain = 'settings-view.tenant-test';
    $tenant = provisionCompanySettingTenant($domain);
    loginAsCompanySettingAdmin($domain);

    $response = test()->get("http://{$domain}/settings");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Tenant/Admin/Settings/Edit')
        ->has('settings')
        ->has('stores')
        ->has('invoiceNumbering')
        ->where('settings.company_name', 'My Company')
    );
});

test('a tenant user can update company settings', function () {
    $domain = 'settings-update.tenant-test';
    $tenant = provisionCompanySettingTenant($domain);
    loginAsCompanySettingAdmin($domain);

    $payload = baseTenantSettingsPayload();

    $response = test()->put("http://{$domain}/settings", $payload);

    $response->assertRedirect("http://{$domain}/settings");
    $response->assertSessionHas('status', 'Company settings updated.');

    $tenant->run(function () {
        $settings = CompanySetting::current();
        expect($settings->company_name)->toBe('Acme Corporation Pvt. Ltd.');
        expect($settings->address)->toBe('Kathmandu, Nepal');
        expect($settings->phone)->toBe('9800000000');
        expect($settings->email)->toBe('info@acme.test');
        expect($settings->pan_vat_number)->toBe('123456789');
        expect($settings->invoice_footer_note)->toBe('Thank you for shopping with us!');
    });
});

test('a tenant user can upload and delete company logo', function () {
    Storage::fake('public');

    $domain = 'settings-logo.tenant-test';
    $tenant = provisionCompanySettingTenant($domain);
    loginAsCompanySettingAdmin($domain);

    // Upload
    $response = test()->post("http://{$domain}/settings/logo", [
        'logo' => UploadedFile::fake()->image('logo.jpg'),
    ]);

    $response->assertRedirect("http://{$domain}/settings");
    $response->assertSessionHas('status', 'Company logo updated.');

    $logoPath = null;
    $tenant->run(function () use (&$logoPath, $tenant) {
        $settings = CompanySetting::current();
        expect($settings->logo_path)->not->toBeNull();
        expect($settings->logo_path)->toStartWith('tenant-logos/'.$tenant->id.'/');
        $logoPath = $settings->logo_path;
        Storage::disk('public')->assertExists($logoPath);
    });

    // Delete
    $deleteResponse = test()->delete("http://{$domain}/settings/logo");

    $deleteResponse->assertRedirect("http://{$domain}/settings");
    $deleteResponse->assertSessionHas('status', 'Company logo removed.');

    $tenant->run(function () use ($logoPath) {
        $settings = CompanySetting::current();
        expect($settings->logo_path)->toBeNull();
        Storage::disk('public')->assertMissing($logoPath);
    });
});

test('a tenant user without settings.manage receives 403 Forbidden', function () {
    $domain = 'settings-forbidden.tenant-test';
    $tenant = provisionCompanySettingTenant($domain);
    loginAsCompanySettingCashier($domain);

    test()->get("http://{$domain}/settings")->assertForbidden();
    test()->put("http://{$domain}/settings", baseTenantSettingsPayload())->assertForbidden();
    test()->post("http://{$domain}/settings/logo", [
        'logo' => UploadedFile::fake()->image('logo.jpg'),
    ])->assertForbidden();
    test()->delete("http://{$domain}/settings/logo")->assertForbidden();
    test()->post("http://{$domain}/settings/starting-number", [
        'voucher_type' => 'sale',
        'next_number' => 10,
    ])->assertForbidden();
});

test('two document series cannot share a prefix', function () {
    $domain = 'settings-prefix.tenant-test';
    $tenant = provisionCompanySettingTenant($domain);
    loginAsCompanySettingAdmin($domain);

    $payload = array_merge(baseTenantSettingsPayload(), ['sale_pan_prefix' => 'SL']);

    test()->put("http://{$domain}/settings", $payload)
        ->assertSessionHasErrors('sale_pan_prefix');
});

test('a tenant user can set starting invoice number', function () {
    $domain = 'settings-starting-num.tenant-test';
    $tenant = provisionCompanySettingTenant($domain);
    loginAsCompanySettingAdmin($domain);

    $tenant->run(function () {
        FiscalYear::create([
            'name' => '2081/82',
            'start_date' => '2024-07-16',
            'end_date' => '2025-07-15',
            'status' => FiscalYearStatus::Open,
        ]);
    });

    $response = test()->post("http://{$domain}/settings/starting-number", [
        'voucher_type' => 'sale',
        'next_number' => 500,
    ]);

    $response->assertRedirect("http://{$domain}/settings");
    $response->assertSessionHas('status', 'Starting number updated.');

    $tenant->run(function () {
        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();
        expect(VoucherSequence::nextNumberFor($fiscalYear, VoucherType::Sale))->toBe(500);
    });
});
