<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSalesReturnTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function salesReturnTestAdmin(): User
{
    return User::factory()->create();
}

function salesReturnTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('returning part of one line posts a balanced voucher and increases stock', function () {
    $tenant = provisionSalesReturnTestTenant('sales-return-basic.tenant-test');

    $tenant->run(function () {
        salesReturnTestOpenFiscalYear();
        // Sold without any prior stock - this test is only about the return
        // flow, not stock policy.
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = salesReturnTestAdmin();
        $customer = Customer::factory()->create();
        $itemA = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);
        $itemB = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [
                ['item_id' => $itemA->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0],
                ['item_id' => $itemB->id, 'quantity' => 5, 'rate' => 20, 'discount' => 0],
            ],
            $admin,
        );

        expect($itemA->fresh()->currentStock()->toString())->toBe('-10.0000');

        $saleLineA = $sale->lines()->where('item_id', $itemA->id)->firstOrFail();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-05', 'reason' => 'Damaged'],
            [['sale_line_id' => $saleLineA->id, 'quantity' => 4]],
            $admin,
        );

        // 4 of 10 units @ effective unit price 100 = 400 taxable, 13% VAT = 52.
        expect((string) $return->taxable_amount)->toBe('400.00')
            ->and((string) $return->vat_amount)->toBe('52.00')
            ->and((string) $return->total)->toBe('452.00');

        $voucher = $return->journalVoucher;
        expect(Money::sum($voucher->lines->pluck('debit'))->toString())
            ->toBe(Money::sum($voucher->lines->pluck('credit'))->toString());

        expect($itemA->fresh()->currentStock()->toString())->toBe('-6.0000');

        $movement = ItemStockMovement::where('item_id', $itemA->id)
            ->where('movement_type', StockMovementType::SaleReturn)
            ->firstOrFail();
        expect(Quantity::of($movement->quantity)->toString())->toBe('4.0000');
    });

    $tenant->delete();
});

test('a return exceeding the remaining returnable quantity is rejected', function () {
    $tenant = provisionSalesReturnTestTenant('sales-return-overreturn.tenant-test');

    $tenant->run(function () {
        salesReturnTestOpenFiscalYear();
        $admin = salesReturnTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => 3]],
            $admin,
        );

        expect(fn () => SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-03', 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => 3]],
            $admin,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('returning against a cancelled sale is rejected', function () {
    $tenant = provisionSalesReturnTestTenant('sales-return-cancelled-sale.tenant-test');

    $tenant->run(function () {
        salesReturnTestOpenFiscalYear();
        $admin = salesReturnTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();
        $sale->cancel($admin, 'Wrong entry');

        expect(fn () => SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => 1]],
            $admin,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling a sale that already has a return against it is rejected', function () {
    $tenant = provisionSalesReturnTestTenant('sales-return-then-cancel.tenant-test');

    $tenant->run(function () {
        salesReturnTestOpenFiscalYear();
        $admin = salesReturnTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => 1]],
            $admin,
        );

        expect(fn () => $sale->cancel($admin, 'Too late'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('cancelling a sales return reverses its voucher, frees the returned quantity, and rejects double-cancellation', function () {
    $tenant = provisionSalesReturnTestTenant('sales-return-cancel.tenant-test');

    $tenant->run(function () {
        salesReturnTestOpenFiscalYear();
        // Sold without any prior stock - this test is only about the
        // cancel-a-return flow, not stock policy.
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = salesReturnTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => 'Damaged'],
            [['sale_line_id' => $saleLine->id, 'quantity' => 4]],
            $admin,
        );
        expect($item->fresh()->currentStock()->toString())->toBe('-6.0000');

        $return->cancel($admin, 'Entered in error');

        expect($return->fresh()->status)->toBe('cancelled')
            ->and($item->fresh()->currentStock()->toString())->toBe('-10.0000');

        $cancelVoucher = JournalVoucher::latest('id')->firstOrFail();
        expect(Money::sum($cancelVoucher->lines->pluck('debit'))->toString())
            ->toBe(Money::sum($cancelVoucher->lines->pluck('credit'))->toString());

        expect(fn () => $return->cancel($admin, 'Again'))->toThrow(InvalidArgumentException::class);

        // The cancelled return no longer counts against the remaining
        // returnable quantity - the full original 10 units are returnable again.
        $newReturn = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-03', 'reason' => 'Re-return'],
            [['sale_line_id' => $saleLine->id, 'quantity' => 10]],
            $admin,
        );
        expect(Money::of($newReturn->total)->isPositive())->toBeTrue();
    });

    $tenant->delete();
});

test('cancelling a sale becomes possible again once its only return against it is cancelled', function () {
    $tenant = provisionSalesReturnTestTenant('sales-return-then-uncancel.tenant-test');

    $tenant->run(function () {
        salesReturnTestOpenFiscalYear();
        $admin = salesReturnTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => 1]],
            $admin,
        );

        expect(fn () => $sale->cancel($admin, 'Too late'))->toThrow(InvalidArgumentException::class);

        $return->cancel($admin, 'Undo the return');

        $sale->cancel($admin, 'Now allowed');
        expect($sale->fresh()->status)->toBe('cancelled');
    });

    $tenant->delete();
});

