<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\Agent;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * SAL-07 (flags/sales.md): a sales return claws back the agent's commission
 * on the returned goods, pro-rated by value, inside the credit-note voucher.
 * The running total is anchored cumulatively, so the return that completes
 * the sale takes exactly the remainder and the total reversed never exceeds
 * the commission (CONTRACTS C6 addendum).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function salesReturnCommissionTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function salesReturnCommissionOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

/**
 * @param  array<int, array<string, mixed>>  $lines
 */
function salesReturnCommissionSale(User $actor, Customer $customer, array $lines, ?Agent $agent = null, string $commission = '0'): Sale
{
    return Sale::post(
        [
            'customer_id' => $customer->id,
            'invoice_type' => 'full',
            'date' => '2026-06-01',
            'payment_mode' => 'credit',
            'agent_id' => $agent?->id,
            'commission_amount' => $commission,
        ],
        $lines,
        $actor,
    );
}

function salesReturnCommissionPost(User $actor, Sale $sale, int $saleLineId, string $quantity): SalesReturn
{
    return SalesReturn::post(
        ['sale_id' => $sale->id, 'date' => '2026-06-05', 'reason' => null],
        [['sale_line_id' => $saleLineId, 'quantity' => $quantity]],
        $actor,
    );
}

function salesReturnCommissionExpenseId(): int
{
    return Account::where('code', 'EXE22')->firstOrFail()->id;
}

/**
 * Debits minus credits on an account, across every voucher (reversals
 * included, as the ledger reports read it).
 */
function salesReturnCommissionNet(int $accountId): string
{
    $lines = JournalVoucherLine::where('account_id', $accountId)->get(['debit', 'credit']);

    return Money::sum($lines->pluck('debit'))->minus(Money::sum($lines->pluck('credit')))->toString();
}

/**
 * The commission this return's own credit-note voucher clawed back, after
 * checking that the agent leg mirrors the EXE22 leg exactly.
 */
function salesReturnCommissionReversed(SalesReturn $salesReturn, Agent $agent): string
{
    $lines = JournalVoucherLine::where('journal_voucher_id', $salesReturn->journal_voucher_id)->get();

    $expenseCredit = Money::sum($lines->where('account_id', salesReturnCommissionExpenseId())->pluck('credit'));
    $agentDebit = Money::sum($lines->where('account_id', $agent->account_id)->pluck('debit'));

    expect($agentDebit->toString())->toBe($expenseCredit->toString());

    return $expenseCredit->toString();
}

function salesReturnCommissionAssertBalanced(): void
{
    foreach (JournalVoucher::with('lines')->get() as $voucher) {
        expect(Money::sum($voucher->lines->pluck('debit'))->toString())
            ->toBe(Money::sum($voucher->lines->pluck('credit'))->toString());
    }
}

test('SAL-07 a partial return reverses its pro-rated share of the commission in the credit note', function () {
    $tenant = salesReturnCommissionTenant('sal07-partial.tenant-test');

    $tenant->run(function () {
        salesReturnCommissionOpenFiscalYear();
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $agent = Agent::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = salesReturnCommissionSale($admin, $customer, [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]], $agent, '100.00');
        $saleLine = $sale->lines()->firstOrFail();

        // 300.00 of a 1,000.00 sale: 30% of the 100.00 commission.
        $return = salesReturnCommissionPost($admin, $sale, $saleLine->id, '3');

        expect((string) $return->total)->toBe('300.00')
            ->and(salesReturnCommissionReversed($return, $agent))->toBe('30.00')
            ->and(salesReturnCommissionNet(salesReturnCommissionExpenseId()))->toBe('70.00')
            ->and(salesReturnCommissionNet($agent->account_id))->toBe('-70.00')
            // The customer is credited the return total only; commission never touches them.
            ->and(salesReturnCommissionNet($customer->account_id))->toBe('700.00');

        salesReturnCommissionAssertBalanced();
    });

    $tenant->delete();
});

