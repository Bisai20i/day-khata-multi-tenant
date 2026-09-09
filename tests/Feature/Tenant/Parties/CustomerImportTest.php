<?php

use App\Models\Customer;
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

function provisionCustomerImportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsCustomerImportOwner(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('an authenticated user can bulk import customers from a csv file', function () {
    $domain = 'customer-import-success.tenant-test';
    $tenant = provisionCustomerImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Customer::factory()->create(['name' => 'Existing Customer', 'mobile_no' => '9800000099']);
    });

    loginAsCustomerImportOwner($domain);

    $csv = "name,address,mobile_no,email,tpin,citizenship\n"
        ."Ram Sharma,Kathmandu-10,9811111111,ram@example.com,111111111,12-34-56-78901\n"
        ."Sita Gurung,Pokhara,9822222222,sita@example.com,,\n";
    $file = UploadedFile::fake()->createWithContent('customers.csv', $csv);

    $response = $this->post("http://{$domain}/customers/import", ['file' => $file]);

    $response->assertRedirect("http://{$domain}/customers");
    $response->assertSessionHas('importResult', function (array $result) {
        return $result['imported'] === 2 && $result['skipped'] === [];
    });

    $tenant->run(function () {
        expect(Customer::query()->where('name', 'Ram Sharma')->exists())->toBeTrue();
        expect(Customer::query()->where('name', 'Sita Gurung')->exists())->toBeTrue();

        $imported = Customer::query()->where('name', 'Ram Sharma')->firstOrFail();
        expect($imported->account_id)->not->toBeNull();
        expect($imported->account->name)->toBe('Ram Sharma');
    });

    $tenant->delete();
});

test('bulk import skips invalid or duplicate rows and reports why without importing them', function () {
    $domain = 'customer-import-invalid.tenant-test';
    $tenant = provisionCustomerImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Customer::factory()->create(['name' => 'Already Here', 'mobile_no' => '9800000005']);
    });

    loginAsCustomerImportOwner($domain);

    $csv = "name,address,mobile_no,email,tpin,citizenship\n"
        // valid row.
        ."Good Row,Kathmandu,9833333333,good@example.com,,\n"
        // missing required name.
        .",No Name Address,9844444444,,,\n"
        // mobile number already used by an existing customer.
        ."Duplicate Of Existing,Somewhere,9800000005,,,\n"
        // mobile number repeated later in the same file.
        ."First Of Pair,Somewhere,9855555555,,,\n"
        ."Second Of Pair,Somewhere Else,9855555555,,,\n"
        // invalid email format.
        ."Bad Email,Somewhere,9866666666,not-an-email,,\n";
    $file = UploadedFile::fake()->createWithContent('customers.csv', $csv);

    $response = $this->post("http://{$domain}/customers/import", ['file' => $file]);

    $response->assertRedirect("http://{$domain}/customers");
    $response->assertSessionHas('importResult', function (array $result) {
        return $result['imported'] === 2 && count($result['skipped']) === 4;
    });

    $tenant->run(function () {
        expect(Customer::query()->where('name', 'Good Row')->exists())->toBeTrue();
        expect(Customer::query()->where('name', 'First Of Pair')->exists())->toBeTrue();

        expect(Customer::query()->where('name', 'No Name Address')->exists())->toBeFalse();
        expect(Customer::query()->where('name', 'Duplicate Of Existing')->exists())->toBeFalse();
        expect(Customer::query()->where('name', 'Second Of Pair')->exists())->toBeFalse();
        expect(Customer::query()->where('name', 'Bad Email')->exists())->toBeFalse();

        // The pre-existing row and the two genuinely valid rows only - no
        // partial writes from the invalid ones leaked into the table.
        expect(Customer::query()->count())->toBe(3);
    });

    $tenant->delete();
});

test('guests cannot reach the customer import endpoints', function () {
    $domain = 'customer-import-guest.tenant-test';
    $tenant = provisionCustomerImportTestTenant($domain);

    $file = UploadedFile::fake()->createWithContent('customers.csv', "name,address,mobile_no,email,tpin,citizenship\nRam,Kathmandu,9811111111,,,\n");

    $this->post("http://{$domain}/customers/import", ['file' => $file])
        ->assertRedirect("http://{$domain}/login");

    $this->get("http://{$domain}/customers/import/template")
        ->assertRedirect("http://{$domain}/login");

    $tenant->run(function () {
        expect(Customer::query()->count())->toBe(0);
    });

    $tenant->delete();
});
