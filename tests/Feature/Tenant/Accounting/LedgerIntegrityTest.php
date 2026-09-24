<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function ledgerIntegrityTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * Opens FY1 and posts one Dr Cash / Cr Sales voucher.
 *
 * @return array{0: JournalVoucher, 1: User}
 */
function ledgerIntegrityPost(): array
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    $admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);

    $voucher = JournalVoucher::post(
        ['date' => '2026-06-01', 'narration' => 'Cash sale'],
        [
            ['account_id' => Account::where('code', 'AS1')->value('id'), 'debit' => 100, 'credit' => 0],
            ['account_id' => Account::where('code', 'INI20')->value('id'), 'debit' => 0, 'credit' => 100],
        ],
        $admin,
    );

    return [$voucher, $admin];
}

test('JE-01 posted lines and vouchers cannot be edited or deleted through the models', function () {
    $tenant = ledgerIntegrityTenant('je01.tenant-test');

    $tenant->run(function () {
        [$voucher] = ledgerIntegrityPost();
        $line = $voucher->lines()->first();

        expect(fn () => $line->update(['debit' => '5.00']))->toThrow(LogicException::class)
            ->and(fn () => $line->delete())->toThrow(LogicException::class)
            ->and(fn () => $voucher->update(['narration' => 'edited']))->toThrow(LogicException::class)
            ->and(fn () => $voucher->delete())->toThrow(LogicException::class);

        expect(JournalVoucherLine::count())->toBe(2)
            ->and($voucher->fresh()->narration)->toBe('Cash sale');
    });

    $tenant->delete();
});

test('JE-01 the database refuses to delete a voucher that has lines', function () {
    $tenant = ledgerIntegrityTenant('je01-fk.tenant-test');

    $tenant->run(function () {
        [$voucher] = ledgerIntegrityPost();

        expect(fn () => JournalVoucher::query()->whereKey($voucher->id)->delete())
            ->toThrow(QueryException::class);
    });

    $tenant->delete();
});

test('JE-01 reversing a voucher still works and only touches status and the reversal link', function () {
    $tenant = ledgerIntegrityTenant('je01-reverse.tenant-test');

    $tenant->run(function () {
        [$voucher, $admin] = ledgerIntegrityPost();

        $reversal = JournalVoucher::reverse($voucher, $admin, 'Undo');

        expect($voucher->fresh()->status)->toBe('cancelled')
            ->and($reversal->reversal_of_id)->toBe($voucher->id);
    });

    $tenant->delete();
});

test('JE-02 system accounts cannot be deleted, renamed or recoded, and accounts with postings cannot be deleted', function () {
    $tenant = ledgerIntegrityTenant('je02.tenant-test');

    $tenant->run(function () {
        ledgerIntegrityPost();

        $cash = Account::where('code', 'AS1')->firstOrFail();
        expect(fn () => $cash->delete())->toThrow(InvalidArgumentException::class)
            ->and(fn () => $cash->update(['name' => 'Petty Cash']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $cash->fresh()->update(['code' => 'ZZ1']))->toThrow(InvalidArgumentException::class);

        $pl = Account::where('name', 'Profit & Loss')->firstOrFail();
        expect(fn () => $pl->delete())->toThrow(InvalidArgumentException::class);

        // A non-protected account with postings cannot be deleted either.
        $custom = Account::create(['account_group_id' => AccountGroup::first()->id, 'name' => 'Used']);
        JournalVoucher::post(
            ['date' => '2026-06-02', 'narration' => 'Use it'],
            [
                ['account_id' => Account::where('code', 'AS1')->value('id'), 'debit' => 10, 'credit' => 0],
                ['account_id' => $custom->id, 'debit' => 0, 'credit' => 10],
            ],
            User::first(),
        );
        expect(fn () => $custom->delete())->toThrow(InvalidArgumentException::class);

        $unused = Account::create(['account_group_id' => AccountGroup::first()->id, 'name' => 'Temp']);
        $unused->delete();
        expect(Account::where('name', 'Temp')->exists())->toBeFalse();
    });

    $tenant->delete();
});

test('JE-02 deleting a protected account through the controller returns a validation error, not a 500', function () {
    $domain = 'je02-http.tenant-test';
    $tenant = ledgerIntegrityTenant($domain);

    $cashId = null;
    $tenant->run(function () use (&$cashId) {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        $cashId = Account::where('code', 'AS1')->value('id');
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);
    $this->delete("http://{$domain}/accounts/{$cashId}")->assertSessionHasErrors('account');

    $tenant->run(fn () => expect(Account::where('code', 'AS1')->exists())->toBeTrue());

    $tenant->delete();
});

test('JE-03 an account with postings cannot move to a different head', function () {
    $tenant = ledgerIntegrityTenant('je03.tenant-test');

    $tenant->run(function () {
        ledgerIntegrityPost();

        $account = Account::create(['account_group_id' => AccountGroup::first()->id, 'name' => 'Movable']);
        $originalHeadId = $account->group->account_head_id;
        $otherGroup = AccountGroup::where('account_head_id', '!=', $originalHeadId)->firstOrFail();

        // No postings yet: moving heads is allowed.
        $account->update(['account_group_id' => $otherGroup->id]);
        expect($account->fresh()->account_group_id)->toBe($otherGroup->id);

        JournalVoucher::post(
            ['date' => '2026-06-02', 'narration' => 'Use it'],
            [
                ['account_id' => Account::where('code', 'AS1')->value('id'), 'debit' => 10, 'credit' => 0],
                ['account_id' => $account->id, 'debit' => 0, 'credit' => 10],
            ],
            User::first(),
        );

        $differentHead = AccountGroup::where('account_head_id', '!=', $otherGroup->account_head_id)->firstOrFail();
        expect(fn () => $account->fresh()->update(['account_group_id' => $differentHead->id]))
            ->toThrow(InvalidArgumentException::class);

        expect($account->fresh()->account_group_id)->toBe($otherGroup->id);
    });

    $tenant->delete();
});

test('JE-04 closing the year and then posting into it are serialised: the late post is refused', function () {
    $tenant = ledgerIntegrityTenant('je04.tenant-test');

    $tenant->run(function () {
        [, $admin] = ledgerIntegrityPost();

        $year = FiscalYear::current();
        $next = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $year->close($next, $admin, 'Closing early for the JE-04 test');

        $lines = [
            ['account_id' => Account::where('code', 'AS1')->value('id'), 'debit' => 5, 'credit' => 0],
            ['account_id' => Account::where('code', 'INI20')->value('id'), 'debit' => 0, 'credit' => 5],
        ];

        // post() re-reads the year under lockForUpdate(), so it sees Closed
        // and refuses instead of writing an unswept voucher into it.
        expect(fn () => JournalVoucher::post(
            ['date' => '2026-07-01', 'narration' => 'Late', 'fiscal_year_id' => $year->id],
            $lines,
            $admin,
        ))->toThrow(Exception::class);
    });

    $tenant->delete();
});
