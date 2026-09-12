<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\AccountHead;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionClosingTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * Every fixture year here ends on 2026-12-31, which is still in the future
 * relative to the suite's clock, so every close in this file is an EARLY
 * close and needs an admin plus a written reason (T11 task 7).
 */
const CLOSING_TEST_REASON = 'Closed early by the test fixture.';

function closingTestAdmin(string $email = 'closer@example.com'): User
{
    return User::factory()->create([
        'email' => $email,
        'role_id' => Role::where('slug', 'admin')->value('id'),
    ]);
}

/**
 * Net (debit - credit) for one account inside one fiscal year, read the way
 * the ledger reads it.
 */
function closingTestNet(int $accountId, int $fiscalYearId): string
{
    $net = JournalVoucherLine::query()
        ->where('account_id', $accountId)
        ->whereHas('journalVoucher', fn ($query) => $query->where('fiscal_year_id', $fiscalYearId))
        ->selectRaw('COALESCE(SUM(CAST(ROUND(debit * 100) AS INTEGER)), 0) - COALESCE(SUM(CAST(ROUND(credit * 100) AS INTEGER)), 0) as net_scaled')
        ->value('net_scaled');

    return number_format(((int) $net) / 100, 2, '.', '');
}

test('closing a fiscal year that is not open is rejected', function () {
    $tenant = provisionClosingTestTenant('fy-close-not-open.tenant-test');

    $tenant->run(function () {
        $closed = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);
        $next = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $actor = closingTestAdmin();

        expect(fn () => $closed->close($next, $actor, CLOSING_TEST_REASON))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('closing a fiscal year sweeps profit-and-loss accounts to zero and carries balance-sheet accounts forward', function () {
    $tenant = provisionClosingTestTenant('fy-close-full.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $actor = closingTestAdmin();

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $sales = Account::where('code', 'INI20')->firstOrFail();
        $purchases = Account::where('code', 'EXE8')->firstOrFail();
        $pl = Account::where('name', 'Profit & Loss')->firstOrFail();

        // A cash sale of 1000.
        JournalVoucher::post(
            ['date' => '2026-03-01', 'narration' => 'Cash sale'],
            [
                ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 1000],
            ],
            $actor,
        );

        // A cash purchase/expense of 400.
        JournalVoucher::post(
            ['date' => '2026-04-01', 'narration' => 'Cash purchase'],
            [
                ['account_id' => $purchases->id, 'debit' => 400, 'credit' => 0],
                ['account_id' => $cash->id, 'debit' => 0, 'credit' => 400],
            ],
            $actor,
        );

        $fy1->close($fy2, $actor, CLOSING_TEST_REASON);

        expect($fy1->fresh()->status)->toBe(FiscalYearStatus::Closed)
            ->and($fy2->fresh()->status)->toBe(FiscalYearStatus::Open)
            ->and($fy1->fresh()->close_reason)->toBe(CLOSING_TEST_REASON);

        // Sales and Purchases are fully zeroed within FY1 once the closing
        // entries are included.
        expect(closingTestNet($sales->id, $fy1->id))->toBe('0.00')
            ->and(closingTestNet($purchases->id, $fy1->id))->toBe('0.00');

        // Net profit (1000 - 400 = 600) lands as a credit to Profit & Loss
        // within FY1's own closing entries - net = debit - credit, so a
        // pure credit balance is negative here.
        expect(closingTestNet($pl->id, $fy1->id))->toBe('-600.00');

        // This tenant holds no stock, so the trading pair posts nothing and
        // the only ClosingEntry voucher is the sweep itself.
        $closingEntries = JournalVoucher::where('fiscal_year_id', $fy1->id)
            ->where('voucher_type', VoucherType::ClosingEntry)
            ->get();
        expect($closingEntries)->toHaveCount(1)
            ->and($closingEntries->first()->lines)->toHaveCount(3);

        // FY2's opening balances: Cash carries forward its 600 debit
        // balance (1000 debit - 400 credit), Profit & Loss carries forward
        // its 600 credit balance - the two lines balance each other.
        $opening = JournalVoucher::where('fiscal_year_id', $fy2->id)
            ->where('voucher_type', VoucherType::OpeningBalance)
            ->firstOrFail();

        $linesByAccount = $opening->lines->keyBy('account_id');

        expect($linesByAccount[$cash->id]->debit)->toBe('600.00')
            ->and($linesByAccount[$cash->id]->credit)->toBe('0.00')
            ->and($linesByAccount[$pl->id]->credit)->toBe('600.00')
            ->and($linesByAccount[$pl->id]->debit)->toBe('0.00');
    });

    $tenant->delete();
});

test('closing a year whose balances do not sum to round figures succeeds and posts exact amounts', function () {
    // The regression this whole task started from (audit P0-1, reported by
    // T03). postClosingEntries() used to pass `(float) netBalance(...)`
    // straight into JournalVoucher::write(), which now refuses any line with
    // more than two decimals. Three expense postings of 753.70 sum, in IEEE
    // 754 doubles, to 2261.1000000000004 - so a perfectly ordinary year-end
    // close threw InvalidAmount and took the whole close down. Every other
    // test in this file uses round figures, which is exactly why the suite
    // stayed green while a real tenant's close would have failed.
    $tenant = provisionClosingTestTenant('fy-close-unround.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $actor = closingTestAdmin();

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $purchases = Account::where('code', 'EXE8')->firstOrFail();
        $sales = Account::where('code', 'INI20')->firstOrFail();
        $pl = Account::where('name', 'Profit & Loss')->firstOrFail();

        foreach (['2026-02-01', '2026-03-01', '2026-04-01'] as $date) {
            JournalVoucher::post(
                ['date' => $date, 'narration' => 'Cash purchase'],
                [
                    ['account_id' => $purchases->id, 'debit' => '753.70', 'credit' => 0],
                    ['account_id' => $cash->id, 'debit' => 0, 'credit' => '753.70'],
                ],
                $actor,
            );
        }

        // And an income side that is equally unfriendly: 3 x 1,010.10.
        foreach (['2026-05-01', '2026-06-01', '2026-07-01'] as $date) {
            JournalVoucher::post(
                ['date' => $date, 'narration' => 'Cash sale'],
                [
                    ['account_id' => $cash->id, 'debit' => '1010.10', 'credit' => 0],
                    ['account_id' => $sales->id, 'debit' => 0, 'credit' => '1010.10'],
                ],
                $actor,
            );
        }

        $fy1->close($fy2, $actor, CLOSING_TEST_REASON);

        $closingEntry = JournalVoucher::where('fiscal_year_id', $fy1->id)
            ->where('voucher_type', VoucherType::ClosingEntry)
            ->with('lines')
            ->firstOrFail();

        $lines = $closingEntry->lines->keyBy('account_id');

        // 3 x 753.70 = 2261.10 exactly, not 2261.1000000000004.
        expect($lines[$purchases->id]->credit)->toBe('2261.10')
            ->and($lines[$sales->id]->debit)->toBe('3030.30')
            // Net profit = 3030.30 - 2261.10 = 769.20.
            ->and($lines[$pl->id]->credit)->toBe('769.20');

        expect(closingTestNet($purchases->id, $fy1->id))->toBe('0.00')
            ->and(closingTestNet($sales->id, $fy1->id))->toBe('0.00');

        // And the carry-forward is exact too: cash = 3030.30 - 2261.10.
        $opening = JournalVoucher::where('fiscal_year_id', $fy2->id)
            ->where('voucher_type', VoucherType::OpeningBalance)
            ->firstOrFail();

        expect($opening->lines->keyBy('account_id')[$cash->id]->debit)->toBe('769.20');
    });

    $tenant->delete();
});

