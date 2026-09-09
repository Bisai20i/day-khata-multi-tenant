<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
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

function provisionOpeningBalanceImportTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsOpeningBalanceImportOwner(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('an authenticated user can bulk import opening balances from a balanced csv file', function () {
    $domain = 'opening-balance-import-success.tenant-test';
    $tenant = provisionOpeningBalanceImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    loginAsOpeningBalanceImportOwner($domain);

    // AS1 (Cash In Hand) and LIA20 (Vat Payable) are seeded on every tenant
    // by ChartOfAccountsSeeder - same accounts JournalVoucherPostingTest uses.
    $csv = "code,name,debit,credit\n"
        ."AS1,,5000.00,\n"
        .",Vat Payable,,5000.00\n";
    $file = UploadedFile::fake()->createWithContent('opening-balances.csv', $csv);

    $response = $this->post("http://{$domain}/accounts/opening-balances/import", [
        'file' => $file,
        'date' => '2026-01-01',
    ]);

    $response->assertRedirect("http://{$domain}/accounts");
    $response->assertSessionHas('importResult', function (array $result) {
        return $result['imported'] === 2 && $result['skipped'] === [];
    });

    $tenant->run(function () {
        $voucher = JournalVoucher::query()->where('voucher_type', VoucherType::OpeningBalance)->firstOrFail();
        expect($voucher->lines()->count())->toBe(2);

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $vat = Account::where('code', 'LIA20')->firstOrFail();

        $cashLine = $voucher->lines()->where('account_id', $cash->id)->firstOrFail();
        $vatLine = $voucher->lines()->where('account_id', $vat->id)->firstOrFail();

        expect((float) $cashLine->debit)->toBe(5000.0)
            ->and((float) $cashLine->credit)->toBe(0.0)
            ->and((float) $vatLine->credit)->toBe(5000.0)
            ->and((float) $vatLine->debit)->toBe(0.0);
    });

    $tenant->delete();
});

test('an unbalanced or invalid csv imports nothing and reports every problem row', function () {
    $domain = 'opening-balance-import-invalid.tenant-test';
    $tenant = provisionOpeningBalanceImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    loginAsOpeningBalanceImportOwner($domain);

    $csv = "code,name,debit,credit\n"
        // valid.
        ."AS1,,5000.00,\n"
        // unknown code.
        ."NOPE,,1000.00,\n"
        // neither debit nor credit given.
        ."LIA20,,,\n"
        // both debit and credit given.
        ."ASA23,,100,100\n"
        // neither code nor name given.
        .",,,500\n";
    $file = UploadedFile::fake()->createWithContent('opening-balances.csv', $csv);

    $response = $this->post("http://{$domain}/accounts/opening-balances/import", [
        'file' => $file,
        'date' => '2026-01-01',
    ]);

    $response->assertRedirect("http://{$domain}/accounts");
    $response->assertSessionHas('importResult', function (array $result) {
        return $result['imported'] === 0 && count($result['skipped']) === 4;
    });

    $tenant->run(function () {
        expect(JournalVoucher::query()->where('voucher_type', VoucherType::OpeningBalance)->count())->toBe(0);
    });

    $tenant->delete();
});

test('a file whose totals do not balance is rejected without posting anything', function () {
    $domain = 'opening-balance-import-unbalanced.tenant-test';
    $tenant = provisionOpeningBalanceImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    loginAsOpeningBalanceImportOwner($domain);

    // Every row individually validates (exactly one of debit/credit is
    // set), but the file's totals don't match - JournalVoucher::post()
    // itself rejects this.
    $csv = "code,name,debit,credit\n"
        ."AS1,,5000.00,\n"
        ."LIA20,,,4000.00\n";
    $file = UploadedFile::fake()->createWithContent('opening-balances.csv', $csv);

    $response = $this->post("http://{$domain}/accounts/opening-balances/import", [
        'file' => $file,
        'date' => '2026-01-01',
    ]);

    $response->assertSessionHasErrors('file');

    $tenant->run(function () {
        expect(JournalVoucher::query()->where('voucher_type', VoucherType::OpeningBalance)->count())->toBe(0);
    });

    $tenant->delete();
});

test('guests cannot reach the opening balance import endpoints', function () {
    $domain = 'opening-balance-import-guest.tenant-test';
    $tenant = provisionOpeningBalanceImportTestTenant($domain);

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    $file = UploadedFile::fake()->createWithContent('opening-balances.csv', "code,name,debit,credit\nAS1,,100,\n,Vat Payable,,100\n");

    $this->post("http://{$domain}/accounts/opening-balances/import", ['file' => $file, 'date' => '2026-01-01'])
        ->assertRedirect("http://{$domain}/login");

    $this->get("http://{$domain}/accounts/opening-balances/template")
        ->assertRedirect("http://{$domain}/login");

    $tenant->run(function () {
        expect(JournalVoucher::query()->count())->toBe(0);
    });

    $tenant->delete();
});
