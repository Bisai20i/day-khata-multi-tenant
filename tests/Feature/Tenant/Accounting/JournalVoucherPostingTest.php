<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionVoucherTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function cashAccount(): Account
{
    return Account::where('code', 'AS1')->firstOrFail();
}

function salesAccount(): Account
{
    return Account::where('code', 'INI20')->firstOrFail();
}

function adminUser(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

function staffUser(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'staff')->value('id')]);
}

test('posting requires at least two lines', function () {
    $tenant = provisionVoucherTestTenant('jv-min-lines.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        expect(fn () => JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Test'],
            [['account_id' => cashAccount()->id, 'debit' => 100, 'credit' => 0]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('posting rejects a line with both debit and credit set', function () {
    $tenant = provisionVoucherTestTenant('jv-both-sides.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        expect(fn () => JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Test'],
            [
                ['account_id' => cashAccount()->id, 'debit' => 100, 'credit' => 100],
                ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 100],
            ],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('posting rejects unbalanced lines', function () {
    $tenant = provisionVoucherTestTenant('jv-unbalanced.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        expect(fn () => JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Test'],
            [
                ['account_id' => cashAccount()->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 90],
            ],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('posting throws when no fiscal year is open', function () {
    $tenant = provisionVoucherTestTenant('jv-no-open-fy.tenant-test');

    $tenant->run(function () {
        $actor = adminUser();

        expect(fn () => JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Test'],
            [
                ['account_id' => cashAccount()->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 100],
            ],
            $actor,
        ))->toThrow(ModelNotFoundException::class);
    });

    $tenant->delete();
});

test('a balanced voucher posts into the currently open fiscal year with a sequential voucher number', function () {
    $tenant = provisionVoucherTestTenant('jv-post-basic.tenant-test');

    $tenant->run(function () {
        $fy = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        $lines = [
            ['account_id' => cashAccount()->id, 'debit' => 500, 'credit' => 0],
            ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 500],
        ];

        $first = JournalVoucher::post(['date' => '2026-06-01', 'narration' => 'Cash sale 1'], $lines, $actor);
        $second = JournalVoucher::post(['date' => '2026-06-02', 'narration' => 'Cash sale 2'], $lines, $actor);

        expect($first->fiscal_year_id)->toBe($fy->id)
            ->and($first->voucher_type)->toBe(VoucherType::Journal)
            ->and($first->voucher_number)->toBe(1)
            ->and($second->voucher_number)->toBe(2)
            ->and($first->lines)->toHaveCount(2);
    });

    $tenant->delete();
});

test('voucher numbering is independent per voucher type', function () {
    $tenant = provisionVoucherTestTenant('jv-numbering-per-type.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        $lines = [
            ['account_id' => cashAccount()->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 100],
        ];

        $journal = JournalVoucher::post(['date' => '2026-06-01', 'narration' => 'J'], $lines, $actor);
        $opening = JournalVoucher::post(['voucher_type' => 'opening_balance', 'date' => '2026-01-01', 'narration' => 'O'], $lines, $actor);

        expect($journal->voucher_number)->toBe(1)
            ->and($opening->voucher_number)->toBe(1);
    });

    $tenant->delete();
});

test('posting into a closed fiscal year without a reason is rejected', function () {
    $tenant = provisionVoucherTestTenant('jv-closed-no-reason.tenant-test');

    $tenant->run(function () {
        $closed = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);
        FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        expect(fn () => JournalVoucher::post(
            ['fiscal_year_id' => $closed->id, 'date' => '2026-06-01', 'narration' => 'Correction'],
            [
                ['account_id' => cashAccount()->id, 'debit' => 0, 'credit' => 50],
                ['account_id' => salesAccount()->id, 'debit' => 50, 'credit' => 0],
            ],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('posting into a closed fiscal year with a reason but a non-admin actor is rejected', function () {
    $tenant = provisionVoucherTestTenant('jv-closed-non-admin.tenant-test');

    $tenant->run(function () {
        $closed = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);
        FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = staffUser();

        expect(fn () => JournalVoucher::post(
            ['fiscal_year_id' => $closed->id, 'reason' => 'Missed expense', 'date' => '2026-06-01', 'narration' => 'Correction'],
            [
                ['account_id' => cashAccount()->id, 'debit' => 0, 'credit' => 50],
                ['account_id' => salesAccount()->id, 'debit' => 50, 'credit' => 0],
            ],
            $actor,
        ))->toThrow(AuthorizationException::class);
    });

    $tenant->delete();
});

test('an admin can post a reasoned correction into a closed fiscal year and it rolls forward into the open year', function () {
    $tenant = provisionVoucherTestTenant('jv-closed-override.tenant-test');

    $tenant->run(function () {
        $closed = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);
        $open = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        // "We recorded a sale that shouldn't have been recorded": debit
        // Sales (reduces income), credit Cash (reduces cash), 100 each.
        $correction = JournalVoucher::post(
            [
                'fiscal_year_id' => $closed->id,
                'reason' => 'Duplicate sale recorded in error',
                'date' => '2026-06-15',
                'narration' => 'Reverse duplicate sale',
            ],
            [
                ['account_id' => salesAccount()->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => cashAccount()->id, 'debit' => 0, 'credit' => 100],
            ],
            $actor,
        );

        expect($correction->fiscal_year_id)->toBe($closed->id)
            ->and($correction->reason)->toBe('Duplicate sale recorded in error');

        // The corrected (closed) year itself must stay untouched beyond the
        // correction voucher — no roll-forward should ever land back in the
        // year being corrected.
        expect(JournalVoucher::where('fiscal_year_id', $closed->id)->count())->toBe(1);

        $rollForward = JournalVoucher::where('fiscal_year_id', $open->id)
            ->where('voucher_type', VoucherType::RollForwardAdjustment)
            ->firstOrFail();

        $plAccount = Account::where('name', 'Profit & Loss')->firstOrFail();
        $linesByAccount = $rollForward->lines->keyBy('account_id');

        // Sales is a profit-and-loss account, so its share of the
        // correction retargets to Profit & Loss (same debit side, 100).
        expect((float) $linesByAccount[$plAccount->id]->debit)->toBe(100.0)
            ->and((float) $linesByAccount[$plAccount->id]->credit)->toBe(0.0);

        // Cash is a balance-sheet account, so it carries forward directly
        // (same credit side, 100).
        $cashId = cashAccount()->id;
        expect((float) $linesByAccount[$cashId]->credit)->toBe(100.0)
            ->and((float) $linesByAccount[$cashId]->debit)->toBe(0.0);
    });

    $tenant->delete();
});
