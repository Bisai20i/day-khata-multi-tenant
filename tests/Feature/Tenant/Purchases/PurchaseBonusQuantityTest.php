<?php

use App\Enums\FiscalYearStatus;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\ItemUnit;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Inventory\StockCosting;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * T13 item 3: bonus / free quantity on purchase lines. Stock takes
 * (quantity + bonus) x factor; the money side never sees the bonus, so the
 * weighted average cost per unit falls by exactly the right amount and the
 * free units go back to the supplier crediting nothing.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionPurchaseBonusTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function purchaseBonusOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function purchaseBonusMovementFor(PurchaseLine $line): ItemStockMovement
{
    return ItemStockMovement::where('reference_type', (new PurchaseLine)->getMorphClass())
        ->where('reference_id', $line->id)
        ->firstOrFail();
}

test('a bonus quantity moves extra stock while the cost basis stays on the paid value', function () {
    $tenant = provisionPurchaseBonusTenant('purchase-bonus-stock.tenant-test');

    $tenant->run(function () {
        purchaseBonusOpenFiscalYear();
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        // 20 paid at 100.00 plus 5 free: 2,000.00 of goods, 25 pieces.
        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '20', 'bonus_quantity' => '5', 'rate' => '100']],
            $actor,
        );

        $line = $purchase->lines()->firstOrFail();

        expect($line->quantity)->toBe('20.0000')
            ->and($line->bonus_quantity)->toBe('5.0000')
            // The bill is for the paid units only: 20 x 100 = 2,000, VAT 260.
            ->and($line->line_total)->toBe('2000.00')
            ->and($line->net_value)->toBe('2000.00')
            ->and($purchase->taxable_amount)->toBe('2000.00')
            ->and($purchase->vat_amount)->toBe('260.00')
            ->and($purchase->total)->toBe('2260.00');

        $movement = purchaseBonusMovementFor($line);

        expect($movement->quantity)->toBe('25.0000')
            // Value is what was PAID. Adding the free units here would price
            // them at 100 each and overstate stock by 500.
            ->and($movement->value)->toBe('2000.00')
            // 2,000.00 / 25 = 80.0000, the correct fall from the 100.00 rate.
            ->and($movement->unit_cost_rate)->toBe('80.0000');

        expect($item->fresh()->currentStock()->toString())->toBe('25.0000')
            ->and((string) StockCosting::averageCost($item->fresh(), '2026-06-30')->toScale(4))->toBe('80.0000')
            ->and(StockCosting::closingValue($item->fresh(), '2026-06-30')->toString())->toBe('2000.00');
    });

    $tenant->delete();
});

test('a bonus quantity in an alternate unit converts to base units with the paid quantity', function () {
    $tenant = provisionPurchaseBonusTenant('purchase-bonus-unit.tenant-test');

    $tenant->run(function () {
        purchaseBonusOpenFiscalYear();
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);
        $box = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Box', 'conversion_factor' => 12]);

        // 10 Boxes bought, 1 Box free: 11 x 12 = 132 pieces for 12,000.00.
        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [[
                'item_id' => $item->id,
                'item_unit_id' => $box->id,
                'quantity' => '10',
                'bonus_quantity' => '1',
                'rate' => '1200',
            ]],
            $actor,
        );

        $movement = purchaseBonusMovementFor($purchase->lines()->firstOrFail());

        expect($movement->quantity)->toBe('132.0000')
            ->and($movement->value)->toBe('12000.00')
            // 12,000.00 / 132 = 90.909090..., rounded once to 4 decimals.
            ->and($movement->unit_cost_rate)->toBe('90.9091');
    });

    $tenant->delete();
});

test('a return that reaches into the free units credits only the paid ones', function () {
    $tenant = provisionPurchaseBonusTenant('purchase-bonus-return.tenant-test');

    $tenant->run(function () {
        purchaseBonusOpenFiscalYear();
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '20', 'bonus_quantity' => '5', 'rate' => '100']],
            $actor,
        );

        $line = $purchase->lines()->firstOrFail();

        // 22 pieces go back: the 20 paid ones plus 2 free ones. Paid units are
        // credited first, so the note is worth the whole line (2,000.00 plus
        // 260.00 VAT) and not a paisa more - the 2 free pieces credit nothing.
        $return = PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05', 'reason' => 'Damaged'],
            [['purchase_line_id' => $line->id, 'quantity' => '22']],
            $actor,
        );

        expect($return->taxable_amount)->toBe('2000.00')
            ->and($return->vat_amount)->toBe('260.00')
            ->and($return->total)->toBe('2260.00');

        /** @var PurchaseReturnLine $returnLine */
        $returnLine = $return->lines()->firstOrFail();

        expect($returnLine->quantity)->toBe('22.0000')
            ->and($returnLine->bonus_quantity)->toBe('2.0000')
            ->and($returnLine->net_value)->toBe('2000.00');

        $movement = ItemStockMovement::where('reference_type', (new PurchaseReturnLine)->getMorphClass())
            ->where('reference_id', $returnLine->id)
            ->firstOrFail();

        // All 22 pieces leave the store; the value leaving is the credited
        // 2,000.00, which empties this item's cost basis along with it.
        expect($movement->quantity)->toBe('22.0000')
            ->and($movement->value)->toBe('2000.00')
            ->and($item->fresh()->currentStock()->toString())->toBe('3.0000')
            ->and(StockCosting::closingValue($item->fresh(), '2026-06-30')->toString())->toBe('0.00');
    });

    $tenant->delete();
});

test('free units may go back to the supplier only alongside something that is credited', function () {
    $tenant = provisionPurchaseBonusTenant('purchase-bonus-zero-note.tenant-test');

    $tenant->run(function () {
        purchaseBonusOpenFiscalYear();
        $actor = User::factory()->create();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        $purchase = Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => '20', 'bonus_quantity' => '5', 'rate' => '100']],
            $actor,
        );

        $line = $purchase->lines()->firstOrFail();

        PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-05'],
            [['purchase_line_id' => $line->id, 'quantity' => '20']],
            $actor,
        );

        // The 20 paid pieces are spent, so a second note for the free ones
        // would be a debit note for 0.00. A zero-value document is refused
        // rather than posted: a free-goods-only return is a stock adjustment,
        // not a debit note.
        expect(fn () => PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-06'],
            [['purchase_line_id' => $line->id, 'quantity' => '5']],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'must credit more than zero');

        // The cap itself still counts the free units, so the 25th piece is the
        // first one that is genuinely over-returned.
        expect(fn () => PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-06'],
            [['purchase_line_id' => $line->id, 'quantity' => '6']],
            $actor,
        ))->toThrow(InvalidArgumentException::class, 'remain');
    });

    $tenant->delete();
});
