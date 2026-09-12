<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseControllerTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginPurchaseControllerTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('the purchases index page renders', function () {
    $domain = 'purchases-index-render.tenant-test';
    $tenant = provisionPurchaseControllerTestTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
    });

    loginPurchaseControllerTestUser($domain);

    $this->get("http://{$domain}/purchases")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Tenant/Purchases/Index'));

    $tenant->delete();
});

test('an authenticated user can post a purchase through the store route', function () {
    $domain = 'purchases-store-http.tenant-test';
    $tenant = provisionPurchaseControllerTestTenant($domain);

    $supplierId = null;
    $itemId = null;
    $tenant->run(function () use (&$supplierId, &$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplierId = Supplier::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => true])->id;
    });

    loginPurchaseControllerTestUser($domain);

    $this->post("http://{$domain}/purchases", [
        'supplier_id' => $supplierId,
        'date' => '2026-06-01',
        'payment_mode' => 'cash',
        'lines' => [
            ['item_id' => $itemId, 'quantity' => 2, 'rate' => 250],
        ],
    ])->assertRedirect("http://{$domain}/purchases");

    $tenant->run(function () {
        expect(Purchase::query()->count())->toBe(1);
    });

    $tenant->delete();
});

test('posting a purchase with an invalid payment mode is rejected by validation', function () {
    $domain = 'purchases-store-invalid-mode.tenant-test';
    $tenant = provisionPurchaseControllerTestTenant($domain);

    $supplierId = null;
    $itemId = null;
    $tenant->run(function () use (&$supplierId, &$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplierId = Supplier::factory()->create()->id;
        $itemId = Item::factory()->create()->id;
    });

    loginPurchaseControllerTestUser($domain);

    $this->post("http://{$domain}/purchases", [
        'supplier_id' => $supplierId,
        'date' => '2026-06-01',
        'payment_mode' => 'cheque',
        'lines' => [
            ['item_id' => $itemId, 'quantity' => 1, 'rate' => 100],
        ],
    ])->assertSessionHasErrors('payment_mode');

    $tenant->run(function () {
        expect(Purchase::query()->count())->toBe(0);
    });

    $tenant->delete();
});

test('an authenticated user can cancel a posted purchase through the cancel route', function () {
    $domain = 'purchases-cancel-http.tenant-test';
    $tenant = provisionPurchaseControllerTestTenant($domain);

    $purchaseId = null;
    $tenant->run(function () use (&$purchaseId) {
        // Cancelling is admin only now (CONTRACTS C5), so the logged-in user
        // for these two tests carries the admin role.
        $actor = User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100]],
            $actor,
        );
        $purchaseId = $purchase->id;
    });

    loginPurchaseControllerTestUser($domain);

    $this->post("http://{$domain}/purchases/{$purchaseId}/cancel", [
        'reason' => 'Entered by mistake',
    ])->assertRedirect("http://{$domain}/purchases");

    $tenant->run(function () use ($purchaseId) {
        expect(Purchase::query()->findOrFail($purchaseId)->status)->toBe('cancelled');
    });

    $tenant->delete();
});

test('cancelling a purchase without a reason fails validation', function () {
    $domain = 'purchases-cancel-no-reason.tenant-test';
    $tenant = provisionPurchaseControllerTestTenant($domain);

    $purchaseId = null;
    $tenant->run(function () use (&$purchaseId) {
        // Cancelling is admin only now (CONTRACTS C5), so the logged-in user
        // for these two tests carries the admin role.
        $actor = User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100]],
            $actor,
        );
        $purchaseId = $purchase->id;
    });

    loginPurchaseControllerTestUser($domain);

    $this->post("http://{$domain}/purchases/{$purchaseId}/cancel", [])
        ->assertSessionHasErrors('reason');

    $tenant->run(function () use ($purchaseId) {
        expect(Purchase::query()->findOrFail($purchaseId)->status)->toBe('posted');
    });

    $tenant->delete();
});

test('the store route rejects a supplier bill number that is already on a live purchase', function () {
    $domain = 'purchases-duplicate-bill-http.tenant-test';
    $tenant = provisionPurchaseControllerTestTenant($domain);

    $supplierId = null;
    $itemId = null;
    $tenant->run(function () use (&$supplierId, &$itemId) {
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplierId = Supplier::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false])->id;
    });

    loginPurchaseControllerTestUser($domain);

    $payload = [
        'supplier_id' => $supplierId,
        'bill_number' => 'INV-4410',
        'date' => '2026-06-01',
        'payment_mode' => 'credit',
        'lines' => [['item_id' => $itemId, 'quantity' => '1', 'rate' => '100']],
    ];

    $this->post("http://{$domain}/purchases", $payload)->assertRedirect();

    // The message belongs on the Bill Number field, not buried in a line error.
    $this->post("http://{$domain}/purchases", $payload)->assertSessionHasErrors('bill_number');

    $purchaseId = null;
    $tenant->run(function () use (&$purchaseId) {
        expect(Purchase::count())->toBe(1);
        $purchaseId = Purchase::firstOrFail()->id;
    });

    // Cancelling the first one frees the number for a replacement entry.
    $this->post("http://{$domain}/purchases/{$purchaseId}/cancel", ['reason' => 'Wrong supplier'])->assertRedirect();
    $this->post("http://{$domain}/purchases", $payload)->assertSessionHasNoErrors();

    $tenant->run(function () {
        expect(Purchase::where('status', 'posted')->count())->toBe(1);
    });

    $tenant->delete();
});