test('a return proportionally reverses the header discount and TDS withheld on the original sale', function () {
    $tenant = provisionSalesReturnTestTenant('sales-return-discount-tds.tenant-test');

    $tenant->run(function () {
        salesReturnTestOpenFiscalYear();
        $admin = salesReturnTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
        $tdsAccount = Account::factory()->create();

        $sale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'discount' => 100,
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 50,
            ],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        expect((string) $sale->taxable_amount)->toBe('900.00')
            ->and((string) $sale->total)->toBe('1017.00');

        $saleLine = $sale->lines()->firstOrFail();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-05', 'reason' => 'Partial return'],
            [['sale_line_id' => $saleLine->id, 'quantity' => 4]],
            $admin,
        );

        // 4 of 10 units of a 1000 vatable subtotal = 400 gross; the 100
        // header discount is shared proportionally (400/1000 = 40% -> 40),
        // so only 360 counts as taxable; VAT at 13% = 46.8; total = 406.8.
        expect((string) $return->taxable_amount)->toBe('360.00')
            ->and((string) $return->vat_amount)->toBe('46.80')
            ->and((string) $return->total)->toBe('406.80');

        $voucher = $return->journalVoucher;
        expect(Money::sum($voucher->lines->pluck('debit'))->toString())
            ->toBe(Money::sum($voucher->lines->pluck('credit'))->toString());

        // TDS share: this line carries the whole invoice's 50, of which the
        // return takes 4 of 10 units = 20.00 exactly - a share of the line's
        // own component, not of the document total (C6). The customer is
        // credited total-minus-tdsShare (386.80), the TDS account the 20
        // being clawed back.
        $tdsLine = $voucher->lines()->where('account_id', $tdsAccount->id)->firstOrFail();
        expect(Money::of($tdsLine->credit)->toString())->toBe('20.00');

        $customerLine = $voucher->lines()->where('account_id', $customer->account_id)->firstOrFail();
        expect(Money::of($customerLine->credit)->toString())->toBe('386.80');
    });

    $tenant->delete();
});

test('a return proportionally reverses a percentage header discount identically to the equivalent flat discount', function () {
    $tenant = provisionSalesReturnTestTenant('sales-return-percentage-discount.tenant-test');

    $tenant->run(function () {
        salesReturnTestOpenFiscalYear();
        $admin = salesReturnTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
        $tdsAccount = Account::factory()->create();

        // Same shape as the flat-discount test above (1000 vatable subtotal,
        // 10% off = 100 Rs) - discount_type=percentage stores the raw "10"
        // in the `discount` column instead of the Rs amount, so this proves
        // the return reconstructs the same 40 Rs (not 400% of the line).
        $sale = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'credit',
                'discount' => 10,
                'discount_type' => 'percentage',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 50,
            ],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        expect((string) $sale->discount)->toBe('10.00')
            ->and((string) $sale->taxable_amount)->toBe('900.00')
            ->and((string) $sale->total)->toBe('1017.00');

        $saleLine = $sale->lines()->firstOrFail();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-05', 'reason' => 'Partial return'],
            [['sale_line_id' => $saleLine->id, 'quantity' => 4]],
            $admin,
        );

        // 4 of 10 units = 400 gross; 10% of that (40, not 400) is the
        // proportional discount reversal, so 360 counts as taxable; VAT at
        // 13% = 46.8; total = 406.8 - identical to the flat-discount case.
        expect((string) $return->taxable_amount)->toBe('360.00')
            ->and((string) $return->vat_amount)->toBe('46.80')
            ->and((string) $return->total)->toBe('406.80');

        $voucher = $return->journalVoucher;
        expect(Money::sum($voucher->lines->pluck('debit'))->toString())
            ->toBe(Money::sum($voucher->lines->pluck('credit'))->toString());

        $tdsLine = $voucher->lines()->where('account_id', $tdsAccount->id)->firstOrFail();
        expect(Money::of($tdsLine->credit)->toString())->toBe('20.00');

        $customerLine = $voucher->lines()->where('account_id', $customer->account_id)->firstOrFail();
        expect(Money::of($customerLine->credit)->toString())->toBe('386.80');
    });

    $tenant->delete();
});

test('a return without a refund account posts only the return voucher', function () {
    $tenant = provisionSalesReturnTestTenant('sales-return-no-refund.tenant-test');

    $tenant->run(function () {
        salesReturnTestOpenFiscalYear();
        $admin = salesReturnTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 50, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => 5]],
            $admin,
        );

        expect($return->refund_journal_voucher_id)->toBeNull()
            ->and($return->refundJournalVoucher)->toBeNull();
    });

    $tenant->delete();
});

