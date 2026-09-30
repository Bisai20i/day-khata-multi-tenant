<?php

use App\Enums\FiscalYearStatus;
use App\Enums\StockMovementType;
use App\Models\Agent;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Flags G-05 (a back-dated sale cannot drive a later day negative), G-13
 * (retired items and stores take no new documents) and G-15 (a commission
 * needs an agent).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSaleGuardsTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function saleGuardsAdmin(): User
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);

    return User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
}

function saleGuardsSale(User $admin, Item $item, string $date, string $quantity): Sale
{
    return Sale::post(
        ['customer_id' => Customer::factory()->create()->id, 'invoice_type' => 'full', 'date' => $date, 'payment_mode' => 'cash'],
        [['item_id' => $item->id, 'quantity' => $quantity, 'rate' => 100, 'discount' => 0]],
        $admin,
    );
}

test('a back-dated sale is refused when it would take a later day below zero', function () {
    $tenant = provisionSaleGuardsTenant('sale-guard-backdated.tenant-test');

    $tenant->run(function () {
        $admin = saleGuardsAdmin();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
        $storeId = Store::where('is_active', true)->orderBy('id')->value('id');

        // 10 in on the 1st, 10 sold on the 3rd, 10 more in on the 5th.
        $item->recordStockMovement(StockMovementType::Opening, 10, '2026-06-01', $storeId);
        saleGuardsSale($admin, $item, '2026-06-03', '10');
        $item->recordStockMovement(StockMovementType::AdjustmentIn, 10, '2026-06-05', $storeId);

        // Today there are 10 on hand, and on the 2nd there were 10, but
        // selling 5 on the 2nd would leave the 3rd at -5.
        expect(fn () => saleGuardsSale($admin, $item, '2026-06-02', '5'))
            ->toThrow(InvalidArgumentException::class, 'Insufficient stock');

        // After the 5th's delivery a sale is fine, and so is today's.
        expect(saleGuardsSale($admin, $item, '2026-06-06', '5')->status)->toBe('posted')
            ->and($item->lowestStockFrom('2026-06-01', $storeId)->toString())->toBe('0.0000');
    });

    $tenant->delete();
});

test('a sale dated today still checks just today\'s stock', function () {
    $tenant = provisionSaleGuardsTenant('sale-guard-today.tenant-test');

    $tenant->run(function () {
        $admin = saleGuardsAdmin();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
        $storeId = Store::where('is_active', true)->orderBy('id')->value('id');
        $item->recordStockMovement(StockMovementType::Opening, 3, '2026-06-01', $storeId);

        expect(saleGuardsSale($admin, $item, '2026-06-10', '3')->status)->toBe('posted')
            ->and(fn () => saleGuardsSale($admin, $item, '2026-06-10', '1'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('inactive items and stores are refused on sales and purchases', function () {
    $domain = 'sale-guard-inactive.tenant-test';
    $tenant = provisionSaleGuardsTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        saleGuardsAdmin();
        $ids = [
            'customer' => Customer::factory()->create()->id,
            'supplier' => Supplier::factory()->create()->id,
            'inactiveItem' => Item::factory()->create(['is_active' => false, 'is_stockable' => false])->id,
            'activeItem' => Item::factory()->create(['is_stockable' => false])->id,
            'inactiveStore' => Store::create(['name' => 'Closed branch', 'is_active' => false])->id,
        ];
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $sale = fn (array $overrides) => array_replace_recursive([
        'customer_id' => $ids['customer'], 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash',
        'lines' => [['item_id' => $ids['activeItem'], 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
    ], $overrides);

    $this->post("http://{$domain}/sales", $sale(['lines' => [['item_id' => $ids['inactiveItem']]]]))->assertSessionHasErrors('lines.0.item_id');
    $this->post("http://{$domain}/sales", $sale(['store_id' => $ids['inactiveStore']]))->assertSessionHasErrors('store_id');

    $purchase = fn (array $overrides) => array_replace_recursive([
        'supplier_id' => $ids['supplier'], 'date' => '2026-06-01', 'payment_mode' => 'cash', 'expected_total' => '100.00',
        'lines' => [['item_id' => $ids['activeItem'], 'quantity' => 1, 'rate' => 100]],
    ], $overrides);

    $this->post("http://{$domain}/purchases", $purchase(['lines' => [['item_id' => $ids['inactiveItem']]]]))->assertSessionHasErrors('lines.0.item_id');
    $this->post("http://{$domain}/purchases", $purchase(['store_id' => $ids['inactiveStore']]))->assertSessionHasErrors('store_id');

    $tenant->delete();
});

test('a commission without a sales agent is refused', function () {
    $domain = 'sale-guard-commission.tenant-test';
    $tenant = provisionSaleGuardsTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        $admin = saleGuardsAdmin();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => false]);
        $customer = Customer::factory()->create();
        $ids = ['customer' => $customer->id, 'item' => $item->id];

        expect(fn () => Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash', 'commission_amount' => '10'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'sales agent');

        // With an agent it posts.
        $agent = Agent::factory()->create();
        expect(Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash', 'agent_id' => $agent->id, 'commission_amount' => '10'],
            [['item_id' => $item->id, 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
            $admin,
        )->commission_amount)->toBe('10.00');
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/sales", [
        'customer_id' => $ids['customer'], 'invoice_type' => 'full', 'date' => '2026-06-01', 'payment_mode' => 'cash',
        'commission_amount' => '10',
        'lines' => [['item_id' => $ids['item'], 'quantity' => 1, 'rate' => 100, 'discount' => 0]],
    ])->assertSessionHasErrors('agent_id');

    $tenant->delete();
});