test('SAL-07 two partial returns and the final one reverse exactly the whole commission', function () {
    $tenant = salesReturnCommissionTenant('sal07-remainder.tenant-test');

    $tenant->run(function () {
        salesReturnCommissionOpenFiscalYear();
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $agent = Agent::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);

        // 3 x 100 + 13% VAT = 339.00; each unit returns 113.00 (a third, which does not divide 10.00).
        $sale = salesReturnCommissionSale($admin, $customer, [['item_id' => $item->id, 'quantity' => 3, 'rate' => 100, 'discount' => 0]], $agent, '10.00');
        $saleLine = $sale->lines()->firstOrFail();

        $first = salesReturnCommissionPost($admin, $sale, $saleLine->id, '1');
        $second = salesReturnCommissionPost($admin, $sale, $saleLine->id, '1');
        $final = salesReturnCommissionPost($admin, $sale, $saleLine->id, '1');

        // round(10 x 1/3) = 3.33, round(10 x 2/3) - 3.33 = 3.34, final takes 10.00 - 6.67.
        expect(salesReturnCommissionReversed($first, $agent))->toBe('3.33')
            ->and(salesReturnCommissionReversed($second, $agent))->toBe('3.34')
            ->and(salesReturnCommissionReversed($final, $agent))->toBe('3.33')
            ->and(salesReturnCommissionNet(salesReturnCommissionExpenseId()))->toBe('0.00')
            ->and(salesReturnCommissionNet($agent->account_id))->toBe('0.00');

        salesReturnCommissionAssertBalanced();
    });

    $tenant->delete();
});

test('SAL-07 many tiny returns never reverse more than the commission', function () {
    $tenant = salesReturnCommissionTenant('sal07-never-exceeds.tenant-test');

    $tenant->run(function () {
        salesReturnCommissionOpenFiscalYear();
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $agent = Agent::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        // Each 0.01 return is worth exactly half a paisa of commission: rounding each one up on
        // its own would reverse 0.19 of a 0.10 commission before the last unit came back.
        $sale = salesReturnCommissionSale($admin, $customer, [['item_id' => $item->id, 'quantity' => 20, 'rate' => '0.01', 'discount' => 0]], $agent, '0.10');
        $saleLine = $sale->lines()->firstOrFail();

        $reversed = Money::zero();

        for ($unit = 1; $unit <= 20; $unit++) {
            $return = salesReturnCommissionPost($admin, $sale, $saleLine->id, '1');
            $reversed = $reversed->plus(salesReturnCommissionReversed($return, $agent));

            expect($reversed->isLessThanOrEqualTo(Money::of('0.10')))->toBeTrue();
        }

        expect($reversed->toString())->toBe('0.10')
            ->and(salesReturnCommissionNet(salesReturnCommissionExpenseId()))->toBe('0.00')
            ->and(salesReturnCommissionNet($agent->account_id))->toBe('0.00');

        salesReturnCommissionAssertBalanced();
    });

    $tenant->delete();
});

test('SAL-07 cancelling a return restores its commission share and the next return re-anchors', function () {
    $tenant = salesReturnCommissionTenant('sal07-cancel.tenant-test');

    $tenant->run(function () {
        salesReturnCommissionOpenFiscalYear();
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $agent = Agent::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = salesReturnCommissionSale($admin, $customer, [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]], $agent, '100.00');
        $saleLine = $sale->lines()->firstOrFail();

        $return = salesReturnCommissionPost($admin, $sale, $saleLine->id, '3');
        expect(salesReturnCommissionReversed($return, $agent))->toBe('30.00');

        $return->cancel($admin, 'Entered in error');

        // The reversal voucher mirrors the commission legs with everything else.
        expect(salesReturnCommissionNet(salesReturnCommissionExpenseId()))->toBe('100.00')
            ->and(salesReturnCommissionNet($agent->account_id))->toBe('-100.00');

        // The cancelled return no longer counts: returning everything takes the whole commission.
        $full = salesReturnCommissionPost($admin, $sale, $saleLine->id, '10');

        expect(salesReturnCommissionReversed($full, $agent))->toBe('100.00')
            ->and(salesReturnCommissionNet(salesReturnCommissionExpenseId()))->toBe('0.00')
            ->and(salesReturnCommissionNet($agent->account_id))->toBe('0.00');

        salesReturnCommissionAssertBalanced();
    });

    $tenant->delete();
});

