<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Enums\VoucherType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucherLine;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSaleTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function saleTestAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

function saleTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function saleTestCashAccount(): Account
{
    return Account::where('code', 'AS1')->firstOrFail();
}

function saleTestSalesAccount(): Account
{
    return Account::where('code', 'INI20')->firstOrFail();
}

function saleTestVatPayableAccount(): Account
{
    return Account::where('code', 'LIA20')->firstOrFail();
}

function saleTestAccountNetBalance(int $accountId): float
{
    return (float) JournalVoucherLine::query()
        ->where('account_id', $accountId)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
        ->value('net');
}

test('a cash sale posts a balanced voucher, nets the customer account to zero, and records a stock movement', function () {
    $tenant = provisionSaleTestTenant('sale-cash.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        expect((float) $sale->taxable_amount)->toBe(200.0)
            ->and((float) $sale->vat_amount)->toBe(26.0)
            ->and((float) $sale->total)->toBe(226.0)
            ->and($sale->journalVoucher->voucher_type)->toBe(VoucherType::Sale);

        $lines = $sale->journalVoucher->lines;
        $totalDebit = (float) $lines->sum('debit');
        $totalCredit = (float) $lines->sum('credit');
        expect($totalDebit)->toBe($totalCredit);

        // Cash sale: customer is debited then immediately credited by the
        // settlement leg, so its net ledger balance is zero.
        expect(saleTestAccountNetBalance($customer->account_id))->toBe(0.0)
            ->and(saleTestAccountNetBalance(saleTestCashAccount()->id))->toBe(226.0)
            ->and(saleTestAccountNetBalance(saleTestSalesAccount()->id))->toBe(-200.0)
            ->and(saleTestAccountNetBalance(saleTestVatPayableAccount()->id))->toBe(-26.0);

        $movement = ItemStockMovement::where('item_id', $item->id)->firstOrFail();
        expect($movement->movement_type)->toBe(StockMovementType::Sale)
            ->and((float) $movement->quantity)->toBe(2.0)
            ->and($movement->cancelled)->toBeFalse()
            ->and($item->fresh()->currentStock())->toBe(-2.0);
    });

    $tenant->delete();
});

test('a credit sale skips the settlement leg and leaves the total owed on the customer account', function () {
    $tenant = provisionSaleTestTenant('sale-credit.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        expect((float) $sale->total)->toBe(113.0)
            ->and(saleTestAccountNetBalance($customer->account_id))->toBe(113.0)
            ->and(saleTestAccountNetBalance(saleTestCashAccount()->id))->toBe(0.0);
    });

    $tenant->delete();
});

test('vat is only charged on vatable lines', function () {
    $tenant = provisionSaleTestTenant('sale-vat-mix.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $vatable = Item::factory()->create(['is_vatable' => true, 'is_stockable' => false]);
        $nonVatable = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [
                ['item_id' => $vatable->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0],
                ['item_id' => $nonVatable->id, 'quantity' => 1, 'rate' => 50, 'discount' => 0],
            ],
            $admin,
        );

        expect((float) $sale->taxable_amount)->toBe(100.0)
            ->and((float) $sale->nontaxable_amount)->toBe(50.0)
            ->and((float) $sale->vat_amount)->toBe(13.0)
            ->and((float) $sale->total)->toBe(163.0);
    });

    $tenant->delete();
});

test('a partial payment whose cash and bank amounts do not add up to the settlement due is rejected', function () {
    $tenant = provisionSaleTestTenant('sale-partial-mismatch.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $bankAccount = Account::factory()->create();

        expect(fn () => Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'partial',
                'bank_account_id' => $bankAccount->id,
                'cash_amount' => 10,
                'bank_amount' => 10,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('the TDS leg only appears when a TDS amount is set, and reduces the settlement due', function () {
    $tenant = provisionSaleTestTenant('sale-tds.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $tdsAccount = Account::factory()->create();

        $withoutTds = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        expect($withoutTds->journalVoucher->lines()->where('account_id', $tdsAccount->id)->count())->toBe(0);

        $withTds = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-02',
                'payment_mode' => 'cash',
                'tds_account_id' => $tdsAccount->id,
                'tds_amount' => 10,
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        expect((float) $withTds->total)->toBe(100.0);
        $tdsLine = $withTds->journalVoucher->lines()->where('account_id', $tdsAccount->id)->firstOrFail();
        expect((float) $tdsLine->debit)->toBe(10.0);

        // Cash received is total (100) minus TDS withheld (10) = 90.
        $cashLines = $withTds->journalVoucher->lines()->where('account_id', saleTestCashAccount()->id)->get();
        expect((float) $cashLines->sum('debit'))->toBe(90.0);
    });

    $tenant->delete();
});

test('cancelling a sale posts a mirrored SaleReturn voucher, flags stock movements cancelled, and rejects double-cancellation', function () {
    $tenant = provisionSaleTestTenant('sale-cancel.tenant-test');

    $tenant->run(function () {
        saleTestOpenFiscalYear();
        $admin = saleTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 3, 'rate' => 50, 'discount' => 0]],
            $admin,
        );

        expect($item->fresh()->currentStock())->toBe(-3.0);

        $sale->cancel($admin, 'Recorded in error');

        $returnVoucher = \App\Models\JournalVoucher::where('voucher_type', VoucherType::SaleReturn)->firstOrFail();
        expect((float) $returnVoucher->lines->sum('debit'))->toBe((float) $returnVoucher->lines->sum('credit'))
            ->and(saleTestAccountNetBalance($customer->account_id))->toBe(0.0)
            ->and($sale->fresh()->status)->toBe('cancelled')
            ->and($item->fresh()->currentStock())->toBe(0.0);

        expect(fn () => $sale->cancel($admin, 'Again'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});
