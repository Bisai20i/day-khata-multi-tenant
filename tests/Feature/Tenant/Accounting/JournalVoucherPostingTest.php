<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VoucherSequence;
use App\Support\ClosedFiscalYearGuard;
use App\Support\Money\InvalidAmount;
use App\Support\Money\Money;
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

        // The reversal posts into the dedicated Reversal series and points back
        // at the voucher it reverses (CONTRACTS C4), so no other series ever
        // loses a number to a cancellation.
        expect($reversal->voucher_type)->toBe(VoucherType::Reversal)
            ->and($reversal->status)->toBe('posted')
            ->and($reversal->reversal_of_id)->toBe($voucher->id);

        $linesByAccount = $reversal->lines->keyBy('account_id');

        // Every original debit becomes a credit of the same amount and
        // vice versa, so the net effect on each account touched is zero.
        expect((float) $linesByAccount[$depreciation->id]->credit)->toBe(300.0)
            ->and((float) $linesByAccount[$depreciation->id]->debit)->toBe(0.0)
            ->and((float) $linesByAccount[$disposalLoss->id]->credit)->toBe(200.0)
            ->and((float) $linesByAccount[$disposalLoss->id]->debit)->toBe(0.0)
            ->and((float) $linesByAccount[$cash->id]->debit)->toBe(500.0)
            ->and((float) $linesByAccount[$cash->id]->credit)->toBe(0.0);

        $totalDebit = Money::sum($reversal->lines->pluck('debit'));
        $totalCredit = Money::sum($reversal->lines->pluck('credit'));
        expect($totalDebit->isEqualTo($totalCredit))->toBeTrue()
            ->and($totalDebit->toString())->toBe('500.00');
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

test('cancelling a journal voucher from a now-closed fiscal year is rejected - correct it with a return or credit note instead', function () {
    // This used to succeed, posting the reversal into whichever year happened
    // to be open today. That moves money out of a period whose VAT return has
    // already been filed, so CONTRACTS C4/C5 locks cancellation to documents
    // whose own fiscal year is still the open one.
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

        expect(fn () => $voucher->fresh()->cancel($actor, 'Correcting after year-end close'))
            ->toThrow(InvalidArgumentException::class);

        expect($voucher->fresh()->status)->toBe('posted')
            ->and(JournalVoucher::where('voucher_type', VoucherType::Reversal)->count())->toBe(0);
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

test('a voucher whose sides only balance after rounding is rejected, and nothing is stored', function () {
    // Dr 333.333 x 3 against Cr 999.999: the old check summed floats and
    // compared round($sum, 2), so this passed and MySQL then rounded each line
    // on its own into Dr 999.99 against Cr 1000.00 (audit P0-2). A third
    // decimal is now refused outright rather than rounded for the user.
    $tenant = provisionVoucherTestTenant('jv-three-decimals.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        $expense = Account::where('code', 'EXE20')->firstOrFail();

        expect(fn () => JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Three-way split'],
            [
                ['account_id' => $expense->id, 'debit' => '333.333', 'credit' => 0],
                ['account_id' => $expense->id, 'debit' => '333.333', 'credit' => 0],
                ['account_id' => $expense->id, 'debit' => '333.333', 'credit' => 0],
                ['account_id' => cashAccount()->id, 'debit' => 0, 'credit' => '999.999'],
            ],
            $actor,
        ))->toThrow(InvalidAmount::class);

        expect(JournalVoucher::count())->toBe(0)
            ->and(JournalVoucherLine::count())->toBe(0);
    });

    $tenant->delete();
});

test('two lines that balance exactly at two decimals post and are stored to the paisa', function () {
    $tenant = provisionVoucherTestTenant('jv-exact-paisa.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        $voucher = JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Odd paisa'],
            [
                ['account_id' => cashAccount()->id, 'debit' => '0.01', 'credit' => 0],
                ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => '0.01'],
            ],
            $actor,
        );

        $lines = $voucher->lines()->orderBy('id')->get();

        expect($lines[0]->debit)->toBe('0.01')
            ->and($lines[0]->credit)->toBe('0.00')
            ->and($lines[1]->credit)->toBe('0.01');
    });

    $tenant->delete();
});

test('a line that moves nothing is rejected', function () {
    $tenant = provisionVoucherTestTenant('jv-zero-line.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        expect(fn () => JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'With a dead line'],
            [
                ['account_id' => cashAccount()->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 100],
                ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 0],
            ],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect(JournalVoucher::count())->toBe(0);
    });

    $tenant->delete();
});

