<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\FiscalYearArchive;
use App\Models\JournalVoucher;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FiscalYear\FiscalYearArchiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionArchiveBrowsingTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginAsArchiveBrowsingAdmin(string $domain): User
{
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

test('an admin can browse an archived fiscal year and one of its vouchers, and browsing never mutates the archive', function () {
    $domain = 'fy-archive-browse.tenant-test';
    $tenant = provisionArchiveBrowsingTestTenant($domain);
    $admin = loginAsArchiveBrowsingAdmin($domain);

    $archiveId = null;
    $saleVoucherId = null;
    $expectedVoucherCount = null;
    $expectedLineCount = null;

    $tenant->run(function () use ($admin, &$archiveId, &$saleVoucherId, &$expectedVoucherCount, &$expectedLineCount) {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $sales = Account::where('code', 'INI20')->firstOrFail();
        $purchases = Account::where('code', 'EXE8')->firstOrFail();

        $sale = JournalVoucher::post(
            ['date' => '2026-02-01', 'narration' => 'Archive test sale one'],
            [
                ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 1000],
            ],
            $admin,
        );

        JournalVoucher::post(
            ['date' => '2026-03-01', 'narration' => 'Archive test sale two'],
            [
                ['account_id' => $cash->id, 'debit' => 750, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 750],
            ],
            $admin,
        );

        JournalVoucher::post(
            ['date' => '2026-04-01', 'narration' => 'Archive test purchase'],
            [
                ['account_id' => $purchases->id, 'debit' => 400, 'credit' => 0],
                ['account_id' => $cash->id, 'debit' => 0, 'credit' => 400],
            ],
            $admin,
        );

        $fy1->close($fy2, $admin);

        // Faithful stand-in for FiscalYearArchiveController::store(), which
        // is being built in parallel and may not exist on disk yet - this
        // calls exactly what that controller will call under the hood.
        $archive = FiscalYearArchiver::archive($fy1->fresh(), $admin);

        $archiveId = $archive->id;
        $saleVoucherId = $sale->id;
        $expectedVoucherCount = $archive->voucher_count;
        $expectedLineCount = $archive->line_count;

        // 3 posted vouchers plus at least the year-end closing entry.
        expect($expectedVoucherCount)->toBeGreaterThanOrEqual(4);
    });

    $showResponse = test()->get("http://{$domain}/fiscal-year-archives/{$archiveId}");

    $showResponse->assertOk()->assertInertia(fn ($page) => $page
        ->component('Tenant/Accounting/FiscalYearArchive/Show')
        ->where('fiscalYear.name', 'FY1')
        ->where('archive.voucherCount', $expectedVoucherCount)
        ->where('archive.lineCount', $expectedLineCount)
        ->has('vouchers', $expectedVoucherCount)
        ->where('vouchers.0.narration', 'Archive test sale one')
        ->where('vouchers.0.totalDebit', 1000)
        ->where('vouchers.0.totalCredit', 1000)
        ->where('vouchers.1.narration', 'Archive test sale two')
        ->where('vouchers.1.totalDebit', 750)
        ->where('vouchers.1.totalCredit', 750)
    );

    $voucherResponse = test()->get("http://{$domain}/fiscal-year-archives/{$archiveId}/vouchers/{$saleVoucherId}");

    $voucherResponse->assertOk()->assertInertia(fn ($page) => $page
        ->component('Tenant/Accounting/FiscalYearArchive/VoucherDetail')
        ->where('voucher.narration', 'Archive test sale one')
        ->has('lines', 2)
        ->where('lines.0.accountCode', 'AS1')
        ->where('lines.0.debit', 1000)
        ->where('lines.0.credit', 0)
        ->where('lines.1.accountCode', 'INI20')
        ->where('lines.1.debit', 0)
        ->where('lines.1.credit', 1000)
    );

    test()->get("http://{$domain}/fiscal-year-archives/{$archiveId}/vouchers/999999")->assertNotFound();

    $tenant->run(function () use ($archiveId, $expectedVoucherCount, $expectedLineCount) {
        $archive = FiscalYearArchive::findOrFail($archiveId);
        $connection = FiscalYearArchiver::connectionFor($archive);

        expect(DB::connection($connection)->table('journal_vouchers')->count())->toBe($expectedVoucherCount)
            ->and(DB::connection($connection)->table('journal_voucher_lines')->count())->toBe($expectedLineCount)
            ->and($archive->fresh()->voucher_count)->toBe($expectedVoucherCount)
            ->and($archive->fresh()->line_count)->toBe($expectedLineCount);
    });

    $tenant->delete();
});

test('a non-admin cannot browse a fiscal year archive or one of its vouchers', function () {
    $domain = 'fy-archive-staff.tenant-test';
    $tenant = provisionArchiveBrowsingTestTenant($domain);

    $archiveId = null;

    $tenant->run(function () use (&$archiveId) {
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $admin = User::factory()->create(['role_id' => $adminRole->id]);

        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $sales = Account::where('code', 'INI20')->firstOrFail();

        JournalVoucher::post(
            ['date' => '2026-02-01', 'narration' => 'Staff test sale'],
            [
                ['account_id' => $cash->id, 'debit' => 500, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 500],
            ],
            $admin,
        );

        $fy1->close($fy2, $admin);

        $archive = FiscalYearArchiver::archive($fy1->fresh(), $admin);
        $archiveId = $archive->id;

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

    test()->get("http://{$domain}/fiscal-year-archives/{$archiveId}")->assertForbidden();
    test()->get("http://{$domain}/fiscal-year-archives/{$archiveId}/vouchers/1")->assertForbidden();

    $tenant->delete();
});
