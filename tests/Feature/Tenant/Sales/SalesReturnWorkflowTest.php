<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\JournalVoucher;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Covers Part 2's request -> approve/reject two-step workflow bolted onto
 * SalesReturn (see the model's own class docblock for the design decision):
 * requesting a return posts nothing, approving it posts exactly what a
 * direct post() would have, and rejecting it with a reason leaves
 * everything unposted while recording why.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSalesReturnWorkflowTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function salesReturnWorkflowTestAdmin(): User
{
    return User::factory()->create();
}

function salesReturnWorkflowTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('requesting a return posts no journal voucher and no stock movement', function () {
    $tenant = provisionSalesReturnWorkflowTestTenant('sales-return-request-noop.tenant-test');

    $tenant->run(function () {
        salesReturnWorkflowTestOpenFiscalYear();
        $admin = salesReturnWorkflowTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();
        $voucherCountBefore = JournalVoucher::count();
        $movementCountBefore = ItemStockMovement::count();

        $return = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-05', 'reason' => 'Wrong size'],
            [['sale_line_id' => $saleLine->id, 'quantity' => 4]],
            $admin,
        );

        expect($return->status)->toBe('pending')
            ->and($return->journal_voucher_id)->toBeNull()
            ->and((float) $return->taxable_amount)->toBe(400.0)
            ->and((float) $return->vat_amount)->toBe(52.0)
            ->and((float) $return->total)->toBe(452.0)
            ->and(JournalVoucher::count())->toBe($voucherCountBefore)
            ->and(ItemStockMovement::count())->toBe($movementCountBefore)
            ->and($return->lines)->toHaveCount(1);

        // Stock is untouched - still exactly what the sale itself posted,
        // no SaleReturn movement recorded yet.
        expect(ItemStockMovement::where('movement_type', StockMovementType::SaleReturn)->count())->toBe(0);
    });

    $tenant->delete();
});

test('approving a pending request posts the voucher and stock movement, and cannot be approved twice', function () {
    $tenant = provisionSalesReturnWorkflowTestTenant('sales-return-approve.tenant-test');

    $tenant->run(function () {
        salesReturnWorkflowTestOpenFiscalYear();
        // Sold without any prior stock - this test is only about the
        // approval flow, not stock policy.
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = salesReturnWorkflowTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        $return = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-05', 'reason' => 'Damaged'],
            [['sale_line_id' => $saleLine->id, 'quantity' => 4]],
            $admin,
        );

        $approver = salesReturnWorkflowTestAdmin();
        $posted = $return->approve($approver);

        expect($posted->status)->toBe('posted')
            ->and($posted->journal_voucher_id)->not->toBeNull();

        $voucher = $posted->journalVoucher;
        expect((float) $voucher->lines->sum('debit'))->toBe((float) $voucher->lines->sum('credit'))
            // Posted using the return's own requested date, not "today".
            ->and($voucher->date->format('Y-m-d'))->toBe('2026-06-05');

        expect((float) $item->fresh()->currentStock())->toBe(-6.0);

        $movement = ItemStockMovement::where('item_id', $item->id)
            ->where('movement_type', StockMovementType::SaleReturn)
            ->firstOrFail();
        expect((float) $movement->quantity)->toBe(4.0);

        expect(fn () => $posted->approve($approver))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('rejecting a pending request records the reason, posts nothing, and frees the returnable quantity', function () {
    $tenant = provisionSalesReturnWorkflowTestTenant('sales-return-reject.tenant-test');

    $tenant->run(function () {
        salesReturnWorkflowTestOpenFiscalYear();
        $admin = salesReturnWorkflowTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();
        $voucherCountBefore = JournalVoucher::count();

        $return = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => 5]],
            $admin,
        );

        $rejected = $return->reject('Item was never actually returned to the counter');

        expect($rejected->status)->toBe('rejected')
            ->and($rejected->rejection_reason)->toBe('Item was never actually returned to the counter')
            ->and($rejected->journal_voucher_id)->toBeNull()
            ->and(JournalVoucher::count())->toBe($voucherCountBefore);

        // A rejected request no longer counts against the returnable
        // quantity - the full original 5 units are returnable again.
        $newReturn = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-03', 'reason' => 'Re-request'],
            [['sale_line_id' => $saleLine->id, 'quantity' => 5]],
            $admin,
        );
        expect((float) $newReturn->total)->toBeGreaterThan(0);

        // A rejected request cannot be approved or cancelled.
        expect(fn () => $rejected->approve($admin))->toThrow(InvalidArgumentException::class);
        expect(fn () => $rejected->cancel($admin, 'nope'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a pending request against the same sale blocks the sale from being cancelled, but a rejected one does not', function () {
    $tenant = provisionSalesReturnWorkflowTestTenant('sales-return-request-blocks-sale-cancel.tenant-test');

    $tenant->run(function () {
        salesReturnWorkflowTestOpenFiscalYear();
        $admin = salesReturnWorkflowTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 5, 'rate' => 10, 'discount' => 0]],
            $admin,
        );
        $saleLine = $sale->lines()->firstOrFail();

        $return = SalesReturn::request(
            ['sale_id' => $sale->id, 'date' => '2026-06-02', 'reason' => null],
            [['sale_line_id' => $saleLine->id, 'quantity' => 1]],
            $admin,
        );

        expect(fn () => $sale->cancel($admin, 'Too late'))->toThrow(InvalidArgumentException::class);

        $return->reject('Not eligible');

        // Now that the only return against it is rejected (never posted
        // anything), the sale can be cancelled again.
        $sale->cancel($admin, 'Now allowed');
        expect($sale->fresh()->status)->toBe('cancelled');
    });

    $tenant->delete();
});

function loginSalesReturnWorkflowTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the request/approve/reject routes drive the same workflow end to end over HTTP', function () {
    $domain = 'sales-return-workflow-http.tenant-test';
    $tenant = provisionSalesReturnWorkflowTestTenant($domain);

    $saleId = null;
    $saleLineId = null;
    $tenant->run(function () use (&$saleId, &$saleLineId) {
        User::factory()->create(['email' => 'owner@example.com']);
        salesReturnWorkflowTestOpenFiscalYear();
        $admin = salesReturnWorkflowTestAdmin();
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

    loginSalesReturnWorkflowTestUser($domain);

    $this->post("http://{$domain}/sales-returns/request", [
        'sale_id' => $saleId,
        'date' => '2026-06-02',
        'lines' => [
            ['sale_line_id' => $saleLineId, 'quantity' => 2],
        ],
    ])->assertRedirect("http://{$domain}/sales-returns");

    $returnId = null;
    $tenant->run(function () use (&$returnId) {
        $pending = SalesReturn::where('status', 'pending')->firstOrFail();
        $returnId = $pending->id;
        expect($pending->journal_voucher_id)->toBeNull();
    });

    $this->post("http://{$domain}/sales-returns/{$returnId}/reject")
        ->assertSessionHasErrors('reason');

    $this->post("http://{$domain}/sales-returns/{$returnId}/reject", ['reason' => 'Customer changed their mind'])
        ->assertRedirect("http://{$domain}/sales-returns");

    $tenant->run(function () use ($returnId) {
        $rejected = SalesReturn::findOrFail($returnId);
        expect($rejected->status)->toBe('rejected')
            ->and($rejected->rejection_reason)->toBe('Customer changed their mind');
    });

    // A fresh request that IS approved actually posts.
    $this->post("http://{$domain}/sales-returns/request", [
        'sale_id' => $saleId,
        'date' => '2026-06-03',
        'lines' => [
            ['sale_line_id' => $saleLineId, 'quantity' => 1],
        ],
    ])->assertRedirect("http://{$domain}/sales-returns");

    $secondReturnId = null;
    $tenant->run(function () use (&$secondReturnId) {
        $secondReturnId = SalesReturn::where('status', 'pending')->firstOrFail()->id;
    });

    $this->post("http://{$domain}/sales-returns/{$secondReturnId}/approve")
        ->assertRedirect("http://{$domain}/sales-returns");

    $tenant->run(function () use ($secondReturnId) {
        $approved = SalesReturn::findOrFail($secondReturnId);
        expect($approved->status)->toBe('posted')
            ->and($approved->journal_voucher_id)->not->toBeNull();
    });

    $tenant->delete();
});
