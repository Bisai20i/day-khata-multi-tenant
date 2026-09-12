<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
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
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
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
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
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
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
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

test('re-importing replaces the previous batch instead of stacking on top of it', function () {
    // Importing the same file twice used to double every opening balance, with
    // no way to undo either import (audit P1).
    $domain = 'opening-balance-import-replace.tenant-test';
    $tenant = provisionOpeningBalanceImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    loginAsOpeningBalanceImportOwner($domain);

    $csv = "code,name,debit,credit\n"
        ."AS1,,5000.00,\n"
        ."LIA20,,,5000.00\n";

    $this->post("http://{$domain}/accounts/opening-balances/import", [
        'file' => UploadedFile::fake()->createWithContent('opening-balances.csv', $csv),
        'date' => '2026-01-01',
    ])->assertRedirect("http://{$domain}/accounts");

    $secondCsv = "code,name,debit,credit\n"
        ."AS1,,7000.00,\n"
        ."LIA20,,,7000.00\n";

    $this->post("http://{$domain}/accounts/opening-balances/import", [
        'file' => UploadedFile::fake()->createWithContent('opening-balances.csv', $secondCsv),
        'date' => '2026-01-01',
    ])->assertRedirect("http://{$domain}/accounts");

    $tenant->run(function () {
        $imports = JournalVoucher::query()
            ->where('voucher_type', VoucherType::OpeningBalance)
            ->orderBy('id')
            ->get();

        expect($imports)->toHaveCount(2)
            ->and($imports[0]->status)->toBe('cancelled')
            ->and($imports[1]->status)->toBe('posted');

        // The first batch was reversed in its own Reversal series, so the
        // account is left showing exactly the second batch's 7000.
        $reversal = JournalVoucher::query()->where('voucher_type', VoucherType::Reversal)->firstOrFail();
        expect($reversal->reversal_of_id)->toBe($imports[0]->id);

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $net = Money::sum(JournalVoucherLine::where('account_id', $cash->id)->pluck('debit'))
            ->minus(Money::sum(JournalVoucherLine::where('account_id', $cash->id)->pluck('credit')));

        expect($net->toString())->toBe('7000.00');
    });

    $tenant->delete();
});

test('an import can be cleared from the accounts page, and only an import can', function () {
    $domain = 'opening-balance-import-clear.tenant-test';
    $tenant = provisionOpeningBalanceImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    loginAsOpeningBalanceImportOwner($domain);

    $csv = "code,name,debit,credit\n"
        ."AS1,,5000.00,\n"
        ."LIA20,,,5000.00\n";

    $this->post("http://{$domain}/accounts/opening-balances/import", [
        'file' => UploadedFile::fake()->createWithContent('opening-balances.csv', $csv),
        'date' => '2026-01-01',
    ])->assertRedirect("http://{$domain}/accounts");

    $importId = null;
    $carryForwardId = null;
    $tenant->run(function () use (&$importId, &$carryForwardId) {
        $importId = JournalVoucher::query()->where('voucher_type', VoucherType::OpeningBalance)->value('id');

        // A year-end carry-forward voucher is the same voucher_type but is not
        // an import, so this screen must refuse to unwind it.
        $carryForwardId = JournalVoucher::post(
            [
                'voucher_type' => VoucherType::OpeningBalance->value,
                'date' => '2026-01-01',
                'narration' => 'Opening balances carried forward from FY0',
            ],
            [
                ['account_id' => Account::where('code', 'AS1')->value('id'), 'debit' => 10, 'credit' => 0],
                ['account_id' => Account::where('code', 'LIA20')->value('id'), 'debit' => 0, 'credit' => 10],
            ],
            User::first(),
        )->id;
    });

    $this->post("http://{$domain}/accounts/opening-balances/{$carryForwardId}/reverse")
        ->assertSessionHasErrors('opening_balance_import');

    $this->post("http://{$domain}/accounts/opening-balances/{$importId}/reverse")
        ->assertRedirect("http://{$domain}/accounts");

    $tenant->run(function () use ($importId, $carryForwardId) {
        expect(JournalVoucher::find($importId)->status)->toBe('cancelled')
            ->and(JournalVoucher::find($carryForwardId)->status)->toBe('posted');

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $net = Money::sum(JournalVoucherLine::where('account_id', $cash->id)->pluck('debit'))
            ->minus(Money::sum(JournalVoucherLine::where('account_id', $cash->id)->pluck('credit')));

        // 5000 imported, 5000 reversed, 10 from the carry-forward voucher.
        expect($net->toString())->toBe('10.00');
    });

    $tenant->delete();
});

test('the stock account, profit and loss accounts and over-precise amounts are all refused', function () {
    $domain = 'opening-balance-import-blocked-rows.tenant-test';
    $tenant = provisionOpeningBalanceImportTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    loginAsOpeningBalanceImportOwner($domain);

    $csv = "code,name,debit,credit\n"
        // Opening Stock: only the opening stock import may value this.
        ."AS11,,5000.00,\n"
        // Sales Account: a profit-and-loss account has no opening balance.
        ."INI20,,,5000.00\n"
        // Three decimals: refused rather than rounded into an unbalanced voucher.
        ."AS1,,333.333,\n"
        ."LIA20,,,333.333\n";

    $response = $this->post("http://{$domain}/accounts/opening-balances/import", [
        'file' => UploadedFile::fake()->createWithContent('opening-balances.csv', $csv),
        'date' => '2026-01-01',
    ]);

    $response->assertRedirect("http://{$domain}/accounts");
    $response->assertSessionHas('importResult', function (array $result) {
        return $result['imported'] === 0 && count($result['skipped']) === 4;
    });

    $tenant->run(function () {
        expect(JournalVoucher::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('a staff user gets a 403 on the opening balance import and clear routes', function () {
    $domain = 'opening-balance-import-admin-gate.tenant-test';
    $tenant = provisionOpeningBalanceImportTestTenant($domain);

    $importId = null;
    $tenant->run(function () use (&$importId) {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'staff')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

        $admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);

        $importId = JournalVoucher::post(
            [
                'voucher_type' => VoucherType::OpeningBalance->value,
                'date' => '2026-01-01',
                'narration' => 'Opening balance import',
            ],
            [
                ['account_id' => Account::where('code', 'AS1')->value('id'), 'debit' => 100, 'credit' => 0],
                ['account_id' => Account::where('code', 'LIA20')->value('id'), 'debit' => 0, 'credit' => 100],
            ],
            $admin,
        )->id;
    });

    loginAsOpeningBalanceImportOwner($domain);

    // The blank template stays downloadable; writing to the ledger does not.
    $this->get("http://{$domain}/accounts/opening-balances/template")->assertOk();

    $this->post("http://{$domain}/accounts/opening-balances/import", [
        'file' => UploadedFile::fake()->createWithContent('opening-balances.csv', "code,name,debit,credit\nAS1,,100,\nLIA20,,,100\n"),
        'date' => '2026-01-01',
    ])->assertForbidden();

    $this->post("http://{$domain}/accounts/opening-balances/{$importId}/reverse")->assertForbidden();

    $tenant->run(function () use ($importId) {
        expect(JournalVoucher::count())->toBe(1)
            ->and(JournalVoucher::find($importId)->status)->toBe('posted');
    });

    $tenant->delete();
});
