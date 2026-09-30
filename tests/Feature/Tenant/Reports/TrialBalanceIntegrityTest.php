<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Flags G-08 (an account with no head or group is shown as Unclassified, not
 * dropped) and G-21 (a bad year or date window is a validation error).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionTrialBalanceIntegrityTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

test('an account that lost its group still shows on the trial balance, under Unclassified, and the totals still balance', function () {
    $domain = 'trial-balance-unclassified.tenant-test';
    $tenant = provisionTrialBalanceIntegrityTenant($domain);

    $fyId = null;
    $tenant->run(function () use (&$fyId) {
        $admin = User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        $fyId = FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open])->id;
        $orphan = Account::create(['account_group_id' => AccountGroup::first()->id, 'name' => 'Orphaned Ledger', 'code' => 'ORPH1']);

        JournalVoucher::post(
            ['date' => '2026-03-01', 'narration' => 'Into the orphan'],
            [
                ['account_id' => Account::where('code', 'AS1')->value('id'), 'debit' => 0, 'credit' => 250],
                ['account_id' => $orphan->id, 'debit' => 250, 'credit' => 0],
            ],
            $admin,
        );

        // A broken import or a hand-edited row: the account points at no
        // group at all. Written past the model on purpose.
        DB::table('accounts')->where('id', $orphan->id)->update(['account_group_id' => null, 'account_subgroup_id' => null]);
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/reports/trial-balance?fiscal_year_id={$fyId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('unclassifiedCount', 1)
            ->where('inBalance', true)
            ->where('heads', function (Collection $heads) {
                $unclassified = $heads->firstWhere('name', 'Unclassified');

                return $unclassified !== null
                    && $unclassified['groups'][0]['accounts'][0]['code'] === 'ORPH1'
                    && $unclassified['groups'][0]['accounts'][0]['closingDebit'] === '250.00';
            }));

    $tenant->delete();
});

test('a trial balance with every account filed has no Unclassified warning', function () {
    $domain = 'trial-balance-classified.tenant-test';
    $tenant = provisionTrialBalanceIntegrityTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/reports/trial-balance")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('unclassifiedCount', 0));

    $tenant->delete();
});

test('an unknown fiscal year or a backwards date window is a validation error on reports and ledgers', function () {
    $domain = 'report-input-validation.tenant-test';
    $tenant = provisionTrialBalanceIntegrityTenant($domain);

    $cashId = null;
    $tenant->run(function () use (&$cashId) {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $cashId = Account::where('code', 'AS1')->value('id');
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->get("http://{$domain}/reports/trial-balance?fiscal_year_id=999999")->assertSessionHasErrors('fiscal_year_id');
    $this->get("http://{$domain}/reports/trial-balance?from=2026-06-30&to=2026-06-01")->assertSessionHasErrors('to');
    $this->get("http://{$domain}/reports/balance-sheet?to=not-a-date")->assertSessionHasErrors('to');
    $this->get("http://{$domain}/accounts/{$cashId}/ledger?fiscal_year_id=999999")->assertSessionHasErrors('fiscal_year_id');
    $this->get("http://{$domain}/accounts/{$cashId}/ledger?from=2026-06-30&to=2026-06-01")->assertSessionHasErrors('to');

    // A sensible request is untouched.
    $this->get("http://{$domain}/reports/trial-balance?from=2026-06-01&to=2026-06-30")->assertOk();

    $tenant->delete();
});
