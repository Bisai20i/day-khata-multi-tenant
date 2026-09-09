<?php

use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

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

function provisionSupplierImportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsSupplierImportOwner(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('an authenticated user can bulk import suppliers from a csv file', function () {
    $domain = 'supplier-import-success.tenant-test';
    $tenant = provisionSupplierImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginAsSupplierImportOwner($domain);

    $csv = "name,address,mobile_no,email,tpin\n"
        ."ABC Traders,Kathmandu-10,9811111111,abc@example.com,111111111\n"
        ."Himal Suppliers,Pokhara,9822222222,,\n";
    $file = UploadedFile::fake()->createWithContent('suppliers.csv', $csv);

    $response = $this->post("http://{$domain}/suppliers/import", ['file' => $file]);

    $response->assertRedirect("http://{$domain}/suppliers");
    $response->assertSessionHas('importResult', function (array $result) {
        return $result['imported'] === 2 && $result['skipped'] === [];
    });

    $tenant->run(function () {
        expect(Supplier::query()->where('name', 'ABC Traders')->exists())->toBeTrue();
        expect(Supplier::query()->where('name', 'Himal Suppliers')->exists())->toBeTrue();

        $imported = Supplier::query()->where('name', 'ABC Traders')->firstOrFail();
        expect($imported->account_id)->not->toBeNull();
        expect($imported->account->name)->toBe('ABC Traders');
    });

    $tenant->delete();
});

test('bulk import skips invalid or duplicate supplier rows and reports why without importing them', function () {
    $domain = 'supplier-import-invalid.tenant-test';
    $tenant = provisionSupplierImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Supplier::factory()->create(['name' => 'Already Here', 'mobile_no' => '9800000005']);
    });

    loginAsSupplierImportOwner($domain);

    $csv = "name,address,mobile_no,email,tpin\n"
        // valid row.
        ."Good Supplier,Kathmandu,9833333333,good@example.com,\n"
        // missing required name.
        .",No Name Address,9844444444,,\n"
        // mobile number already used by an existing supplier.
        ."Duplicate Of Existing,Somewhere,9800000005,,\n"
        // mobile number repeated later in the same file.
        ."First Of Pair,Somewhere,9855555555,,\n"
        ."Second Of Pair,Somewhere Else,9855555555,,\n"
        // invalid email format.
        ."Bad Email,Somewhere,9866666666,not-an-email,\n";
    $file = UploadedFile::fake()->createWithContent('suppliers.csv', $csv);

    $response = $this->post("http://{$domain}/suppliers/import", ['file' => $file]);

    $response->assertRedirect("http://{$domain}/suppliers");
    $response->assertSessionHas('importResult', function (array $result) {
        return $result['imported'] === 2 && count($result['skipped']) === 4;
    });

    $tenant->run(function () {
        expect(Supplier::query()->where('name', 'Good Supplier')->exists())->toBeTrue();
        expect(Supplier::query()->where('name', 'First Of Pair')->exists())->toBeTrue();

        expect(Supplier::query()->where('name', 'No Name Address')->exists())->toBeFalse();
        expect(Supplier::query()->where('name', 'Duplicate Of Existing')->exists())->toBeFalse();
        expect(Supplier::query()->where('name', 'Second Of Pair')->exists())->toBeFalse();
        expect(Supplier::query()->where('name', 'Bad Email')->exists())->toBeFalse();

        // The pre-existing row and the two genuinely valid rows only - no
        // partial writes from the invalid ones leaked into the table.
        expect(Supplier::query()->count())->toBe(3);
    });

    $tenant->delete();
});

test('guests cannot reach the supplier import endpoints', function () {
    $domain = 'supplier-import-guest.tenant-test';
    $tenant = provisionSupplierImportTestTenant($domain);

    $file = UploadedFile::fake()->createWithContent('suppliers.csv', "name,address,mobile_no,email,tpin\nABC,Kathmandu,9811111111,,\n");

    $this->post("http://{$domain}/suppliers/import", ['file' => $file])
        ->assertRedirect("http://{$domain}/login");

    $this->get("http://{$domain}/suppliers/import/template")
        ->assertRedirect("http://{$domain}/login");

    $tenant->run(function () {
        expect(Supplier::query()->count())->toBe(0);
    });

    $tenant->delete();
});