test('a return with a refund account posts a refund settlement voucher and nets the customer balance to zero', function () {
    $tenant = provisionSalesReturnTestTenant('sales-return-refund.tenant-test');

    $tenant->run(function () {
        salesReturnTestOpenFiscalYear();
        $admin = salesReturnTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
        $bankAccount = Account::factory()->create([
            'account_group_id' => AccountGroup::where('name', 'Current Assets')->firstOrFail()->id,
            'account_subgroup_id' => null,
        ]);

        // Cash sale: fully settled at posting, so a full return leaves the
        // customer with a credit balance until refunded.
        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 50, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null, 'refund_account_id' => $bankAccount->id],
            [['sale_line_id' => $saleLine->id, 'quantity' => 5]],
            $admin,
        );

        expect((string) $return->total)->toBe('282.50')
            ->and($return->refund_journal_voucher_id)->not->toBeNull();

        $refundVoucher = $return->refundJournalVoucher;
        expect(Money::sum($refundVoucher->lines->pluck('debit'))->toString())
            ->toBe(Money::sum($refundVoucher->lines->pluck('credit'))->toString());

        $customerDebit = $refundVoucher->lines()->where('account_id', $customer->account_id)->firstOrFail();
        expect(Money::of($customerDebit->debit)->toString())->toBe('282.50');

        $bankCredit = $refundVoucher->lines()->where('account_id', $bankAccount->id)->firstOrFail();
        expect(Money::of($bankCredit->credit)->toString())->toBe('282.50');

        $customerLines = JournalVoucherLine::where('account_id', $customer->account_id)->get();
        $netBalance = Money::sum($customerLines->pluck('debit'))->minus(Money::sum($customerLines->pluck('credit')));
        expect($netBalance->toString())->toBe('0.00');
    });

    $tenant->delete();
});

function loginSalesReturnTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the sales returns index page renders and a return can be posted through the store route', function () {
    $domain = 'sales-return-http.tenant-test';
    $tenant = provisionSalesReturnTestTenant($domain);

    $saleId = null;
    $saleLineId = null;
    $tenant->run(function () use (&$saleId, &$saleLineId) {
        User::factory()->create(['email' => 'owner@example.com']);
        salesReturnTestOpenFiscalYear();
        $admin = salesReturnTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10, 'discount' => 0]],
            $admin,
        );
        $saleId = $sale->id;
        $saleLineId = $sale->lines()->firstOrFail()->id;
    });

    loginSalesReturnTestUser($domain);

    $this->get("http://{$domain}/sales-returns")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Sales/Returns/Index'));

    $this->post("http://{$domain}/sales-returns", [
        'sale_id' => $saleId,
        'date' => '2026-06-02',
        'lines' => [
            ['sale_line_id' => $saleLineId, 'quantity' => 2],
        ],
    ])->assertRedirect("http://{$domain}/sales-returns");

    $tenant->run(function () {
        expect(SalesReturn::query()->count())->toBe(1);
    });

    $tenant->delete();
});

test('cancelling a credit note over HTTP is admin only', function () {
    $domain = 'sales-return-cancel-role.tenant-test';
    $tenant = provisionSalesReturnTestTenant($domain);

    $returnId = null;
    $tenant->run(function () use (&$returnId) {
        // A plain tenant user, with no role: they may record a return but not
        // reverse a posted credit note (CONTRACTS C5).
        User::factory()->create(['email' => 'clerk@example.com']);
        salesReturnTestOpenFiscalYear();
        $admin = salesReturnTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10, 'discount' => 0]],
            $admin,
        );

        $returnId = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $sale->lines()->firstOrFail()->id, 'quantity' => 2]],
            $admin,
        )->id;
    });

    $this->post("http://{$domain}/login", ['email' => 'clerk@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/sales-returns/{$returnId}/cancel", ['reason' => 'Not my call'])
        ->assertForbidden();

    $tenant->run(function () use ($returnId) {
        expect(SalesReturn::findOrFail($returnId)->status)->toBe('posted');

        User::factory()->create([
            'email' => 'boss@example.com',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);
    });

    $this->post("http://{$domain}/login", ['email' => 'boss@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/sales-returns/{$returnId}/cancel", ['reason' => 'Entered in error'])
        ->assertRedirect("http://{$domain}/sales-returns");

    $tenant->run(function () use ($returnId) {
        $cancelled = SalesReturn::findOrFail($returnId);

        expect($cancelled->status)->toBe('cancelled')
            ->and($cancelled->cancel_reason)->toBe('Entered in error')
            // Cancelling never releases or rewrites the note's own number.
            ->and($cancelled->credit_note_number)->toBe('SR-1');
    });

    $tenant->delete();
});