test('a negative line amount is rejected rather than quietly flipping sides', function () {
    $tenant = provisionVoucherTestTenant('jv-negative-line.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        expect(fn () => JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Negative debit'],
            [
                ['account_id' => cashAccount()->id, 'debit' => '-100', 'credit' => 0],
                ['account_id' => salesAccount()->id, 'debit' => '-100', 'credit' => 0],
            ],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a voucher dated outside its fiscal year is rejected', function () {
    // The Asar 30 bill posted after the Shrawan 1 rollover: it used to land in
    // the new year's ledger while every date-filtered report placed it in the
    // already-filed period (audit P0-11).
    $tenant = provisionVoucherTestTenant('jv-date-outside-year.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        $lines = [
            ['account_id' => cashAccount()->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 100],
        ];

        expect(fn () => JournalVoucher::post(['date' => '2026-12-31', 'narration' => 'Too early'], $lines, $actor))
            ->toThrow(InvalidArgumentException::class);

        expect(fn () => JournalVoucher::post(['date' => '2028-01-01', 'narration' => 'Too late'], $lines, $actor))
            ->toThrow(InvalidArgumentException::class);

        // The boundaries themselves are inside the year.
        expect(JournalVoucher::post(['date' => '2027-01-01', 'narration' => 'First day'], $lines, $actor)->voucher_number)->toBe(1);
        expect(JournalVoucher::post(['date' => '2027-12-31', 'narration' => 'Last day'], $lines, $actor)->voucher_number)->toBe(2);
    });

    $tenant->delete();
});

test('a reversal is numbered in its own series and never consumes a sale or receipt number', function () {
    $tenant = provisionVoucherTestTenant('jv-reversal-series.tenant-test');

    $tenant->run(function () {
        $fy = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        $lines = [
            ['account_id' => cashAccount()->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 100],
        ];

        // Two sales and one receipt already numbered in this year.
        JournalVoucher::post(['voucher_type' => 'sale', 'date' => '2026-06-01', 'narration' => 'Sale 1'], $lines, $actor);
        $secondSale = JournalVoucher::post(['voucher_type' => 'sale', 'date' => '2026-06-02', 'narration' => 'Sale 2'], $lines, $actor);
        JournalVoucher::post(['voucher_type' => 'receipt', 'date' => '2026-06-03', 'narration' => 'Receipt 1'], $lines, $actor);

        $reversal = JournalVoucher::reverse($secondSale, $actor, 'Cancellation of sale 2');

        expect($reversal->voucher_type)->toBe(VoucherType::Reversal)
            ->and($reversal->voucher_number)->toBe(1)
            ->and($reversal->reversal_of_id)->toBe($secondSale->id)
            ->and($secondSale->fresh()->status)->toBe('cancelled');

        // Neither customer-facing series moved, so the next sale is SL-3 and
        // the next receipt is RC-2 with no hole in either.
        expect(VoucherSequence::nextNumberFor($fy, VoucherType::Sale))->toBe(3)
            ->and(VoucherSequence::nextNumberFor($fy, VoucherType::Receipt))->toBe(2);

        $next = JournalVoucher::post(['voucher_type' => 'sale', 'date' => '2026-06-04', 'narration' => 'Sale 3'], $lines, $actor);
        expect($next->voucher_number)->toBe(3);
    });

    $tenant->delete();
});

test('the mirrored reversal swaps every side and nets the touched accounts to zero', function () {
    $tenant = provisionVoucherTestTenant('jv-reversal-mirror.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        $original = JournalVoucher::post(
            ['voucher_type' => 'sale', 'date' => '2026-06-01', 'narration' => 'Credit sale'],
            [
                ['account_id' => cashAccount()->id, 'debit' => '1130.55', 'credit' => 0],
                ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => '1130.55'],
            ],
            $actor,
        );

        $reversal = JournalVoucher::reverse($original, $actor, 'Cancelled');
        $byAccount = $reversal->lines()->get()->keyBy('account_id');

        expect($byAccount[cashAccount()->id]->credit)->toBe('1130.55')
            ->and($byAccount[cashAccount()->id]->debit)->toBe('0.00')
            ->and($byAccount[salesAccount()->id]->debit)->toBe('1130.55');

        $netCash = Money::sum(JournalVoucherLine::where('account_id', cashAccount()->id)->pluck('debit'))
            ->minus(Money::sum(JournalVoucherLine::where('account_id', cashAccount()->id)->pluck('credit')));

        expect($netCash->toString())->toBe('0.00');
    });

    $tenant->delete();
});

test('a voucher can only be reversed once', function () {
    $tenant = provisionVoucherTestTenant('jv-reverse-once.tenant-test');

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        $original = JournalVoucher::post(
            ['voucher_type' => 'sale', 'date' => '2026-06-01', 'narration' => 'Credit sale'],
            [
                ['account_id' => cashAccount()->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 100],
            ],
            $actor,
        );

        JournalVoucher::reverse($original, $actor, 'First reversal');

        expect(fn () => JournalVoucher::reverse($original->fresh(), $actor, 'Second reversal'))
            ->toThrow(InvalidArgumentException::class);

        expect(JournalVoucher::where('voucher_type', VoucherType::Reversal)->count())->toBe(1);
    });

    $tenant->delete();
});

