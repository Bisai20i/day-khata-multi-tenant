<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\AccountUnderHead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

/**
 * flags/purchases.md PUR-01 (bill number unique per fiscal year), PUR-02
 * (account pickers validated by chart head and not a party ledger) and PUR-03
 * (unlinked purchase return is admin only, reason mandatory, rate bounded,
 * supplier credit mode).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function flagFixTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function flagFixLogin(string $domain): void
{
    test()->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);
}

function flagFixOpenYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function flagFixAdmin(): User
{
    return User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
}

test('the same supplier bill number is accepted again in a new fiscal year but not twice in one year', function () {
    $tenant = flagFixTenant('pur-01-bill-year.tenant-test');

    $tenant->run(function () {
        $actor = User::factory()->create();
        $first = flagFixOpenYear();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false]);
        $data = ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit', 'bill_number' => '001'];
        $lines = [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100]];

        $purchase = Purchase::post($data, $lines, $actor);
        expect($purchase->fiscal_year_id)->toEqual($first->id);

        expect(fn () => Purchase::post($data, $lines, $actor))->toThrow(InvalidArgumentException::class);

        $first->update(['status' => FiscalYearStatus::Closed]);
        $second = FiscalYear::create(['name' => 'FY2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => FiscalYearStatus::Open]);

        $next = Purchase::post([...$data, 'date' => '2027-02-01'], $lines, $actor);

        expect($next->fiscal_year_id)->toEqual($second->id)
            ->and($next->bill_number_key)->toBe('001');
    });

    $tenant->delete();
});

test('the bill number form rule is scoped to the fiscal year', function () {
    $domain = 'pur-01-bill-rule.tenant-test';
    $tenant = flagFixTenant($domain);
    $supplierId = null;
    $itemId = null;

    $tenant->run(function () use (&$supplierId, &$itemId) {
        $actor = flagFixAdmin();
        flagFixOpenYear();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false]);
        $supplierId = $supplier->id;
        $itemId = $item->id;
        Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit', 'bill_number' => '001'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100]],
            $actor,
        );
    });

    flagFixLogin($domain);

    $this->post("http://{$domain}/purchases", [
        'supplier_id' => $supplierId, 'bill_number' => '001', 'date' => '2026-06-02', 'payment_mode' => 'credit',
        'lines' => [['item_id' => $itemId, 'quantity' => 1, 'rate' => 100]],
    ])->assertSessionHasErrors('bill_number');

    $tenant->delete();
});

test('AccountUnderHead accepts only accounts under the head that are not party ledgers', function () {
    $tenant = flagFixTenant('pur-02-rule.tenant-test');

    $tenant->run(function () {
        $bank = Account::where('code', 'AS1')->firstOrFail();
        $expense = Account::where('code', 'EXE8')->firstOrFail();
        $tds = Account::where('code', 'LIA21')->firstOrFail();
        $supplierAccountId = Supplier::factory()->create()->account_id;

        $passes = fn (string $head, $id): bool => Validator::make(['a' => $id], ['a' => [new AccountUnderHead($head)]])->passes();

        expect($passes('Assets', $bank->id))->toBeTrue()
            ->and($passes('Assets', $expense->id))->toBeFalse()
            ->and($passes('Assets', $supplierAccountId))->toBeFalse()
            ->and($passes('Liabilities', $tds->id))->toBeTrue()
            ->and($passes('Liabilities', $expense->id))->toBeFalse()
            ->and($passes('Liabilities', $bank->id))->toBeFalse();
    });

    $tenant->delete();
});

test('purchase, return and payment endpoints refuse an account from the wrong head', function () {
    $domain = 'pur-02-endpoints.tenant-test';
    $tenant = flagFixTenant($domain);
    $expenseId = null;
    $supplierId = null;

    $tenant->run(function () use (&$expenseId, &$supplierId) {
        flagFixAdmin();
        flagFixOpenYear();
        $expenseId = Account::where('code', 'EXE8')->value('id');
        $supplierId = Supplier::factory()->create()->id;
    });

    flagFixLogin($domain);

    $this->post("http://{$domain}/purchases", ['supplier_id' => $supplierId, 'bank_account_id' => $expenseId, 'tds_account_id' => $expenseId])
        ->assertSessionHasErrors(['bank_account_id', 'tds_account_id']);

    $this->post("http://{$domain}/purchase-returns", ['refund_account_id' => $expenseId])
        ->assertSessionHasErrors('refund_account_id');

    $this->post("http://{$domain}/purchase-returns/unlinked", ['bank_account_id' => $expenseId])
        ->assertSessionHasErrors('bank_account_id');

    $this->post("http://{$domain}/payments", ['supplier_id' => $supplierId, 'payment_mode' => 'bank', 'bank_account_id' => $expenseId])
        ->assertSessionHasErrors('bank_account_id');

    $tenant->delete();
});

/**
 * @return array{0: int, 1: int} item id (10 pieces at 100.00 on the books) and supplier id
 */
