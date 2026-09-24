<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Regression tests for the sales audit P1 flags SAL-01 to SAL-04
 * (flags/sales.md): cancelled sales in the totals row, receipt bank account
 * restriction, returns against a sale with a negative line, and the role and
 * maker/checker gates on unlinked returns and approve/reject.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function salesP1Tenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function salesP1OpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function salesP1CreditSale(User $actor, Customer $customer, array $lines, string $date = '2026-06-01'): Sale
{
    return Sale::post(
        ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => $date, 'payment_mode' => 'credit'],
        $lines,
        $actor,
    );
}

function salesP1Login(string $domain, string $email): void
{
    test()->post("http://{$domain}/login", ['email' => $email, 'password' => 'password']);
}

test('SAL-01 a cancelled sale does not move the sales list totals row', function () {
    $domain = 'sal01-totals.tenant-test';
    $tenant = salesP1Tenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        salesP1OpenFiscalYear();
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        salesP1CreditSale($admin, $customer, [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]]);
        $cancelled = salesP1CreditSale($admin, $customer, [['item_id' => $item->id, 'quantity' => 1, 'rate' => 700, 'discount' => 0]]);
        $cancelled->cancel($admin, 'Entered in error');
    });

    salesP1Login($domain, 'owner@example.com');

    $this->get("http://{$domain}/sales")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.total', '100.00'));

    $tenant->delete();
});

test('SAL-02 a receipt cannot use a non-money account as its bank account', function () {
    $domain = 'sal02-bank.tenant-test';
    $tenant = salesP1Tenant($domain);

    $customerId = null;
    $customerAccountId = null;
    $bankId = null;
    $tenant->run(function () use (&$customerId, &$customerAccountId, &$bankId) {
        User::factory()->create(['email' => 'owner@example.com']);
        salesP1OpenFiscalYear();
        $customer = Customer::factory()->create();
        $customerId = $customer->id;
        $customerAccountId = $customer->account_id;
        $bank = Account::factory()->create([
            'account_group_id' => AccountGroup::where('name', 'Current Assets')->firstOrFail()->id,
            'account_subgroup_id' => null,
            'code' => 'BANK-SAL02',
            'name' => 'Receipt Bank',
        ]);
        $bankId = $bank->id;

        // Model layer: the customer's own ledger account is refused.
        $actor = User::factory()->create();
        $payload = ['customer_id' => $customer->id, 'date' => '2026-06-10', 'amount' => 100, 'payment_mode' => 'bank'];

        expect(fn () => Receipt::post($payload + ['bank_account_id' => $customer->account_id], $actor))
            ->toThrow(InvalidArgumentException::class);
    });

    salesP1Login($domain, 'owner@example.com');

    // Form layer: the customer's account is rejected on the field itself.
    $this->post("http://{$domain}/receipts", [
        'customer_id' => $customerId,
        'date' => '2026-06-10',
        'amount' => 100,
        'payment_mode' => 'bank',
        'bank_account_id' => $customerAccountId,
    ])->assertSessionHasErrors('bank_account_id');

    // A real bank account goes through.
    $this->post("http://{$domain}/receipts", [
        'customer_id' => $customerId,
        'date' => '2026-06-10',
        'amount' => 100,
        'payment_mode' => 'bank',
        'bank_account_id' => $bankId,
    ])->assertSessionHasNoErrors();

    $tenant->run(fn () => expect(Receipt::count())->toBe(1));

    $tenant->delete();
});

test('SAL-03 a sale with a negative line can still be returned on its normal lines', function () {
    $tenant = salesP1Tenant('sal03-negative.tenant-test');

    $tenant->run(function () {
        salesP1OpenFiscalYear();
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = salesP1CreditSale($admin, $customer, [
            ['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0],
            ['item_id' => $item->id, 'quantity' => -2, 'rate' => 100, 'discount' => 0],
        ]);

        $lines = $sale->lines()->orderBy('id')->get();
        $positive = $lines->first(fn ($line) => (float) $line->quantity > 0);
        $negative = $lines->first(fn ($line) => (float) $line->quantity < 0);

        // The picker offers only the returnable line.
        expect(collect(SalesReturn::returnableLines($sale))->pluck('sale_line_id')->all())->toBe([$positive->id]);

        // Half of the positive line: 5/10 of the 800.00 invoice value.
        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $positive->id, 'quantity' => 5]],
            $admin,
        );
        expect((string) $return->total)->toBe('400.00');

        // The negative line itself is still refused.
        expect(fn () => SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-03', 'reason' => null],
            [['sale_line_id' => $negative->id, 'quantity' => 1]],
            $admin,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('SAL-04 a non-admin cannot post an unlinked return or approve or reject a request', function () {
    $domain = 'sal04-roles.tenant-test';
    $tenant = salesP1Tenant($domain);

    $returnId = null;
    $tenant->run(function () use (&$returnId) {
        User::factory()->create(['email' => 'clerk@example.com']);
        salesP1OpenFiscalYear();
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $sale = salesP1CreditSale($admin, $customer, [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10, 'discount' => 0]]);

        $returnId = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => 1]],
            $admin,
        )->id;
    });

    salesP1Login($domain, 'clerk@example.com');

    $this->post("http://{$domain}/sales-returns/unlinked", [])->assertForbidden();
    $this->post("http://{$domain}/sales-returns/{$returnId}/approve")->assertForbidden();
    $this->post("http://{$domain}/sales-returns/{$returnId}/reject", ['reason' => 'No'])->assertForbidden();

    $tenant->run(fn () => expect(SalesReturn::findOrFail($returnId)->status)->toBe('pending'));

    $tenant->delete();
});

test('SAL-04 an admin cannot approve a return request they created themselves', function () {
    $domain = 'sal04-maker-checker.tenant-test';
    $tenant = salesP1Tenant($domain);

    $saleId = null;
    $lineId = null;
    $tenant->run(function () use (&$saleId, &$lineId) {
        User::factory()->create(['email' => 'boss@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        User::factory()->create(['email' => 'other-boss@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        salesP1OpenFiscalYear();
        $admin = User::factory()->create();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $sale = salesP1CreditSale($admin, $customer, [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10, 'discount' => 0]]);
        $saleId = $sale->id;
        $lineId = $sale->lines()->firstOrFail()->id;
    });

    salesP1Login($domain, 'boss@example.com');

    $this->post("http://{$domain}/sales-returns/request", [
        'sale_id' => $saleId,
        'date' => '2026-06-02',
        'lines' => [['sale_line_id' => $lineId, 'quantity' => 1]],
    ]);

    $returnId = null;
    $tenant->run(function () use (&$returnId) {
        $returnId = SalesReturn::where('status', 'pending')->firstOrFail()->id;
    });

    $this->post("http://{$domain}/sales-returns/{$returnId}/approve")->assertSessionHasErrors('salesReturn');
    $tenant->run(fn () => expect(SalesReturn::findOrFail($returnId)->status)->toBe('pending'));

    // A different admin can.
    salesP1Login($domain, 'other-boss@example.com');
    $this->post("http://{$domain}/sales-returns/{$returnId}/approve")->assertSessionHasNoErrors();
    $tenant->run(fn () => expect(SalesReturn::findOrFail($returnId)->status)->toBe('posted'));

    $tenant->delete();
});