test('closing a year with stock on hand posts the trading pair and carries the closing value into the new year', function () {
    $tenant = provisionClosingTestTenant('fy-close-stock.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $actor = closingTestAdmin();

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $purchases = Account::where('code', 'EXE8')->firstOrFail();
        $sales = Account::where('code', 'INI20')->firstOrFail();
        $stockInHand = Account::where('code', FiscalYear::STOCK_IN_HAND_CODE)->firstOrFail();
        $openingStock = Account::where('code', FiscalYear::OPENING_STOCK_CODE)->firstOrFail();
        $closingStock = Account::where('code', FiscalYear::CLOSING_STOCK_CODE)->firstOrFail();
        $pl = Account::where('name', 'Profit & Loss')->firstOrFail();

        expect($stockInHand->name)->toBe('Stock in Hand');

        $store = Store::factory()->create();
        $item = Item::factory()->create(['is_stockable' => true, 'purchase_rate' => '10.00']);

        // 100 units bought in at 10.00 (value 1,000.00), 40 sold. Weighted
        // average cost stays 10.00, so closing stock is 60 x 10.00 = 600.00.
        $item->recordStockMovement(StockMovementType::Purchase, '100', '2026-02-01', $store->id, null, '10.0000', '1000.00');
        $item->recordStockMovement(StockMovementType::Sale, '40', '2026-05-01', $store->id);

        JournalVoucher::post(
            ['date' => '2026-02-01', 'narration' => 'Cash purchase'],
            [
                ['account_id' => $purchases->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $cash->id, 'debit' => 0, 'credit' => 1000],
            ],
            $actor,
        );

        JournalVoucher::post(
            ['date' => '2026-05-01', 'narration' => 'Cash sale'],
            [
                ['account_id' => $cash->id, 'debit' => 700, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 700],
            ],
            $actor,
        );

        $fy1->close($fy2, $actor, CLOSING_TEST_REASON);

        // The closing-stock voucher: Dr Stock in Hand / Cr Closing Stock.
        // Nothing was brought in, so no opening-stock voucher was posted.
        $stockVouchers = JournalVoucher::where('fiscal_year_id', $fy1->id)
            ->where('voucher_type', VoucherType::ClosingEntry)
            ->whereHas('lines', fn ($query) => $query->where('account_id', $stockInHand->id))
            ->with('lines')
            ->get();

        expect($stockVouchers)->toHaveCount(1);

        $stockLines = $stockVouchers->first()->lines->keyBy('account_id');
        expect($stockLines[$stockInHand->id]->debit)->toBe('600.00')
            ->and($stockLines[$closingStock->id]->credit)->toBe('600.00');

        // Gross profit is now right: 700 sales - 1000 purchases + 600
        // closing stock = 300 profit, not a 300 loss.
        expect(closingTestNet($pl->id, $fy1->id))->toBe('-300.00');

        // Stock in Hand nets to the closing value inside FY1 and is carried
        // into FY2's opening balances at exactly that.
        expect(closingTestNet($stockInHand->id, $fy1->id))->toBe('600.00');

        $opening = JournalVoucher::where('fiscal_year_id', $fy2->id)
            ->where('voucher_type', VoucherType::OpeningBalance)
            ->firstOrFail();

        expect($opening->lines->keyBy('account_id')[$stockInHand->id]->debit)->toBe('600.00');

        // FY2's own close then moves that 600 into Opening Stock.
        $fy3 = FiscalYear::create(['name' => 'FY3', 'start_date' => '2028-01-01', 'end_date' => '2028-12-31', 'status' => FiscalYearStatus::Closed]);
        $fy2->refresh()->close($fy3, $actor, CLOSING_TEST_REASON);

        expect(closingTestNet($openingStock->id, $fy2->id))->toBe('0.00');

        $openingStockVoucher = JournalVoucher::where('fiscal_year_id', $fy2->id)
            ->where('voucher_type', VoucherType::ClosingEntry)
            ->whereHas('lines', fn ($query) => $query->where('account_id', $openingStock->id))
            ->with('lines')
            ->firstOrFail();

        expect($openingStockVoucher->lines->keyBy('account_id')[$openingStock->id]->debit)->toBe('600.00');
    });

    $tenant->delete();
});

