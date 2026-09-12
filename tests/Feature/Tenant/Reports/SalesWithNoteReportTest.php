<?php

use App\Enums\FiscalYearStatus;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSalesWithNoteTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginSalesWithNoteTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the sales with note report includes only sales with a non-blank note', function () {
    $domain = 'sales-with-note.tenant-test';
    $tenant = provisionSalesWithNoteTestTenant($domain);

    $invoiceNumber = null;
    $tenant->run(function () use (&$invoiceNumber) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create(['name' => 'Ram Shrestha']);
        $item = Item::factory()->create(['name' => 'Widget', 'unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => false]);

        // Has a real note - must appear.
        $noted = Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-01',
                'payment_mode' => 'cash',
                'narration' => 'Customer requested rush delivery',
                'chalani_number' => 'CH-100',
            ],
            [['item_id' => $item->id, 'quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $invoiceNumber = $noted->invoice_number;

        // narration explicitly null - must NOT appear.
        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // narration is an empty string - must NOT appear.
        Sale::post(
            [
                'customer_id' => $customer->id,
                'invoice_type' => 'full',
                'date' => '2026-06-03',
                'payment_mode' => 'cash',
                'narration' => '',
            ],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
    });

    loginSalesWithNoteTestUser($domain);

    $this->get("http://{$domain}/reports/sales-with-note?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Reports/SalesWithNote')
            ->has('sales', 1)
            // The row now carries the stored invoice number, so an operator
            // can tie a note back to the printed bill.
            ->where('sales.0.invoice_number', $invoiceNumber)
            ->where('sales.0.note', 'Customer requested rush delivery')
            ->where('sales.0.chalani_number', 'CH-100')
            ->where('sales.0.customer', 'Ram Shrestha')
            ->has('sales.0.items', 1)
            ->where('sales.0.items.0.name', 'Widget')
            // Exact 4-decimal quantity string, and the unit the LINE was
            // entered in (no item_unit_id here, so it falls back to the
            // item's base unit).
            ->where('sales.0.items.0.quantity', '2.0000')
            ->where('sales.0.items.0.unit', 'pcs')
            // Money is a 2-decimal string: 2 x 100, exempt item, no VAT.
            ->where('sales.0.total', '200.00')
            ->where('totals.count', 1)
            ->where('totals.total', '200.00')
        );

    $tenant->delete();
});

test('a cancelled sale with a note is excluded from the sales with note report', function () {
    $domain = 'sales-with-note-cancelled.tenant-test';
    $tenant = provisionSalesWithNoteTestTenant($domain);

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget', 'is_vatable' => false, 'is_stockable' => false]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash', 'narration' => 'Damaged box'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        $sale->cancel($admin, 'Recorded in error');
    });

    loginSalesWithNoteTestUser($domain);

    $this->get("http://{$domain}/reports/sales-with-note?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 0)
            ->where('totals.count', 0)
            // An empty period totals to the 2-decimal string "0.00", not 0.
            ->where('totals.total', '0.00')
        );

    $tenant->delete();
});

test('a sale with a note outside the date range is excluded from the sales with note report', function () {
    $domain = 'sales-with-note-daterange.tenant-test';
    $tenant = provisionSalesWithNoteTestTenant($domain);

    $tenant->run(function () {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget', 'is_vatable' => false, 'is_stockable' => false]);

        Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-07-15', 'payment_mode' => 'cash', 'narration' => 'Out of range note'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 999, 'discount' => 0]],
            $admin,
        );
    });

    loginSalesWithNoteTestUser($domain);

    $this->get("http://{$domain}/reports/sales-with-note?from=2026-06-01&to=2026-06-30")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 0)
            ->where('totals.count', 0)
            ->where('totals.total', '0.00')
        );

    $tenant->delete();
});

test('the sales with note report can be narrowed to a single store', function () {
    $domain = 'sales-with-note-store-filter.tenant-test';
    $tenant = provisionSalesWithNoteTestTenant($domain);

    $storeAId = null;
    $tenant->run(function () use (&$storeAId) {
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $admin = User::factory()->create(['email' => 'owner@example.com']);
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget', 'is_vatable' => false, 'is_stockable' => false]);
        $storeA = Store::where('is_active', true)->orderBy('id')->firstOrFail();
        $storeB = Store::factory()->create(['name' => 'Branch Store']);

        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $storeA->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash', 'narration' => 'Store A note'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );
        Sale::post(
            ['customer_id' => $customer->id, 'store_id' => $storeB->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash', 'narration' => 'Store B note'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $storeAId = $storeA->id;
    });

    loginSalesWithNoteTestUser($domain);

    $this->get("http://{$domain}/reports/sales-with-note?from=2026-06-01&to=2026-06-30&store_id={$storeAId}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sales', 1)
            ->where('sales.0.note', 'Store A note')
            ->where('totals.count', 1)
            ->where('totals.total', '100.00')
        );

    $tenant->delete();
});
