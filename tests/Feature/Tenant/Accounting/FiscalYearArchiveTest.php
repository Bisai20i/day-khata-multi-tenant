<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\FiscalYearArchive;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FiscalYear\FiscalYearArchiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

function provisionArchiveTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsArchiveAdmin(string $domain): User
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

/**
 * Posts three balanced journal vouchers into $fiscalYear (which must be the
 * currently open year) and returns the actor who posted them, for reuse
 * across tests.
 */
function postArchiveTestVouchers(User $actor): void
{
    $cash = Account::where('code', 'AS1')->firstOrFail();
    $sales = Account::where('code', 'INI20')->firstOrFail();
    $purchases = Account::where('code', 'EXE8')->firstOrFail();

    // Cash sale of 1000.
    JournalVoucher::post(
        ['date' => '2026-03-01', 'narration' => 'Cash sale 1'],
        [
            ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => $sales->id, 'debit' => 0, 'credit' => 1000],
        ],
        $actor,
    );

    // Cash purchase/expense of 400.
    JournalVoucher::post(
        ['date' => '2026-04-01', 'narration' => 'Cash purchase'],
        [
            ['account_id' => $purchases->id, 'debit' => 400, 'credit' => 0],
            ['account_id' => $cash->id, 'debit' => 0, 'credit' => 400],
        ],
        $actor,
    );

    // A second cash sale of 500.
    JournalVoucher::post(
        ['date' => '2026-05-01', 'narration' => 'Cash sale 2'],
        [
            ['account_id' => $cash->id, 'debit' => 500, 'credit' => 0],
            ['account_id' => $sales->id, 'debit' => 0, 'credit' => 500],
        ],
        $actor,
    );
}

test('an admin can archive a closed fiscal year, producing a row with correct counts', function () {
    $domain = 'fy-archive-create.tenant-test';
    $tenant = provisionArchiveTestTenant($domain);
    $admin = loginAsArchiveAdmin($domain);

    $fiscalYearId = null;

    $tenant->run(function () use ($admin, &$fiscalYearId) {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fiscalYearId = $fy1->id;

        postArchiveTestVouchers($admin);

        $fy1->close($fy2, $admin);

        // 3 manually-posted vouchers + 1 system-posted ClosingEntry
        // voucher all land inside fy1; the OpeningBalance voucher close()
        // also posts lands inside fy2, not fy1.
        expect(JournalVoucher::where('fiscal_year_id', $fy1->id)->count())->toBe(4)
            ->and(JournalVoucher::where('fiscal_year_id', $fy1->id)->where('voucher_type', VoucherType::ClosingEntry)->exists())->toBeTrue();
    });

    $response = test()->post("http://{$domain}/fiscal-years/{$fiscalYearId}/archive");
    $response->assertRedirect(route('tenant.fiscal-years.index'));

    $tenant->run(function () use ($fiscalYearId, $admin) {
        $archive = FiscalYearArchive::where('fiscal_year_id', $fiscalYearId)->first();

        expect($archive)->not->toBeNull()
            ->and($archive->fiscal_year_id)->toBe($fiscalYearId)
            ->and($archive->archived_by)->toBe($admin->id)
            ->and($archive->voucher_count)->toBe(4)
            ->and($archive->line_count)->toBe(
                JournalVoucherLine::whereHas('journalVoucher', fn ($q) => $q->where('fiscal_year_id', $fiscalYearId))->count()
            );
    });

    $tenant->delete();
});

test('archiving round-trips the ledger exactly: aggregate sums and a per-line spot check both match the live data', function () {
    $domain = 'fy-archive-roundtrip.tenant-test';
    $tenant = provisionArchiveTestTenant($domain);
    $admin = loginAsArchiveAdmin($domain);

    $fiscalYearId = null;

    $tenant->run(function () use ($admin, &$fiscalYearId) {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fiscalYearId = $fy1->id;

        postArchiveTestVouchers($admin);
        $fy1->close($fy2, $admin);
    });

    test()->post("http://{$domain}/fiscal-years/{$fiscalYearId}/archive");

    $tenant->run(function () use ($fiscalYearId) {
        $archive = FiscalYearArchive::where('fiscal_year_id', $fiscalYearId)->firstOrFail();
        $connectionName = FiscalYearArchiver::connectionFor($archive);

        // Independent sources: the live table (scoped to this fiscal
        // year's vouchers) vs. the standalone archive SQLite connection.
        $liveDebit = (float) JournalVoucherLine::whereHas('journalVoucher', fn ($q) => $q->where('fiscal_year_id', $fiscalYearId))->sum('debit');
        $liveCredit = (float) JournalVoucherLine::whereHas('journalVoucher', fn ($q) => $q->where('fiscal_year_id', $fiscalYearId))->sum('credit');

        $archivedDebit = (float) DB::connection($connectionName)->table('journal_voucher_lines')->sum('debit');
        $archivedCredit = (float) DB::connection($connectionName)->table('journal_voucher_lines')->sum('credit');

        expect($archivedDebit)->toBe($liveDebit)
            ->and($archivedCredit)->toBe($liveCredit)
            // The ledger must still balance after the copy.
            ->and($archivedDebit)->toBe($archivedCredit);

        // Per-line spot check on one specific voucher: every debit/credit/
        // account_code value must match exactly between the live and
        // archived copies.
        $voucher = JournalVoucher::where('fiscal_year_id', $fiscalYearId)
            ->where('narration', 'Cash sale 1')
            ->firstOrFail();

        $liveLines = $voucher->lines()->with('account')->orderBy('id')->get()
            ->map(fn (JournalVoucherLine $line) => [
                'account_code' => $line->account->code,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
            ])->all();

        $archivedLines = DB::connection($connectionName)->table('journal_voucher_lines')
            ->where('journal_voucher_id', $voucher->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($line) => [
                'account_code' => $line->account_code,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
            ])->all();

        expect($archivedLines)->toBe($liveLines)
            ->and($archivedLines)->toHaveCount(2);
    });

    $tenant->delete();
});