test('a starting number applies to the next voucher of that series and is refused once one exists', function () {
    $tenant = provisionVoucherTestTenant('jv-starting-number.tenant-test');

    $tenant->run(function () {
        $fy = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $actor = adminUser();

        $lines = [
            ['account_id' => cashAccount()->id, 'debit' => 100, 'credit' => 0],
            ['account_id' => salesAccount()->id, 'debit' => 0, 'credit' => 100],
        ];

        VoucherSequence::setStartingNumber($fy, VoucherType::Sale, 501);

        expect(VoucherSequence::nextNumberFor($fy, VoucherType::Sale))->toBe(501);

        $first = JournalVoucher::post(['voucher_type' => 'sale', 'date' => '2026-06-01', 'narration' => 'Sale'], $lines, $actor);
        $second = JournalVoucher::post(['voucher_type' => 'sale', 'date' => '2026-06-02', 'narration' => 'Sale'], $lines, $actor);

        expect($first->voucher_number)->toBe(501)
            ->and($second->voucher_number)->toBe(502);

        // Other series are untouched by one series' starting number.
        expect(VoucherSequence::nextNumberFor($fy, VoucherType::SalePan))->toBe(1);

        expect(fn () => VoucherSequence::setStartingNumber($fy, VoucherType::Sale, 900))
            ->toThrow(InvalidArgumentException::class);

        expect(fn () => VoucherSequence::setStartingNumber($fy, VoucherType::SalePan, 0))
            ->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('assertDateInOpenYear resolves the year a date belongs to, and refuses a closed one', function () {
    $tenant = provisionVoucherTestTenant('jv-assert-date-open-year.tenant-test');

    $tenant->run(function () {
        $closed = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);
        $open = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = adminUser();

        expect(ClosedFiscalYearGuard::assertDateInOpenYear('2027-06-01')->id)->toBe($open->id);

        // A date inside the closed year resolves to THAT year, not the open
        // one, and is then refused - the whole point of the guard.
        expect(fn () => ClosedFiscalYearGuard::assertDateInOpenYear('2026-06-01'))
            ->toThrow(InvalidArgumentException::class);

        // No fiscal year covers it at all.
        expect(fn () => ClosedFiscalYearGuard::assertDateInOpenYear('2030-06-01'))
            ->toThrow(InvalidArgumentException::class);

        // Reopened for correction: admin plus a reason gets through, a staff
        // member does not.
        $closed->reopen($admin, 'Auditor found a missed expense');

        expect(ClosedFiscalYearGuard::assertDateInOpenYear('2026-06-01', $admin, 'Missed expense')->id)->toBe($closed->id);

        expect(fn () => ClosedFiscalYearGuard::assertDateInOpenYear('2026-06-01', staffUser(), 'Missed expense'))
            ->toThrow(AuthorizationException::class);

        expect(fn () => ClosedFiscalYearGuard::assertDateInOpenYear('2026-06-01', $admin, null))
            ->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a staff user gets a 403 on the journal voucher store and cancel routes', function () {
    $domain = 'jv-admin-gate.tenant-test';
    $tenant = provisionVoucherTestTenant($domain);

    $voucherId = $cashId = $salesId = null;
    $tenant->run(function () use (&$voucherId, &$cashId, &$salesId) {
        User::factory()->create(['email' => 'staff@example.com', 'role_id' => Role::where('slug', 'staff')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $cashId = cashAccount()->id;
        $salesId = salesAccount()->id;

        $voucherId = JournalVoucher::post(
            ['date' => '2026-06-01', 'narration' => 'Posted by an admin'],
            [
                ['account_id' => $cashId, 'debit' => 100, 'credit' => 0],
                ['account_id' => $salesId, 'debit' => 0, 'credit' => 100],
            ],
            adminUser(),
        )->id;
    });

    $this->post("http://{$domain}/login", ['email' => 'staff@example.com', 'password' => 'password']);

    // Reading the ledger stays open to staff; writing to it does not.
    $this->get("http://{$domain}/journal-vouchers")->assertOk();

    $this->post("http://{$domain}/journal-vouchers", [
        'date' => '2026-06-02',
        'narration' => 'Staff attempt',
        'lines' => [
            ['account_id' => $cashId, 'debit' => 50, 'credit' => 0],
            ['account_id' => $salesId, 'debit' => 0, 'credit' => 50],
        ],
    ])->assertForbidden();

    $this->post("http://{$domain}/journal-vouchers/{$voucherId}/cancel", ['reason' => 'Staff attempt'])
        ->assertForbidden();

    $tenant->run(function () use ($voucherId) {
        expect(JournalVoucher::count())->toBe(1)
            ->and(JournalVoucher::find($voucherId)->status)->toBe('posted');
    });

    $tenant->delete();
});