function flagFixStockedItem(User $actor): array
{
    $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true, 'account_id' => Account::factory()->create()->id]);
    $supplier = Supplier::factory()->create();

    Purchase::post(
        ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
        [['item_id' => $item->id, 'quantity' => '10', 'rate' => '100']],
        $actor,
    );

    return [$item->id, $supplier->id];
}

test('a non admin cannot post an unlinked purchase return', function () {
    $domain = 'pur-03-non-admin.tenant-test';
    $tenant = flagFixTenant($domain);

    $tenant->run(fn () => User::factory()->create(['email' => 'owner@example.com']));

    flagFixLogin($domain);

    $this->post("http://{$domain}/purchase-returns/unlinked", [])->assertForbidden();

    $tenant->delete();
});

test('an unlinked return needs a reason, a confirmed total and a rate within cost', function () {
    $domain = 'pur-03-validation.tenant-test';
    $tenant = flagFixTenant($domain);
    $itemId = null;

    $tenant->run(function () use (&$itemId) {
        $actor = flagFixAdmin();
        flagFixOpenYear();
        [$itemId] = flagFixStockedItem($actor);
    });

    flagFixLogin($domain);

    $base = ['date' => '2026-06-10', 'payment_mode' => 'cash', 'vat_rate' => '0', 'reason' => 'Damaged', 'expected_total' => '100.00'];

    $this->post("http://{$domain}/purchase-returns/unlinked", [...$base, 'reason' => '', 'expected_total' => null,
        'lines' => [['item_id' => $itemId, 'quantity' => 1]]])
        ->assertSessionHasErrors(['reason', 'expected_total']);

    $this->post("http://{$domain}/purchase-returns/unlinked", [...$base, 'expected_total' => '150.00',
        'lines' => [['item_id' => $itemId, 'quantity' => 1, 'rate' => '150']]])
        ->assertSessionHasErrors('lines.0.rate');

    $tenant->run(fn () => expect(PurchaseReturn::query()->count())->toBe(0));

    $this->post("http://{$domain}/purchase-returns/unlinked", [...$base,
        'lines' => [['item_id' => $itemId, 'quantity' => 1, 'rate' => '100']]])
        ->assertSessionHasNoErrors();

    $tenant->run(fn () => expect(PurchaseReturn::query()->count())->toBe(1));

    $tenant->delete();
});

test('an unlinked return can be credited to the supplier account instead of cash', function () {
    $domain = 'pur-03-credit.tenant-test';
    $tenant = flagFixTenant($domain);
    $itemId = null;
    $supplierId = null;

    $tenant->run(function () use (&$itemId, &$supplierId) {
        $actor = flagFixAdmin();
        flagFixOpenYear();
        [$itemId, $supplierId] = flagFixStockedItem($actor);
    });

    flagFixLogin($domain);

    $payload = ['date' => '2026-06-10', 'payment_mode' => 'credit', 'vat_rate' => '0', 'reason' => 'Damaged',
        'expected_total' => '200.00', 'lines' => [['item_id' => $itemId, 'quantity' => 2]]];

    $this->post("http://{$domain}/purchase-returns/unlinked", $payload)->assertSessionHasErrors('supplier_id');

    $this->post("http://{$domain}/purchase-returns/unlinked", [...$payload, 'supplier_id' => $supplierId])
        ->assertSessionHasNoErrors();

    $tenant->run(function () use ($supplierId) {
        $return = PurchaseReturn::firstOrFail();
        $supplierAccountId = Supplier::findOrFail($supplierId)->account_id;
        $lines = $return->journalVoucher->lines;

        expect($return->cash_amount)->toBe('0.00')
            ->and($return->bank_amount)->toBe('0.00')
            ->and($lines->where('account_id', $supplierAccountId)->sum('debit'))->toEqual('200.00')
            ->and($lines->where('account_id', Account::where('code', 'AS1')->value('id'))->count())->toBe(0);
    });

    $tenant->delete();
});
