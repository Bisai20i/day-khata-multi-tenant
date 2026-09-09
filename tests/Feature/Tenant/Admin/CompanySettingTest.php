<?php

use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

function provisionCompanySettingTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsSettingsAdmin(string $domain): User
{
    $admin = null;

    tenancy()->initialize(Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $domain))->firstOrFail());

    $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
    $admin = User::factory()->create([
        'email' => 'boss@example.com',
        'password' => 'password',
        'role_id' => $adminRole->id,
    ]);

    tenancy()->end();

    test()->post("http://{$domain}/login", [
        'email' => 'boss@example.com',
        'password' => 'password',
    ]);

    return $admin;
}

test('an admin can view the settings edit page', function () {
    $domain = 'company-settings-edit.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);
    loginAsSettingsAdmin($domain);

    $response = test()->get("http://{$domain}/settings");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Tenant/Admin/Settings/Edit')
        ->has('settings')
        ->where('settings.company_name', 'My Company')
    );

    $tenant->delete();
});

test('a non-admin is forbidden from the settings edit page', function () {
    $domain = 'company-settings-staff.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);

    $tenant->run(function () {
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
        User::factory()->create(['email' => 'staffer@example.com', 'role_id' => $staffRole->id]);
    });

    test()->post("http://{$domain}/login", ['email' => 'staffer@example.com', 'password' => 'password']);

    test()->get("http://{$domain}/settings")->assertForbidden();
    test()->put("http://{$domain}/settings", ['company_name' => 'x'])->assertForbidden();

    $tenant->delete();
});

/**
 * @return array<string, mixed>
 */
function baseSettingsUpdatePayload(): array
{
    return [
        'company_name' => 'Trinovate Tech',
        'address' => 'Kathmandu, Nepal',
        'phone' => '01-4123456',
        'email' => 'billing@trinovatetech.com',
        'pan_vat_number' => '123456789',
        'invoice_footer_note' => 'Thank you for your business!',
        'print_paper_size' => 'a4',
        'default_vat_rate' => '13.00',
        'allow_negative_stock' => false,
        'default_store_id' => null,
        'sale_full_prefix' => 'SL',
        'sale_full_enabled' => true,
        'sale_abbreviated_prefix' => 'SLA',
        'sale_abbreviated_enabled' => true,
        'sale_pan_prefix' => 'SLP',
        'sale_pan_enabled' => true,
        'purchase_prefix' => 'PU',
    ];
}

test('an admin can update the company settings', function () {
    $domain = 'company-settings-update.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);
    loginAsSettingsAdmin($domain);

    $response = test()->put("http://{$domain}/settings", baseSettingsUpdatePayload());

    $response->assertRedirect();

    $tenant->run(function () {
        $settings = CompanySetting::current();

        expect($settings->company_name)->toBe('Trinovate Tech');
        expect($settings->address)->toBe('Kathmandu, Nepal');
        expect($settings->phone)->toBe('01-4123456');
        expect($settings->email)->toBe('billing@trinovatetech.com');
        expect($settings->pan_vat_number)->toBe('123456789');
        expect($settings->invoice_footer_note)->toBe('Thank you for your business!');
        expect(CompanySetting::count())->toBe(1);
    });

    $tenant->delete();
});

test('updating settings requires a company name', function () {
    $domain = 'company-settings-validation.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);
    loginAsSettingsAdmin($domain);

    $response = test()->put("http://{$domain}/settings", ['company_name' => '']);

    $response->assertSessionHasErrors('company_name');

    $tenant->delete();
});

test('an admin can update the new invoicing and stock policy fields', function () {
    $domain = 'company-settings-invoicing-update.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);
    loginAsSettingsAdmin($domain);

    $storeId = null;
    $tenant->run(function () use (&$storeId) {
        $storeId = Store::factory()->create(['name' => 'Main Warehouse'])->id;
    });

    $payload = array_merge(baseSettingsUpdatePayload(), [
        'default_vat_rate' => '7.50',
        'allow_negative_stock' => true,
        'default_store_id' => $storeId,
        'sale_full_prefix' => 'INV',
        'sale_full_enabled' => false,
        'sale_abbreviated_prefix' => 'ABR',
        'sale_pan_prefix' => 'PAN',
        'purchase_prefix' => 'PRC',
    ]);

    $response = test()->put("http://{$domain}/settings", $payload);

    $response->assertRedirect();

    $tenant->run(function () use ($storeId) {
        $settings = CompanySetting::current();

        expect((float) $settings->default_vat_rate)->toBe(7.50);
        expect($settings->allow_negative_stock)->toBeTrue();
        expect($settings->default_store_id)->toBe($storeId);
        expect($settings->sale_full_prefix)->toBe('INV');
        expect($settings->sale_full_enabled)->toBeFalse();
        expect($settings->sale_abbreviated_prefix)->toBe('ABR');
        expect($settings->sale_pan_prefix)->toBe('PAN');
        expect($settings->purchase_prefix)->toBe('PRC');
    });

    $tenant->delete();
});

