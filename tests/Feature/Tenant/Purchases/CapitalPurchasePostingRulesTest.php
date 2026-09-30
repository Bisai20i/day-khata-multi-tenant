<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CapitalPurchase;
use App\Models\CapitalPurchaseSettlement;
use App\Models\FiscalYear;
use App\Models\JournalVoucherLine;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\BillingException;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Flags G-03 (capital VAT rate is the company rate or 0), G-04 (a cash or
 * bank capital bill with a supplier posts the supplier pair, decision D4)
 * and G-23 (settlements are in the activity log).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionCapitalRulesTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function capitalRulesSetup(): User
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

    return User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
}

function capitalRulesPost(User $actor, array $header, string $amount = '1000'): CapitalPurchase
{
    return CapitalPurchase::post(
        array_merge(['type' => 'capital', 'date' => '2026-06-01', 'payment_mode' => 'cash'], $header),
        [['account_id' => Account::factory()->create()->id, 'amount' => $amount, 'vatable' => true]],
        $actor,
    );
}

test('a capital bill takes the company VAT rate or none, and nothing in between', function () {
    $tenant = provisionCapitalRulesTenant('capital-vat-rate.tenant-test');

    $tenant->run(function () {
        $actor = capitalRulesSetup();

        expect(capitalRulesPost($actor, ['vat_rate' => '13'])->vat_amount)->toBe('130.00')
            ->and(capitalRulesPost($actor, ['vat_rate' => '0'])->vat_amount)->toBe('0.00')
            ->and(fn () => capitalRulesPost($actor, ['vat_rate' => '100']))->toThrow(InvalidArgumentException::class, 'company rate')
            ->and(fn () => capitalRulesPost($actor, ['vat_rate' => '5']))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('the store route rejects any VAT rate other than the company rate or zero', function () {
    $domain = 'capital-vat-rate-http.tenant-test';
    $tenant = provisionCapitalRulesTenant($domain);

    $accountId = null;
    $tenant->run(function () use (&$accountId) {
        capitalRulesSetup();
        $accountId = Account::factory()->create()->id;
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/capital-purchases", [
        'type' => 'capital',
        'date' => '2026-06-01',
        'payment_mode' => 'cash',
        'vat_rate' => '100',
        'lines' => [['account_id' => $accountId, 'amount' => '1000', 'vatable' => true]],
    ])->assertSessionHasErrors('vat_rate');

    $tenant->run(function () {
        expect(CapitalPurchase::count())->toBe(0);
    });

    $tenant->delete();
});

test('a cash or bank capital bill with a supplier shows the bill and its payment on the supplier ledger', function () {
    $tenant = provisionCapitalRulesTenant('capital-supplier-pair.tenant-test');

    $tenant->run(function () {
        $actor = capitalRulesSetup();
        $supplier = Supplier::factory()->create();
        $bank = Account::factory()->create();

        foreach ([['payment_mode' => 'cash'], ['payment_mode' => 'bank', 'bank_account_id' => $bank->id]] as $mode) {
            $purchase = capitalRulesPost($actor, [...$mode, 'supplier_id' => $supplier->id, 'vat_rate' => '0']);
            $lines = JournalVoucherLine::where('journal_voucher_id', $purchase->journal_voucher_id)->get();
            $supplierLines = $lines->where('account_id', $supplier->account_id);

            expect($supplierLines)->toHaveCount(2)
                ->and(Money::sum($supplierLines->map(fn ($l) => Money::of($l->credit)))->toString())->toBe('1000.00')
                ->and(Money::sum($supplierLines->map(fn ($l) => Money::of($l->debit)))->toString())->toBe('1000.00')
                ->and(Money::sum($lines->map(fn ($l) => Money::of($l->debit)))->toString())
                ->toBe(Money::sum($lines->map(fn ($l) => Money::of($l->credit)))->toString())
                ->and($purchase->outstandingAmount()->toString())->toBe('0.00');
        }

        // No supplier: the payment account is credited directly, as before.
        $anonymous = capitalRulesPost($actor, ['vat_rate' => '0']);
        expect(JournalVoucherLine::where('journal_voucher_id', $anonymous->journal_voucher_id)->count())->toBe(2);
    });

    $tenant->delete();
});

test('a capital purchase settlement is written to the activity log', function () {
    $tenant = provisionCapitalRulesTenant('capital-settlement-activity.tenant-test');

    $tenant->run(function () {
        $actor = capitalRulesSetup();
        $purchase = capitalRulesPost($actor, ['payment_mode' => 'credit', 'supplier_id' => Supplier::factory()->create()->id, 'vat_rate' => '0']);

        $settlement = CapitalPurchaseSettlement::settle($purchase, ['date' => '2026-06-10', 'amount' => '400', 'payment_mode' => 'cash'], $actor);

        expect(ActivityLog::where('subject_type', $settlement->getMorphClass())
            ->where('subject_id', $settlement->id)
            ->where('action', 'created')
            ->exists())->toBeTrue();
    });

    $tenant->delete();
});

test('a capital line amount with more than two decimals is refused rather than rounded', function () {
    $tenant = provisionCapitalRulesTenant('capital-amount-decimals.tenant-test');

    $tenant->run(function () {
        $actor = capitalRulesSetup();

        expect(fn () => capitalRulesPost($actor, ['vat_rate' => '0'], '100.005'))
            ->toThrow(BillingException::class, 'Line 1 amount');
    });

    $tenant->delete();
});
