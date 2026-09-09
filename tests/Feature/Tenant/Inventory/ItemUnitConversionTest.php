<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\ItemUnit;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

/*
|--------------------------------------------------------------------------
| ItemUnit (alternate unit) stock conversion
|--------------------------------------------------------------------------
|
| Covers the exact gap the audit found in legacy day_khata's
| InventorysettingDetails/`equals`: a line entered in an alt unit (e.g.
| "2 Box") must deduct/add BASE-unit stock (2 * conversion_factor), while
| the money math (quantity/rate/line_total) stays exactly as entered - see
| Sale::post()/Purchase::post()'s $baseQuantity. The no-alt-unit regression
| test guards the other half of the contract: an item with zero ItemUnit
| rows must behave byte-for-byte as it did before this feature existed.
|
*/

function provisionItemUnitTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function itemUnitTestAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

function itemUnitTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('selling an alt unit deducts base-unit stock by the conversion factor and stores the as-entered quantity/unit on the line', function () {
    $tenant = provisionItemUnitTestTenant('item-unit-sale-alt.tenant-test');

    $tenant->run(function () {
        itemUnitTestOpenFiscalYear();
        $admin = itemUnitTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => true]);
        $box = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Box', 'conversion_factor' => 12]);

        // Stock the item first (in the alt unit too) so the sale below
        // doesn't trip the negative-stock guard - also exercises the
        // purchase side of the same conversion.
        Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 5, 'rate' => 1000]],
            $admin,
        );
        expect($item->fresh()->currentStock())->toBe(60.0); // 5 Box * 12 = 60 pcs

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 2, 'rate' => 1100]],
            $admin,
        );

        $line = $sale->lines()->firstOrFail();
        // As-entered quantity/rate/unit are exactly what was submitted -
        // the printed invoice must read "2 Box @ 1100", not "24 pcs".
        expect((float) $line->quantity)->toBe(2.0)
            ->and($line->item_unit_id)->toBe($box->id)
            ->and((float) $line->unit_conversion_factor)->toBe(12.0)
            ->and((float) $line->rate)->toBe(1100.0)
            ->and((float) $line->line_total)->toBe(2200.0); // money math untouched by the conversion

        // Stock deduction is base-unit: 2 Box * 12 = 24 pcs out.
        $movement = ItemStockMovement::where('item_id', $item->id)
            ->where('movement_type', StockMovementType::Sale)
            ->firstOrFail();
        expect((float) $movement->quantity)->toBe(24.0)
            ->and($item->fresh()->currentStock())->toBe(36.0); // 60 - 24
    });

    $tenant->delete();
});

test('a unit belonging to a different item is rejected', function () {
    $tenant = provisionItemUnitTestTenant('item-unit-cross-item.tenant-test');

    $tenant->run(function () {
        itemUnitTestOpenFiscalYear();
        $admin = itemUnitTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_stockable' => true]);
        $otherItem = Item::factory()->create();
        $foreignUnit = ItemUnit::factory()->create(['item_id' => $otherItem->id, 'name' => 'Box', 'conversion_factor' => 12]);

        CompanySetting::current()->update(['allow_negative_stock' => true]);

        expect(fn () => Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'item_unit_id' => $foreignUnit->id, 'quantity' => 1, 'rate' => 100]],
            $admin,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('an item with no ItemUnit rows behaves exactly as before unit conversion existed', function () {
    $tenant = provisionItemUnitTestTenant('item-unit-regression.tenant-test');

    $tenant->run(function () {
        itemUnitTestOpenFiscalYear();
        $admin = itemUnitTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['unit' => 'pcs', 'is_vatable' => false, 'is_stockable' => true]);

        expect($item->units)->toBeEmpty();

        Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 50]],
            $admin,
        );
        expect($item->fresh()->currentStock())->toBe(10.0);

        // No item_unit_id sent at all - exactly the payload shape every
        // pre-existing caller (and every pre-existing test) already uses.
        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 4, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        $line = $sale->lines()->firstOrFail();
        expect($line->item_unit_id)->toBeNull()
            ->and((float) $line->unit_conversion_factor)->toBe(1.0) // conversion factor of 1 - a pure no-op
            ->and((float) $line->quantity)->toBe(4.0)
            ->and((float) $line->line_total)->toBe(400.0);

        // Base-unit quantity == as-entered quantity, unchanged from before
        // this feature existed.
        $movement = ItemStockMovement::where('item_id', $item->id)
            ->where('movement_type', StockMovementType::Sale)
            ->firstOrFail();
        expect((float) $movement->quantity)->toBe(4.0)
            ->and($item->fresh()->currentStock())->toBe(6.0); // 10 - 4
    });

    $tenant->delete();
});
