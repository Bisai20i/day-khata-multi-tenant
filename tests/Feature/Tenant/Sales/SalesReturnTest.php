<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Tenant;
use App\Models\User;
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

        expect($itemA->fresh()->currentStock())->toBe(-10.0);

        $saleLineA = $sale->lines()->where('item_id', $itemA->id)->firstOrFail();

        $return = SalesReturn::post(
            ['sale_id' => $sale->id, 'date' => '2026-06-05', 'reason' => 'Damaged'],
            [['sale_line_id' => $saleLineA->id, 'quantity' => 4]],
            $admin,
        );

        // 4 of 10 units @ effective unit price 100 = 400 taxable, 13% VAT = 52.
        expect((float) $return->taxable_amount)->toBe(400.0)
            ->and((float) $return->vat_amount)->toBe(52.0)
            ->and((float) $return->total)->toBe(452.0);

        $voucher = $return->journalVoucher;
        expect((float) $voucher->lines->sum('debit'))->toBe((float) $voucher->lines->sum('credit'));

        expect($itemA->fresh()->currentStock())->toBe(-6.0);

        $movement = ItemStockMovement::where('item_id', $itemA->id)
            ->where('movement_type', StockMovementType::SaleReturn)
            ->firstOrFail();
        expect((float) $movement->quantity)->toBe(4.0);
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
