<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Billing\BillingException;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Covers T12 item 6's "returns without a bill" backend (SalesReturn::
 * postUnlinked()), the cash+bank split refund on both the linked and
 * unlinked paths (SalesReturn::postRefund() via DocumentCalculator::
 * assertExactSplit(), C3), and the bonus_quantity cap on a linked return
 * (SalesReturn::prepareLines()). These were already in the tree (verified by
 * reading, per the task file's item 6 notes); this file is the missing test
 * coverage for that already-implemented behaviour.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSalesReturnUnlinkedTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function salesReturnUnlinkedTestAdmin(): User
{
    return User::factory()->create();
}

function salesReturnUnlinkedTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

/**
 * A Current Assets-filed account other than the seeded AS1 "Cash In Hand",
 * for a refund's bank leg - SalesReturn::refundAccountQuery() only accepts
 * an account filed (directly or via subgroup) under "Current Assets".
 */
function salesReturnUnlinkedTestBankAccount(): Account
{
    return Account::factory()->create([
        'account_group_id' => AccountGroup::where('name', 'Current Assets')->firstOrFail()->id,
        'account_subgroup_id' => null,
        'code' => 'BANK-TEST',
        'name' => 'Test Bank',
    ]);
}

test('an unlinked return credits the customer, VAT and stock without a parent sale', function () {
    $tenant = provisionSalesReturnUnlinkedTestTenant('sales-return-unlinked-basic.tenant-test');

    $tenant->run(function () {
        salesReturnUnlinkedTestOpenFiscalYear();
        $admin = salesReturnUnlinkedTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $return = SalesReturn::postUnlinked(
            ['customer_id' => $customer->id, 'date' => '2026-06-05', 'reason' => 'Pre-cutover sale'],
            [['item_id' => $item->id, 'quantity' => '3', 'rate' => '100.00']],
            $admin,
        );

        // 3 x 100 = 300 taxable, 13% VAT = 39, total 339 - priced exactly
        // like a fresh sale, at the CURRENT company VAT rate (there is no
        // frozen document to read one off).
        expect($return->sale_id)->toBeNull()
            ->and($return->is_unlinked)->toBeTrue()
            ->and((string) $return->taxable_amount)->toBe('300.00')
            ->and((string) $return->vat_amount)->toBe('39.00')
            ->and((string) $return->total)->toBe('339.00')
            ->and($return->credit_note_number)->not->toBeNull();

        $voucher = $return->journalVoucher;
        expect(Money::sum($voucher->lines->pluck('debit'))->toString())
            ->toBe(Money::sum($voucher->lines->pluck('credit'))->toString());

        // Stock only ever moves IN on a return, so a fresh item with no
        // prior movement now holds exactly the returned quantity.
        expect($item->fresh()->currentStock()->toString())->toBe('3.0000');
    });

    $tenant->delete();
});

test('an unlinked return refuses a customer that does not exist', function () {
    $tenant = provisionSalesReturnUnlinkedTestTenant('sales-return-unlinked-bad-customer.tenant-test');

    $tenant->run(function () {
        salesReturnUnlinkedTestOpenFiscalYear();
        $admin = salesReturnUnlinkedTestAdmin();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        expect(fn () => SalesReturn::postUnlinked(
            ['customer_id' => 999999, 'date' => '2026-06-05', 'reason' => null],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '10.00']],
            $admin,
        ))->toThrow(ModelNotFoundException::class);
    });

    $tenant->delete();
});

test('a linked return split refund that adds up exactly to the credit posts cash and bank legs', function () {
    $tenant = provisionSalesReturnUnlinkedTestTenant('sales-return-split-exact.tenant-test');

    $tenant->run(function () {
        salesReturnUnlinkedTestOpenFiscalYear();
        $admin = salesReturnUnlinkedTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $bankAccount = salesReturnUnlinkedTestBankAccount();
        $cashAccount = Account::where('code', 'AS1')->firstOrFail();

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => '10', 'rate' => '100.00']],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        // Full return of the 1000 cash sale: 600 handed back in cash, 400
        // moved to the bank - the two legs must add up to the credit exactly
        // (C3, DocumentCalculator::assertExactSplit()).
        $return = SalesReturn::post(
            [
                'sale_id' => $sale->id,
                'date' => '2026-06-02',
                'reason' => null,
                'refund_account_id' => $bankAccount->id,
                'refund_cash_amount' => '600.00',
                'refund_bank_amount' => '400.00',
            ],
            [['sale_line_id' => $saleLine->id, 'quantity' => '10']],
            $admin,
        );

        $refundVoucher = $return->refundJournalVoucher;
        expect(Money::sum($refundVoucher->lines->pluck('debit'))->toString())
            ->toBe(Money::sum($refundVoucher->lines->pluck('credit'))->toString());

        $cashLeg = $refundVoucher->lines()->where('account_id', $cashAccount->id)->firstOrFail();
        expect(Money::of($cashLeg->credit)->toString())->toBe('600.00');

        $bankLeg = $refundVoucher->lines()->where('account_id', $bankAccount->id)->firstOrFail();
        expect(Money::of($bankLeg->credit)->toString())->toBe('400.00');

        $customerLeg = $refundVoucher->lines()->where('account_id', $customer->account_id)->firstOrFail();
        expect(Money::of($customerLeg->debit)->toString())->toBe('1000.00');
    });

    $tenant->delete();
});