test('SAL-07 a sale cannot be cancelled over live returns, and cancelling returns first nets the commission once', function () {
    $tenant = salesReturnCommissionTenant('sal07-sale-cancel.tenant-test');

    $tenant->run(function () {
        salesReturnCommissionOpenFiscalYear();
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $agent = Agent::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = salesReturnCommissionSale($admin, $customer, [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]], $agent, '100.00');
        $saleLine = $sale->lines()->firstOrFail();

        $return = salesReturnCommissionPost($admin, $sale, $saleLine->id, '4');

        expect(fn () => $sale->cancel($admin, 'Wrong customer'))->toThrow(InvalidArgumentException::class);

        $return->cancel($admin, 'Entered in error');
        $sale->cancel($admin, 'Wrong customer');

        // Commission booked once and reversed once - no double clawback.
        expect(salesReturnCommissionNet(salesReturnCommissionExpenseId()))->toBe('0.00')
            ->and(salesReturnCommissionNet($agent->account_id))->toBe('0.00')
            ->and(salesReturnCommissionNet($customer->account_id))->toBe('0.00');

        salesReturnCommissionAssertBalanced();
    });

    $tenant->delete();
});

test('SAL-07 a return of a sale without an agent posts no commission lines', function () {
    $tenant = salesReturnCommissionTenant('sal07-no-agent.tenant-test');

    $tenant->run(function () {
        salesReturnCommissionOpenFiscalYear();
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = salesReturnCommissionSale($admin, $customer, [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]]);
        $saleLine = $sale->lines()->firstOrFail();

        $return = salesReturnCommissionPost($admin, $sale, $saleLine->id, '10');
        $lines = JournalVoucherLine::where('journal_voucher_id', $return->journal_voucher_id)->get();

        // Exactly the pre-SAL-07 shape: revenue debit and customer credit.
        expect($lines)->toHaveCount(2)
            ->and($lines->where('account_id', salesReturnCommissionExpenseId()))->toHaveCount(0)
            ->and(salesReturnCommissionNet(salesReturnCommissionExpenseId()))->toBe('0.00');

        salesReturnCommissionAssertBalanced();
    });

    $tenant->delete();
});

test('SAL-07 an unlinked return never touches commission', function () {
    $tenant = salesReturnCommissionTenant('sal07-unlinked.tenant-test');

    $tenant->run(function () {
        salesReturnCommissionOpenFiscalYear();
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $agent = Agent::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        // The same customer has a commissioned sale, but an unlinked return names no sale.
        salesReturnCommissionSale($admin, $customer, [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]], $agent, '100.00');

        $return = SalesReturn::postUnlinked(
            ['customer_id' => $customer->id, 'date' => '2026-06-05', 'reason' => 'Pre-cutover sale'],
            [['item_id' => $item->id, 'quantity' => '3', 'rate' => '100.00']],
            $admin,
        );

        expect(JournalVoucherLine::where('journal_voucher_id', $return->journal_voucher_id)->where('account_id', salesReturnCommissionExpenseId())->exists())->toBeFalse()
            ->and(salesReturnCommissionNet(salesReturnCommissionExpenseId()))->toBe('100.00')
            ->and(salesReturnCommissionNet($agent->account_id))->toBe('-100.00');

        salesReturnCommissionAssertBalanced();
    });

    $tenant->delete();
});