test('a second close of the same fiscal year is rejected and posts nothing twice', function () {
    $tenant = provisionClosingTestTenant('fy-close-double.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $actor = closingTestAdmin();

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $sales = Account::where('code', 'INI20')->firstOrFail();

        JournalVoucher::post(
            ['date' => '2026-03-01', 'narration' => 'Cash sale'],
            [
                ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 1000],
            ],
            $actor,
        );

        $fy1->close($fy2, $actor, CLOSING_TEST_REASON);

        // A stale in-memory copy of FY1 (exactly what a double-clicked form
        // submits) must not be able to close it again: the status is
        // re-checked inside the transaction, under the row lock.
        $stale = FiscalYear::withoutEvents(fn () => FiscalYear::find($fy1->id));
        $stale->status = FiscalYearStatus::Open;

        expect(fn () => $stale->close(FiscalYear::find($fy2->id), $actor, CLOSING_TEST_REASON))
            ->toThrow(InvalidArgumentException::class);

        expect(JournalVoucher::where('fiscal_year_id', $fy1->id)->where('voucher_type', VoucherType::ClosingEntry)->count())->toBe(1)
            ->and(JournalVoucher::where('fiscal_year_id', $fy2->id)->where('voucher_type', VoucherType::OpeningBalance)->count())->toBe(1);
    });

    $tenant->delete();
});

