<?php

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\AccountHead;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
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

test('closing a fiscal year that is not open is rejected', function () {
    $tenant = provisionClosingTestTenant('fy-close-not-open.tenant-test');

    $tenant->run(function () {
        $closed = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Closed]);
        $next = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);

        expect(fn () => $closed->close($next, $actor))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('closing a fiscal year sweeps profit-and-loss accounts to zero and carries balance-sheet accounts forward', function () {
    $tenant = provisionClosingTestTenant('fy-close-full.tenant-test');

    $tenant->run(function () {
        $fy1 = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $fy2 = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Closed]);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);

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

        $fy1->close($fy2, $actor);

        expect($fy1->fresh()->status)->toBe(FiscalYearStatus::Closed)
            ->and($fy2->fresh()->status)->toBe(FiscalYearStatus::Open);

        // Sales and Purchases are fully zeroed within FY1 once the closing
        // entries are included.
        $sumWithinFy1 = fn (Account $account) => JournalVoucherLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalVoucher', fn ($q) => $q->where('fiscal_year_id', $fy1->id))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');

        expect((float) $sumWithinFy1($sales))->toBe(0.0)
            ->and((float) $sumWithinFy1($purchases))->toBe(0.0);

        // Net profit (1000 - 400 = 600) lands as a credit to Profit & Loss
        // within FY1's own closing entries - net = debit - credit, so a
        // pure credit balance is negative here.
        expect((float) $sumWithinFy1($pl))->toBe(-600.0);

        $closingEntry = JournalVoucher::where('fiscal_year_id', $fy1->id)
            ->where('voucher_type', VoucherType::ClosingEntry)
            ->firstOrFail();
        expect($closingEntry->lines)->toHaveCount(3);

        // FY2's opening balances: Cash carries forward its 600 debit
        // balance (1000 debit - 400 credit), Profit & Loss carries forward
        // its 600 credit balance - the two lines balance each other.
        $opening = JournalVoucher::where('fiscal_year_id', $fy2->id)
            ->where('voucher_type', VoucherType::OpeningBalance)
            ->firstOrFail();

        $linesByAccount = $opening->lines->keyBy('account_id');

        expect((float) $linesByAccount[$cash->id]->debit)->toBe(600.0)
            ->and((float) $linesByAccount[$cash->id]->credit)->toBe(0.0)
            ->and((float) $linesByAccount[$pl->id]->credit)->toBe(600.0)
            ->and((float) $linesByAccount[$pl->id]->debit)->toBe(0.0);
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
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);

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

        $fy1->close($fy2, $actor);

        $closingEntry = JournalVoucher::where('fiscal_year_id', $fy1->id)
            ->where('voucher_type', VoucherType::ClosingEntry)
            ->first();

        expect($closingEntry)->not->toBeNull();

        $plNet = JournalVoucherLine::query()
            ->where('account_id', $pl->id)
            ->whereHas('journalVoucher', fn ($q) => $q->where('fiscal_year_id', $fy1->id))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');

        expect((float) $plNet)->toBe(-1000.0);
    });

    $tenant->delete();
});