test('an open fiscal year cannot be archived', function () {
    $domain = 'fy-archive-open-rejected.tenant-test';
    $tenant = provisionArchiveTestTenant($domain);
    loginAsArchiveAdmin($domain);

    $fiscalYearId = null;

    $tenant->run(function () use (&$fiscalYearId) {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fiscalYearId = $fy1->id;
    });

    $response = test()->post("http://{$domain}/fiscal-years/{$fiscalYearId}/archive");
    $response->assertSessionHasErrors('fiscal_year');

    $tenant->run(function () use ($fiscalYearId) {
        expect(FiscalYearArchive::where('fiscal_year_id', $fiscalYearId)->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('a fiscal year cannot be archived twice', function () {
    $domain = 'fy-archive-twice.tenant-test';
    $tenant = provisionArchiveTestTenant($domain);
    $admin = loginAsArchiveAdmin($domain);

    $fiscalYearId = null;

    $tenant->run(function () use ($admin, &$fiscalYearId) {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fiscalYearId = $fy1->id;

        postArchiveTestVouchers($admin);
        $fy1->close($fy2, $admin);
    });

    $first = test()->post("http://{$domain}/fiscal-years/{$fiscalYearId}/archive");
    $first->assertRedirect();

    $firstFilePath = null;
    $tenant->run(function () use ($fiscalYearId, &$firstFilePath) {
        expect(FiscalYearArchive::where('fiscal_year_id', $fiscalYearId)->count())->toBe(1);
        $firstFilePath = FiscalYearArchive::where('fiscal_year_id', $fiscalYearId)->firstOrFail()->file_path;
    });

    $second = test()->post("http://{$domain}/fiscal-years/{$fiscalYearId}/archive");
    $second->assertSessionHasErrors('fiscal_year');

    $tenant->run(function () use ($fiscalYearId, $firstFilePath) {
        expect(FiscalYearArchive::where('fiscal_year_id', $fiscalYearId)->count())->toBe(1)
            ->and(FiscalYearArchive::where('fiscal_year_id', $fiscalYearId)->firstOrFail()->file_path)->toBe($firstFilePath);
    });

    $tenant->delete();
});

test('a non-admin cannot archive a fiscal year', function () {
    $domain = 'fy-archive-staff.tenant-test';
    $tenant = provisionArchiveTestTenant($domain);

    $fiscalYearId = null;

    $tenant->run(function () use (&$fiscalYearId) {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);
        $fiscalYearId = $fy1->id;

        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();
        User::factory()->create([
            'email' => 'staffer@example.com',
            'password' => 'password',
            'role_id' => $staffRole->id,
        ]);
    });

    test()->post("http://{$domain}/login", [
        'email' => 'staffer@example.com',
        'password' => 'password',
    ]);

    $response = test()->post("http://{$domain}/fiscal-years/{$fiscalYearId}/archive");
    $response->assertForbidden();

    $tenant->run(function () use ($fiscalYearId) {
        expect(FiscalYearArchive::where('fiscal_year_id', $fiscalYearId)->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('archiving never deletes or alters the live journal voucher data', function () {
    $domain = 'fy-archive-live-untouched.tenant-test';
    $tenant = provisionArchiveTestTenant($domain);
    $admin = loginAsArchiveAdmin($domain);

    $fiscalYearId = null;
    $liveVoucherCountBefore = null;
    $liveLineCountBefore = null;
    $liveDebitBefore = null;
    $liveCreditBefore = null;

    $tenant->run(function () use ($admin, &$fiscalYearId, &$liveVoucherCountBefore, &$liveLineCountBefore, &$liveDebitBefore, &$liveCreditBefore) {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $fiscalYearId = $fy1->id;

        postArchiveTestVouchers($admin);
        $fy1->close($fy2, $admin);

        $liveVoucherCountBefore = JournalVoucher::where('fiscal_year_id', $fy1->id)->count();
        $liveLineCountBefore = JournalVoucherLine::whereHas('journalVoucher', fn ($q) => $q->where('fiscal_year_id', $fy1->id))->count();
        $liveDebitBefore = (float) JournalVoucherLine::whereHas('journalVoucher', fn ($q) => $q->where('fiscal_year_id', $fy1->id))->sum('debit');
        $liveCreditBefore = (float) JournalVoucherLine::whereHas('journalVoucher', fn ($q) => $q->where('fiscal_year_id', $fy1->id))->sum('credit');
    });

    test()->post("http://{$domain}/fiscal-years/{$fiscalYearId}/archive");

    $tenant->run(function () use ($fiscalYearId, $liveVoucherCountBefore, $liveLineCountBefore, $liveDebitBefore, $liveCreditBefore) {
        expect(JournalVoucher::where('fiscal_year_id', $fiscalYearId)->count())->toBe($liveVoucherCountBefore)
            ->and(JournalVoucherLine::whereHas('journalVoucher', fn ($q) => $q->where('fiscal_year_id', $fiscalYearId))->count())->toBe($liveLineCountBefore)
            ->and((float) JournalVoucherLine::whereHas('journalVoucher', fn ($q) => $q->where('fiscal_year_id', $fiscalYearId))->sum('debit'))->toBe($liveDebitBefore)
            ->and((float) JournalVoucherLine::whereHas('journalVoucher', fn ($q) => $q->where('fiscal_year_id', $fiscalYearId))->sum('credit'))->toBe($liveCreditBefore);
    });

    $tenant->delete();
});