test('SAL-07 a return request reverses commission only when it is approved', function () {
    $tenant = salesReturnCommissionTenant('sal07-request.tenant-test');

    $tenant->run(function () {
        salesReturnCommissionOpenFiscalYear();
        $admin = User::factory()->create();
        $approver = User::factory()->create();
        $customer = Customer::factory()->create();
        $agent = Agent::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = salesReturnCommissionSale($admin, $customer, [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]], $agent, '100.00');
        $saleLine = $sale->lines()->firstOrFail();

        $request = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-05', 'reason' => 'Damaged'],
            [['sale_line_id' => $saleLine->id, 'quantity' => '5']],
            $admin,
        );

        // Pending: no voucher, no commission effect.
        expect($request->journal_voucher_id)->toBeNull()
            ->and(salesReturnCommissionNet(salesReturnCommissionExpenseId()))->toBe('100.00');

        // A direct return of the other half is not "final" while the request is only pending.
        $direct = salesReturnCommissionPost($admin, $sale, $saleLine->id, '5');
        expect(salesReturnCommissionReversed($direct, $agent))->toBe('50.00');

        $posted = $request->approve($approver);

        expect(salesReturnCommissionReversed($posted, $agent))->toBe('50.00')
            ->and(salesReturnCommissionNet(salesReturnCommissionExpenseId()))->toBe('0.00')
            ->and(salesReturnCommissionNet($agent->account_id))->toBe('0.00');

        salesReturnCommissionAssertBalanced();
    });

    $tenant->delete();
});

test('SAL-07 commission is clawed back from an inactive or deleted agent account', function () {
    $tenant = salesReturnCommissionTenant('sal07-agent-gone.tenant-test');

    $tenant->run(function () {
        salesReturnCommissionOpenFiscalYear();
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $agent = Agent::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = salesReturnCommissionSale($admin, $customer, [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]], $agent, '100.00');
        $saleLine = $sale->lines()->firstOrFail();

        $agent->update(['is_active' => false]);
        $inactive = salesReturnCommissionPost($admin, $sale, $saleLine->id, '2');
        expect(salesReturnCommissionReversed($inactive, $agent))->toBe('20.00');

        // The agent row goes (sales.agent_id is nullOnDelete); its ledger account stays.
        $agent->delete();
        $deleted = salesReturnCommissionPost($admin, $sale, $saleLine->id, '8');

        expect(salesReturnCommissionReversed($deleted, $agent))->toBe('80.00')
            ->and(salesReturnCommissionNet(salesReturnCommissionExpenseId()))->toBe('0.00')
            ->and(salesReturnCommissionNet($agent->account_id))->toBe('0.00');

        salesReturnCommissionAssertBalanced();
    });

    $tenant->delete();
});

test('SAL-07 a sale with a negative line pro-rates commission on the stored invoice total', function () {
    $tenant = salesReturnCommissionTenant('sal07-negative-line.tenant-test');

    $tenant->run(function () {
        salesReturnCommissionOpenFiscalYear();
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $agent = Agent::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        // 1,000.00 - 200.00 = 800.00 invoice (SAL-03); only the positive line is returnable.
        $sale = salesReturnCommissionSale($admin, $customer, [
            ['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0],
            ['item_id' => $item->id, 'quantity' => -2, 'rate' => 100, 'discount' => 0],
        ], $agent, '80.00');
        $positive = $sale->lines()->get()->first(fn ($line) => Money::of($line->line_total)->isPositive());

        $half = salesReturnCommissionPost($admin, $sale, $positive->id, '5');
        expect((string) $half->total)->toBe('400.00')
            ->and(salesReturnCommissionReversed($half, $agent))->toBe('40.00');

        $rest = salesReturnCommissionPost($admin, $sale, $positive->id, '5');
        expect(salesReturnCommissionReversed($rest, $agent))->toBe('40.00')
            ->and(salesReturnCommissionNet(salesReturnCommissionExpenseId()))->toBe('0.00')
            ->and(salesReturnCommissionNet($agent->account_id))->toBe('0.00');

        salesReturnCommissionAssertBalanced();
    });

    $tenant->delete();
});
