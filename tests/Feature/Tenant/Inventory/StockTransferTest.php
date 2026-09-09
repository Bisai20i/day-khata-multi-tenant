<?php

use App\Enums\StockMovementType;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionStockTransferTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function stockTransferTestActor(): User
{
    return User::factory()->create();
}

test('transferring stock between two stores decreases source stock and increases destination stock', function () {
    $tenant = provisionStockTransferTestTenant('stock-transfer-basic.tenant-test');

    $tenant->run(function () {
        $actor = stockTransferTestActor();
        $item = Item::factory()->create(['is_stockable' => true]);
        $storeA = Store::factory()->create(['is_active' => true]);
        $storeB = Store::factory()->create(['is_active' => true]);

        // Give store A 10 units of opening stock to transfer out of.
        StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $storeA->id],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => 10]],
            $actor,
        );

        $transfer = StockTransfer::post(
            ['date' => '2026-06-02', 'from_store_id' => $storeA->id, 'to_store_id' => $storeB->id, 'note' => 'Rebalance'],
            [['item_id' => $item->id, 'quantity' => 4, 'unit_cost_rate' => 12.5]],
            $actor,
        );

        expect($item->fresh()->currentStock($storeA->id))->toBe(6.0)
            ->and($item->fresh()->currentStock($storeB->id))->toBe(4.0)
            ->and($item->fresh()->currentStock())->toBe(10.0)
            ->and((float) $transfer->total_value)->toBe(50.0);

        $line = $transfer->lines->first();
        $movements = ItemStockMovement::query()
            ->where('reference_type', (new StockTransferLine)->getMorphClass())
            ->where('reference_id', $line->id)
            ->get();

        expect($movements)->toHaveCount(2);

        $outMovement = $movements->firstWhere('movement_type', StockMovementType::TransferOut);
        $inMovement = $movements->firstWhere('movement_type', StockMovementType::TransferIn);

        expect($outMovement)->not->toBeNull()
            ->and($inMovement)->not->toBeNull()
            ->and($outMovement->store_id)->toBe($storeA->id)
            ->and((float) $outMovement->quantity)->toBe(4.0)
            ->and($inMovement->store_id)->toBe($storeB->id)
            ->and((float) $inMovement->quantity)->toBe(4.0)
            ->and($outMovement->date->toDateString())->toBe($inMovement->date->toDateString());
    });

    $tenant->delete();
});

test('a transfer to the same store is rejected', function () {
    $tenant = provisionStockTransferTestTenant('stock-transfer-same-store.tenant-test');

    $tenant->run(function () {
        $actor = stockTransferTestActor();
        $item = Item::factory()->create(['is_stockable' => true]);
        $store = Store::factory()->create(['is_active' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $store->id],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => 10]],
            $actor,
        );

        expect(fn () => StockTransfer::post(
            ['date' => '2026-06-02', 'from_store_id' => $store->id, 'to_store_id' => $store->id],
            [['item_id' => $item->id, 'quantity' => 4]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect($item->fresh()->currentStock($store->id))->toBe(10.0);
    });

    $tenant->delete();
});

test('a transfer exceeding the source store\'s available stock is rejected', function () {
    $tenant = provisionStockTransferTestTenant('stock-transfer-oversell.tenant-test');

    $tenant->run(function () {
        $actor = stockTransferTestActor();
        $item = Item::factory()->create(['is_stockable' => true]);
        $storeA = Store::factory()->create(['is_active' => true]);
        $storeB = Store::factory()->create(['is_active' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $storeA->id],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => 3]],
            $actor,
        );

        expect(fn () => StockTransfer::post(
            ['date' => '2026-06-02', 'from_store_id' => $storeA->id, 'to_store_id' => $storeB->id],
            [['item_id' => $item->id, 'quantity' => 4]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect($item->fresh()->currentStock($storeA->id))->toBe(3.0)
            ->and($item->fresh()->currentStock($storeB->id))->toBe(0.0);
    });

    $tenant->delete();
});

test('cancelling a transfer reverts the stock impact at both stores and rejects double-cancellation', function () {
    $tenant = provisionStockTransferTestTenant('stock-transfer-cancel.tenant-test');

    $tenant->run(function () {
        $actor = stockTransferTestActor();
        $item = Item::factory()->create(['is_stockable' => true]);
        $storeA = Store::factory()->create(['is_active' => true]);
        $storeB = Store::factory()->create(['is_active' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01', 'store_id' => $storeA->id],
            [['item_id' => $item->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => 8]],
            $actor,
        );

        $transfer = StockTransfer::post(
            ['date' => '2026-06-02', 'from_store_id' => $storeA->id, 'to_store_id' => $storeB->id],
            [['item_id' => $item->id, 'quantity' => 5]],
            $actor,
        );

        expect($item->fresh()->currentStock($storeA->id))->toBe(3.0)
            ->and($item->fresh()->currentStock($storeB->id))->toBe(5.0);

        $transfer->cancel($actor, 'Recorded in error');

        expect($item->fresh()->currentStock($storeA->id))->toBe(8.0)
            ->and($item->fresh()->currentStock($storeB->id))->toBe(0.0)
            ->and($transfer->fresh()->status)->toBe('cancelled');

        expect(fn () => $transfer->cancel($actor, 'Again'))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});

test('a zero or negative quantity is rejected at the model layer', function () {
    $tenant = provisionStockTransferTestTenant('stock-transfer-bad-quantity.tenant-test');

    $tenant->run(function () {
        $actor = stockTransferTestActor();
        $item = Item::factory()->create(['is_stockable' => true]);
        $storeA = Store::factory()->create(['is_active' => true]);
        $storeB = Store::factory()->create(['is_active' => true]);

        expect(fn () => StockTransfer::post(
            ['date' => '2026-06-01', 'from_store_id' => $storeA->id, 'to_store_id' => $storeB->id],
            [['item_id' => $item->id, 'quantity' => 0]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);

        expect(fn () => StockTransfer::post(
            ['date' => '2026-06-01', 'from_store_id' => $storeA->id, 'to_store_id' => $storeB->id],
            [['item_id' => $item->id, 'quantity' => -5]],
            $actor,
        ))->toThrow(InvalidArgumentException::class);
    });

    $tenant->delete();
});
