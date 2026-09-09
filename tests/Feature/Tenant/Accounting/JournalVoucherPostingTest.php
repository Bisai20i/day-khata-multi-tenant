<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Supplier;
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

test('posting into a plain closed fiscal year is rejected outright, admin and reason or not', function () {
    // The critical Phase D invariant: ClosedFiscalYearGuard never allows a
    // posting into a closed year that has never been reopen()'d, no matter
    // who's posting or whether a reason is given - see App\Support\
    // ClosedFiscalYearGuard's docblock.
    $tenant = provisionVoucherTestTenant('jv-closed-never-reopened.tenant-test');

    $tenant->run(function () {
        $closed = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);
        FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        expect(fn () => JournalVoucher::post(
            ['fiscal_year_id' => $closed->id, 'reason' => 'Missed expense', 'date' => '2026-06-01', 'narration' => 'Correction'],
            [
                ['account_id' => cashAccount()->id, 'debit' => 0, 'credit' => 50],
                ['account_id' => salesAccount()->id, 'debit' => 50, 'credit' => 0],
            ],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('posting into a reopened fiscal year without a reason is rejected', function () {
    $tenant = provisionVoucherTestTenant('jv-closed-no-reason.tenant-test');

    $tenant->run(function () {
        $closed = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);
        FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();
        $closed->reopen($actor, 'Auditor found a missed expense');

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

test('posting into a reopened fiscal year with a reason but a non-admin actor is rejected', function () {
    $tenant = provisionVoucherTestTenant('jv-closed-non-admin.tenant-test');

    $tenant->run(function () {
        $closed = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);
        FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = adminUser();
        $closed->reopen($admin, 'Auditor found a missed expense');
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

test('an admin can post a reasoned correction into a reopened fiscal year and it rolls forward into the open year', function () {
    $tenant = provisionVoucherTestTenant('jv-closed-override.tenant-test');

    $tenant->run(function () {
        $closed = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);
        $open = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();
        $closed->reopen($actor, 'Auditor found a duplicate sale');

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

test('cancelling a posted journal voucher posts a correctly mirrored reversal and marks the original cancelled', function () {
    $tenant = provisionVoucherTestTenant('jv-cancel-basic.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        $depreciation = Account::where('code', 'EXE20')->firstOrFail();
        $disposalLoss = Account::where('code', 'EXE21')->firstOrFail();
        $cash = cashAccount();

        // Three-line balanced voucher: two expense debits, one cash credit.
        $voucher = JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Misc expenses paid in cash'],
            [
                ['account_id' => $depreciation->id, 'debit' => 300, 'credit' => 0],
                ['account_id' => $disposalLoss->id, 'debit' => 200, 'credit' => 0],
                ['account_id' => $cash->id, 'debit' => 0, 'credit' => 500],
            ],
            $actor,
        );

        expect($voucher->status)->toBe('posted');

        $voucher->cancel($actor, 'Entered against the wrong accounts');

        expect($voucher->fresh()->status)->toBe('cancelled');

        $reversal = JournalVoucher::where('narration', "Cancellation of journal voucher #{$voucher->voucher_number}: Entered against the wrong accounts")
            ->firstOrFail();

        expect($reversal->voucher_type)->toBe(VoucherType::Journal)
            ->and($reversal->status)->toBe('posted');

        $linesByAccount = $reversal->lines->keyBy('account_id');

        // Every original debit becomes a credit of the same amount and
        // vice versa, so the net effect on each account touched is zero.
        expect((float) $linesByAccount[$depreciation->id]->credit)->toBe(300.0)
            ->and((float) $linesByAccount[$depreciation->id]->debit)->toBe(0.0)
            ->and((float) $linesByAccount[$disposalLoss->id]->credit)->toBe(200.0)
            ->and((float) $linesByAccount[$disposalLoss->id]->debit)->toBe(0.0)
            ->and((float) $linesByAccount[$cash->id]->debit)->toBe(500.0)
            ->and((float) $linesByAccount[$cash->id]->credit)->toBe(0.0);

        $totalDebit = round((float) $reversal->lines->sum('debit'), 2);
        $totalCredit = round((float) $reversal->lines->sum('credit'), 2);
        expect($totalDebit)->toBe($totalCredit)->and($totalDebit)->toBe(500.0);
    });

    $tenant->delete();
});

test('cancelling an already-cancelled journal voucher is rejected', function () {
    $tenant = provisionVoucherTestTenant('jv-cancel-twice.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        $voucher = JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Cash sale'],
            [
                ['account_id' => cashAccount()->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 100],
            ],
            $actor,
        );

        $voucher->cancel($actor, 'First cancellation');

        expect(fn () => $voucher->fresh()->cancel($actor, 'Second attempt'))
            ->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling a journal voucher that backs a Payment (or any other module) is rejected - cancel the source record instead', function () {
    $tenant = provisionVoucherTestTenant('jv-cancel-source-guard.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();
        $supplier = Supplier::factory()->create();

        $payment = Payment::post([
            'supplier_id' => $supplier->id,
            'date' => '2026-06-10',
            'amount' => 200,
            'payment_mode' => 'cash',
        ], $actor);

        $backingVoucher = $payment->journalVoucher()->firstOrFail();

        // The generic Journal Vouchers index lists every voucher_type with
        // no filter, so this guards against cancelling a Payment's (or
        // Sale's, Purchase's, ...) own voucher from that generic screen,
        // which would reverse the ledger without ever marking the Payment
        // itself cancelled.
        expect(fn () => $backingVoucher->cancel($actor, 'Attempted from the generic screen'))
            ->toThrow(InvalidArgumentException::class);

        expect($backingVoucher->fresh()->status)->toBe('posted')
            ->and($payment->fresh()->status)->toBe('posted');
    });

    $tenant->delete();
});

test('cancelling a system-generated voucher type (e.g. opening balance) is rejected', function () {
    $tenant = provisionVoucherTestTenant('jv-cancel-system-type-guard.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        $opening = JournalVoucher::post(
            ['voucher_type' => 'opening_balance', 'date' => '2026-01-01', 'narration' => 'Opening balance'],
            [
                ['account_id' => cashAccount()->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 1000],
            ],
            $actor,
        );

        expect(fn () => $opening->cancel($actor, 'Trying to undo the opening balance'))
            ->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling a journal voucher originally posted in a now-closed fiscal year still succeeds, posting the reversal into the currently open year', function () {
    // Matches every sibling cancel() (Payment::cancel(), Sale::cancel(),
    // etc.): none of them re-check the ORIGINAL document's fiscal year at
    // all - they call post() with no fiscal_year_id, which always resolves
    // to FiscalYear::current(), so the reversal always lands in whichever
    // year is open today regardless of which year the original document
    // lived in. There is deliberately no "original fiscal year must still
    // be open/reopened" guard anywhere in this app for that reason - only
    // ClosedFiscalYearGuard's own "closed and never reopened" check inside
    // post() applies, and it's a no-op here since the reversal always
    // targets the (already open) current year.
    $tenant = provisionVoucherTestTenant('jv-cancel-original-year-closed.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $actor = adminUser();

        $voucher = JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Cash sale'],
            [
                ['account_id' => cashAccount()->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 100],
            ],
            $actor,
        );

        $fy1->close($fy2, $actor);

        expect($fy1->fresh()->status)->toBe(FiscalYearStatus::Closed)
            ->and($fy2->fresh()->status)->toBe(FiscalYearStatus::Open);

        $voucher->cancel($actor, 'Correcting after year-end close');

        expect($voucher->fresh()->status)->toBe('cancelled');

        $reversal = JournalVoucher::where('narration', "Cancellation of journal voucher #{$voucher->voucher_number}: Correcting after year-end close")
            ->firstOrFail();

        expect($reversal->fiscal_year_id)->toBe($fy2->id);
    });

    $tenant->delete();
});

test('the journal vouchers index page renders and an authenticated user can post and cancel a journal voucher via HTTP', function () {
    $domain = 'jv-http.tenant-test';
    $tenant = provisionVoucherTestTenant($domain);

    $cashId = $salesId = null;
    $tenant->run(function () use (&$cashId, &$salesId) {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $cashId = cashAccount()->id;
        $salesId = salesAccount()->id;
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/journal-vouchers")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Accounting/JournalVouchers/Index'));

    $this->post("http://{$domain}/journal-vouchers", [
        'date' => '2026-06-01',
        'narration' => 'Cash sale',
        'lines' => [
            ['account_id' => $cashId, 'debit' => 150, 'credit' => 0],
            ['account_id' => $salesId, 'debit' => 0, 'credit' => 150],
        ],
    ])->assertRedirect();

    $voucherId = null;
    $tenant->run(function () use (&$voucherId) {
        $voucher = JournalVoucher::where('voucher_type', VoucherType::Journal)->firstOrFail();
        expect($voucher->status)->toBe('posted');
        $voucherId = $voucher->id;
    });

    $this->post("http://{$domain}/journal-vouchers/{$voucherId}/cancel", ['reason' => 'Duplicate entry'])
        ->assertRedirect();

    $tenant->run(function () use ($voucherId) {
        expect(JournalVoucher::find($voucherId)->status)->toBe('cancelled');
    });

    $tenant->delete();
});
