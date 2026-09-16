<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * T13 item 9: purchase form polish. The server-side plumbing for the
 * per-line note, the bonus-quantity input and the "quick add item" link all
 * pre-dated this pass - PurchaseController::store() already validated
 * lines.*.note/lines.*.bonus_quantity, and ItemController::store()/
 * lookupBarcode() already existed for T13 item 6. This file exercises the
 * HTTP round trip Purchases/Create.vue now drives end to end.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseFormFieldsTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function loginPurchaseFormFieldsUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('a purchase line note and bonus quantity round-trip through the store route', function () {
    $domain = 'purchase-form-fields-note.tenant-test';
    $tenant = provisionPurchaseFormFieldsTenant($domain);

    $supplierId = null;
    $itemId = null;
    $tenant->run(function () use (&$supplierId, &$itemId) {
        User::factory()->create(['email' => 'owner@example.com']);
        FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
        $supplierId = Supplier::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true])->id;
    });

    loginPurchaseFormFieldsUser($domain);

    $this->post("http://{$domain}/purchases", [
        'supplier_id' => $supplierId,
        'date' => '2026-06-01',
        'payment_mode' => 'cash',
        'lines' => [
            ['item_id' => $itemId, 'quantity' => 10, 'bonus_quantity' => 2, 'rate' => 100, 'note' => 'Batch #42'],
        ],
    ])->assertRedirect("http://{$domain}/purchases");

    $tenant->run(function () {
        $purchase = Purchase::query()->firstOrFail();
        /** @var PurchaseLine $line */
        $line = $purchase->lines()->firstOrFail();

        expect($line->note)->toBe('Batch #42')
            ->and($line->bonus_quantity)->toBe('2.0000');
    });

    $tenant->delete();
});

test('the quick add-item link posts to the same item-store endpoint the Items page uses', function () {
    $domain = 'purchase-form-fields-quick-item.tenant-test';
    $tenant = provisionPurchaseFormFieldsTenant($domain);

    $categoryId = null;
    $tenant->run(function () use (&$categoryId) {
        User::factory()->create(['email' => 'owner@example.com']);
        $categoryId = ItemCategory::factory()->create()->id;
    });

    loginPurchaseFormFieldsUser($domain);

    $this->post("http://{$domain}/items", [
        'item_category_id' => $categoryId,
        'name' => 'Quick Added Item',
        'unit' => 'pcs',
    ])->assertRedirect("http://{$domain}/items");

    $tenant->run(function () {
        expect(Item::query()->where('name', 'Quick Added Item')->exists())->toBeTrue();
    });

    $tenant->delete();
});

test('scan-to-add resolves a base-unit barcode the same way the purchase form calls it', function () {
    $domain = 'purchase-form-fields-barcode.tenant-test';
    $tenant = provisionPurchaseFormFieldsTenant($domain);

    $tenant->run(function () {
        User::factory()->create(['email' => 'owner@example.com']);
        Item::factory()->create(['barcode' => 'PUR-SCAN-1', 'name' => 'Scanned Item']);
    });

    loginPurchaseFormFieldsUser($domain);

    $this->getJson("http://{$domain}/items/lookup-barcode?code=PUR-SCAN-1")
        ->assertOk()
        ->assertJsonPath('item.name', 'Scanned Item')
        ->assertJsonPath('item_unit_id', null);

    $tenant->delete();
});