test('a linked return split refund that does not add up to the credit is rejected', function () {
    $tenant = provisionSalesReturnUnlinkedTestTenant('sales-return-split-mismatch.tenant-test');

    $tenant->run(function () {
        salesReturnUnlinkedTestOpenFiscalYear();
        $admin = salesReturnUnlinkedTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $bankAccount = salesReturnUnlinkedTestBankAccount();

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => '10', 'rate' => '100.00']],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        // 600 + 300 = 900, short of the 1000 credit by 100 - must be refused
        // rather than silently leaving 100 unaccounted for.
        expect(fn () => SalesReturn::post(
            [
                'sale_id' => $sale->id,
                'date' => '2026-06-02',
                'reason' => null,
                'refund_account_id' => $bankAccount->id,
                'refund_cash_amount' => '600.00',
                'refund_bank_amount' => '300.00',
            ],
            [['sale_line_id' => $saleLine->id, 'quantity' => '10']],
            $admin,
        ))->toThrow(BillingException::class);
    });

    $tenant->delete();
});

test('an unlinked return split refund that does not add up to the credit is rejected', function () {
    $tenant = provisionSalesReturnUnlinkedTestTenant('sales-return-unlinked-split-mismatch.tenant-test');

    $tenant->run(function () {
        salesReturnUnlinkedTestOpenFiscalYear();
        $admin = salesReturnUnlinkedTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $bankAccount = salesReturnUnlinkedTestBankAccount();

        expect(fn () => SalesReturn::postUnlinked(
            [
                'customer_id' => $customer->id,
                'date' => '2026-06-05',
                'reason' => null,
                'refund_account_id' => $bankAccount->id,
                'refund_cash_amount' => '50.00',
                'refund_bank_amount' => '10.00',
            ],
            [['item_id' => $item->id, 'quantity' => '1', 'rate' => '100.00']],
            $admin,
        ))->toThrow(BillingException::class);
    });

    $tenant->delete();
});

test('a linked return cannot credit more bonus units than the sale line has left', function () {
    $tenant = provisionSalesReturnUnlinkedTestTenant('sales-return-bonus-cap.tenant-test');

    $tenant->run(function () {
        salesReturnUnlinkedTestOpenFiscalYear();
        $admin = salesReturnUnlinkedTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '10', 'rate' => '100.00', 'bonus_quantity' => '3']],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        expect((string) $saleLine->bonus_quantity)->toBe('3.0000');

        // Only 3 bonus units were ever given on this line - asking for 5 back
        // must be refused, not silently capped or ignored.
        expect(fn () => SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => '2', 'bonus_quantity' => '5']],
            $admin,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a linked return within the bonus cap credits paid units and restocks the bonus units at zero value', function () {
    $tenant = provisionSalesReturnUnlinkedTestTenant('sales-return-bonus-ok.tenant-test');

    $tenant->run(function () {
        salesReturnUnlinkedTestOpenFiscalYear();
        $admin = salesReturnUnlinkedTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
        CompanySetting::current()->update(['allow_negative_stock' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '10', 'rate' => '100.00', 'bonus_quantity' => '3']],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => '2', 'bonus_quantity' => '3']],
            $admin,
        );

        $returnLine = $return->lines()->firstOrFail();
        // Only the paid quantity is valued - the bonus units restock at zero
        // (audit section 3 "Sales", bonus/free quantity: "money on quantity
        // only"), so 2 units @ 100 = 200 regardless of the 3 bonus units also
        // credited.
        expect((string) $returnLine->quantity)->toBe('2.0000')
            ->and((string) $returnLine->bonus_quantity)->toBe('3.0000')
            ->and((string) $return->taxable_amount)->toBe('200.00');
    });

    $tenant->delete();
});
