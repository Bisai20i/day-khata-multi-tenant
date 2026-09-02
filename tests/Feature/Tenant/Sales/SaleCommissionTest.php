<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\Agent;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucherLine;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

function provisionCommissionTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function commissionTestAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

function commissionTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function commissionTestExpenseAccount(): Account
{
    return Account::where('code', 'EXE22')->firstOrFail();
}

function commissionTestAccountNetBalance(int $accountId): float
{
    return (float) JournalVoucherLine::query()
        ->where('account_id', $accountId)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
        ->value('net');
}

test('a sale with an agent and commission posts a balanced voucher with the extra commission lines', function () {
    $tenant = provisionCommissionTestTenant('sale-commission-balanced.tenant-test');

    $tenant->run(function () {
        commissionTestOpenFiscalYear();
        $admin = commissionTestAdmin();
        $customer = Customer::factory()->create();
        $agent = Agent::factory()->create(['commission_rate' => 5]);
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $sale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'agent_id' => $agent->id,
                'commission_amount' => 10,
            ],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        expect((float) $sale->total)->toBe(226.0)
            ->and($sale->agent_id)->toBe($agent->id)
            ->and((float) $sale->commission_amount)->toBe(10.0);

        $lines = $sale->journalVoucher->lines;
        $totalDebit = (float) $lines->sum('debit');
        $totalCredit = (float) $lines->sum('credit');
        expect($totalDebit)->toBe($totalCredit);

        $commissionExpenseLine = $lines->firstWhere('account_id', commissionTestExpenseAccount()->id);
        expect($commissionExpenseLine)->not->toBeNull()
            ->and((float) $commissionExpenseLine->debit)->toBe(10.0)
            ->and((float) $commissionExpenseLine->credit)->toBe(0.0);

        $agentLine = $lines->firstWhere('account_id', $agent->account_id);
        expect($agentLine)->not->toBeNull()
            ->and((float) $agentLine->credit)->toBe(10.0)
            ->and((float) $agentLine->debit)->toBe(0.0);

        expect(commissionTestAccountNetBalance(commissionTestExpenseAccount()->id))->toBe(10.0)
            ->and(commissionTestAccountNetBalance($agent->account_id))->toBe(-10.0);
    });

    $tenant->delete();
});

test('the commission lines do not affect the customer own debit/settlement math', function () {
    $tenant = provisionCommissionTestTenant('sale-commission-customer-unaffected.tenant-test');

    $tenant->run(function () {
        commissionTestOpenFiscalYear();
        $admin = commissionTestAdmin();
        $customer = Customer::factory()->create();
        $agent = Agent::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        // Cash sale without commission - customer nets to zero, cash account
        // receives the full settlement.
        $withoutCommission = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        expect((float) $withoutCommission->total)->toBe(100.0);

        // Same shape, but with an agent + commission attached - the
        // customer's own net balance and settlement amount must be
        // identical to the no-commission case, since commission is a
        // separate, independent expense/payable pair.
        $withCommission = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-02',
                'payment_mode' => 'cash',
                'agent_id' => $agent->id,
                'commission_amount' => 15,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        expect((float) $withCommission->total)->toBe(100.0);

        $cashAccount = Account::where('code', 'AS1')->firstOrFail();
        $customerDebitLine = $withCommission->journalVoucher->lines
            ->where('account_id', $customer->account_id)
            ->firstWhere('debit', '>', 0);
        $customerCreditLine = $withCommission->journalVoucher->lines
            ->where('account_id', $customer->account_id)
            ->firstWhere('credit', '>', 0);

        expect((float) $customerDebitLine->debit)->toBe(100.0)
            ->and((float) $customerCreditLine->credit)->toBe(100.0);

        // Customer's net balance across both sales is still zero (cash sale
        // settles in full each time) - commission never touches this side.
        expect(commissionTestAccountNetBalance($customer->account_id))->toBe(0.0);

        // Cash account received the full 100 settlement for the commissioned
        // sale too - commission did not reduce what the customer actually paid.
        $cashLineForCommissionedSale = $withCommission->journalVoucher->lines
            ->where('account_id', $cashAccount->id)
            ->firstWhere('debit', '>', 0);
        expect((float) $cashLineForCommissionedSale->debit)->toBe(100.0);
    });

    $tenant->delete();
});

test('a sale with no agent posts exactly as before, with no commission lines', function () {
    $tenant = provisionCommissionTestTenant('sale-commission-none.tenant-test');

    $tenant->run(function () {
        commissionTestOpenFiscalYear();
        $admin = commissionTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        expect($sale->agent_id)->toBeNull()
            ->and((float) $sale->commission_amount)->toBe(0.0);

        expect($sale->journalVoucher->lines()->where('account_id', commissionTestExpenseAccount()->id)->count())->toBe(0);

        $lines = $sale->journalVoucher->lines;
        expect((float) $lines->sum('debit'))->toBe((float) $lines->sum('credit'));
    });

    $tenant->delete();
});

test('a negative commission amount is rejected', function () {
    $tenant = provisionCommissionTestTenant('sale-commission-negative.tenant-test');

    $tenant->run(function () {
        commissionTestOpenFiscalYear();
        $admin = commissionTestAdmin();
        $customer = Customer::factory()->create();
        $agent = Agent::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        expect(fn () => Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'agent_id' => $agent->id,
                'commission_amount' => -5,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a negative commission amount is rejected through the HTTP store route', function () {
    $domain = 'sale-commission-negative-http.tenant-test';
    $tenant = provisionCommissionTestTenant($domain);

    $customerId = null;
    $itemId = null;
    $agentId = null;
    $tenant->run(function () use (&$customerId, &$itemId, &$agentId) {
        User::factory()->create(['email' => 'owner@example.com']);
        commissionTestOpenFiscalYear();
        $customerId = Customer::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true])->id;
        $agentId = Agent::factory()->create()->id;
    });

    $this->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);

    $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'full',
        'date' => '2026-06-01',
        'payment_mode' => 'cash',
        'agent_id' => $agentId,
        'commission_amount' => -5,
        'lines' => [
            ['item_id' => $itemId, 'quantity' => 1, 'rate' => 100],
        ],
    ])->assertSessionHasErrors('commission_amount');

    $tenant->delete();
});