test('an out-of-range VAT rate is rejected', function () {
    $domain = 'company-settings-vat-validation.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);
    loginAsSettingsAdmin($domain);

    $payload = array_merge(baseSettingsUpdatePayload(), ['default_vat_rate' => '150']);

    $response = test()->put("http://{$domain}/settings", $payload);

    $response->assertSessionHasErrors('default_vat_rate');

    $tenant->delete();
});

test('a non-existent default store id is rejected', function () {
    $domain = 'company-settings-store-validation.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);
    loginAsSettingsAdmin($domain);

    $payload = array_merge(baseSettingsUpdatePayload(), ['default_store_id' => 999999]);

    $response = test()->put("http://{$domain}/settings", $payload);

    $response->assertSessionHasErrors('default_store_id');

    $tenant->delete();
});

test('an admin can upload a company logo', function () {
    Storage::fake('public');

    $domain = 'company-settings-logo-upload.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);
    loginAsSettingsAdmin($domain);

    $response = test()->post("http://{$domain}/settings/logo", [
        'logo' => UploadedFile::fake()->image('logo.jpg'),
    ]);

    $response->assertRedirect();

    $tenant->run(function () use ($tenant) {
        $settings = CompanySetting::current();

        expect($settings->logo_path)->not->toBeNull();
        expect($settings->logo_path)->toStartWith('tenant-logos/'.$tenant->id.'/');

        Storage::disk('public')->assertExists($settings->logo_path);
    });

    $tenant->delete();
});

test('re-uploading a company logo deletes the old file', function () {
    Storage::fake('public');

    $domain = 'company-settings-logo-replace.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);
    loginAsSettingsAdmin($domain);

    test()->post("http://{$domain}/settings/logo", [
        'logo' => UploadedFile::fake()->image('first.jpg'),
    ]);

    $originalPath = null;
    $tenant->run(function () use (&$originalPath) {
        $originalPath = CompanySetting::current()->logo_path;
    });

    $response = test()->post("http://{$domain}/settings/logo", [
        'logo' => UploadedFile::fake()->image('second.jpg'),
    ]);

    $response->assertRedirect();

    $tenant->run(function () use ($originalPath) {
        $settings = CompanySetting::current();

        expect($settings->logo_path)->not->toBeNull();
        expect($settings->logo_path)->not->toBe($originalPath);

        Storage::disk('public')->assertExists($settings->logo_path);
        Storage::disk('public')->assertMissing($originalPath);
    });

    $tenant->delete();
});

test('a non-image file is rejected for the logo upload', function () {
    Storage::fake('public');

    $domain = 'company-settings-logo-rejects-non-image.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);
    loginAsSettingsAdmin($domain);

    $response = test()->post("http://{$domain}/settings/logo", [
        'logo' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
    ]);

    $response->assertSessionHasErrors('logo');

    $tenant->delete();
});

test('an oversized logo is rejected', function () {
    Storage::fake('public');

    $domain = 'company-settings-logo-rejects-oversized.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);
    loginAsSettingsAdmin($domain);

    $response = test()->post("http://{$domain}/settings/logo", [
        'logo' => UploadedFile::fake()->image('big.jpg')->size(3000),
    ]);

    $response->assertSessionHasErrors('logo');

    $tenant->delete();
});

test('a non-admin is forbidden from uploading a company logo', function () {
    Storage::fake('public');

    $domain = 'company-settings-logo-staff.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);

    $tenant->run(function () {
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
        User::factory()->create(['email' => 'staffer@example.com', 'role_id' => $staffRole->id]);
    });

    test()->post("http://{$domain}/login", ['email' => 'staffer@example.com', 'password' => 'password']);

    $response = test()->post("http://{$domain}/settings/logo", [
        'logo' => UploadedFile::fake()->image('logo.jpg'),
    ]);

    $response->assertForbidden();

    $tenant->delete();
});

test('CompanySetting::current always returns exactly one row, even before any row exists', function () {
    $domain = 'company-settings-singleton.tenant-test';
    $tenant = provisionCompanySettingTestTenant($domain);

    $tenant->run(function () {
        expect(CompanySetting::count())->toBe(0);

        $first = CompanySetting::current();

        expect(CompanySetting::count())->toBe(1);
        expect($first->company_name)->toBe('My Company');

        $second = CompanySetting::current();

        expect(CompanySetting::count())->toBe(1);
        expect($second->id)->toBe($first->id);
    });

    $tenant->delete();
});