test('a next fiscal year that starts on or before this one ends is rejected', function () {
    $tenant = provisionClosingTestTenant('fy-close-overlap.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        // Deliberately EARLIER than FY1: an opening-balance voucher dated
        // here would restate balances inside a period FY1 still owns.
        $earlier = FiscalYear::create(['name' => 'FY0', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => FiscalYearStatus::Closed]);
        $actor = closingTestAdmin();

        expect(fn () => $fy1->close($earlier, $actor, CLOSING_TEST_REASON))
            ->toThrow(InvalidArgumentException::class);

        expect($fy1->fresh()->status)->toBe(FiscalYearStatus::Open)
            ->and(JournalVoucher::where('fiscal_year_id', $earlier->id)->count())->toBe(0);
    });

    $tenant->delete();
});

test('closing a fiscal year before it has ended needs an admin and a written reason', function () {
    $tenant = provisionClosingTestTenant('fy-close-early.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2099-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2100-01-01', 'end_date' => '2100-12-31', 'status' => FiscalYearStatus::Closed]);
        $staff = User::factory()->create(['email' => 'staff@example.com']);
        $admin = closingTestAdmin();

        // No reason at all.
        expect(fn () => $fy1->close($fy2, $admin))->toThrow(InvalidArgumentException::class);
        expect(fn () => $fy1->close($fy2, $admin, '   '))->toThrow(InvalidArgumentException::class);

        // A reason, but from someone who is not an admin.
        expect(fn () => $fy1->close($fy2, $staff, CLOSING_TEST_REASON))->toThrow(AuthorizationException::class);

        expect($fy1->fresh()->status)->toBe(FiscalYearStatus::Open);

        // Admin plus reason goes through, and the reason is kept on record.
        $fy1->close($fy2, $admin, CLOSING_TEST_REASON);

        expect($fy1->fresh()->status)->toBe(FiscalYearStatus::Closed)
            ->and($fy1->fresh()->close_reason)->toBe(CLOSING_TEST_REASON);
    });

    $tenant->delete();
});

test('a tenant with stale is_profit_and_loss flags gets backfilled and closes correctly', function () {
    // Reproduces a real incident, found via an HTTP-driven smoke test, not
    // by any Pest test: 2026_08_25_100009_add_is_profit_and_loss_to_account_heads_table
    // added the column with a schema default of false and no backfill, so a
    // tenant provisioned before that column existed was left with
    // is_profit_and_loss=false on every head forever. FiscalYear::close()
    // then finds no profit-and-loss accounts to sweep, "succeeds", and
    // silently does nothing - no ClosingEntry, no net-profit sweep into
    // Profit & Loss, leaving a closed year's Balance Sheet unbalanced.
    // RefreshDatabase always seeds a fresh tenant with the correct flags
    // already set, so this scenario is invisible unless the stale state is
    // reproduced manually. 2026_08_29_100200_backfill_is_profit_and_loss_on_account_heads_table
    // is the fix under test here.
    $tenant = provisionClosingTestTenant('fy-close-backfill.tenant-test');

    $tenant->run(function () {
        AccountHead::whereIn('name', ['Income', 'Expenses'])->update(['is_profit_and_loss' => false]);

        (require database_path('migrations/tenant/2026_08_29_100200_backfill_is_profit_and_loss_on_account_heads_table.php'))->up();

        expect(AccountHead::where('name', 'Income')->value('is_profit_and_loss'))->toBeTrue()
            ->and(AccountHead::where('name', 'Expenses')->value('is_profit_and_loss'))->toBeTrue();

        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $actor = closingTestAdmin();

        $cash = Account::where('code', 'AS1')->firstOrFail();
        $sales = Account::where('code', 'INI20')->firstOrFail();
        $pl = Account::where('name', 'Profit & Loss')->firstOrFail();

        JournalVoucher::post(
            ['date' => '2026-03-01', 'narration' => 'Cash sale'],
            [
                ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 1000],
            ],
            $actor,
        );

        $fy1->close($fy2, $actor, CLOSING_TEST_REASON);

        $closingEntry = JournalVoucher::where('fiscal_year_id', $fy1->id)
            ->where('voucher_type', VoucherType::ClosingEntry)
            ->first();

        expect($closingEntry)->not->toBeNull();

        expect(closingTestNet($pl->id, $fy1->id))->toBe('-1000.00');
    });

    $tenant->delete();
});
