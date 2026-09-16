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

/**
 * Bonus / free quantity on a sale line (T12 item 5, audit section 3 "Sales").
 *
 * The rule the whole feature rests on: free units are real stock and no money
 * at all. They leave the shelf with the paid units (so the stock movement, the
 * negative-stock check and the printed bill all have to see them) and they
 * never reach DocumentCalculator (so revenue, VAT, TDS and the customer's
 * balance are exactly what the paid quantity alone would have produced).
 */
uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionSaleBonusTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function saleBonusTestAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

function saleBonusTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

test('a bonus quantity of 2 on a sale of 10 moves 12 units of stock but bills for 10', function () {
    $tenant = provisionSaleBonusTestTenant('sale-bonus-stock.tenant-test');

    $tenant->run(function () {
        saleBonusTestOpenFiscalYear();
        $admin = saleBonusTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true]);

        // 12 in stock: exactly the paid quantity plus the free units, so this
        // also proves the negative-stock check counts the bonus (13 would hide
        // an off-by-the-bonus error).
        Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 12, 'rate' => 50]],
            $admin,
        );

        expect(CompanySetting::current()->allow_negative_stock)->toBeFalse();

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'bonus_quantity' => 2, 'rate' => 100, 'discount' => 0]],
            $admin,
        );

        // Money: 10 x 100 only. The two free pieces are worth nothing to the
        // books, so nothing about the invoice hints at 12 x 100.
        expect($sale->taxable_amount)->toBe('1000.00')
            ->and($sale->vat_amount)->toBe('130.00')
            ->and($sale->total)->toBe('1130.00');

        $line = $sale->lines()->firstOrFail();
        expect($line->quantity)->toBe('10.0000')
            ->and($line->bonus_quantity)->toBe('2.0000')
            ->and($line->line_total)->toBe('1000.00');

        // Stock: all 12 pieces left the shelf.
        $movement = ItemStockMovement::where('item_id', $item->id)
            ->where('movement_type', StockMovementType::Sale)
            ->firstOrFail();

        expect($movement->quantity)->toBe('12.0000')
            ->and($item->fresh()->currentStock()->toString())->toBe('0.0000');
    });

    $tenant->delete();
});

test('the free units are converted to base units by the line unit factor, like the paid ones', function () {
    $tenant = provisionSaleBonusTestTenant('sale-bonus-unit.tenant-test');

    $tenant->run(function () {
        saleBonusTestOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = saleBonusTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);
        $box = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Box', 'conversion_factor' => 12]);

        // Buy 1 box, get 1 box free: 2 boxes of 12 leave the shelf, and the
        // bill is for one box.
        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'bonus_quantity' => 1, 'rate' => 600]],
            $admin,
        );

        expect($sale->total)->toBe('600.00');

        $movement = ItemStockMovement::where('item_id', $item->id)
            ->where('movement_type', StockMovementType::Sale)
            ->firstOrFail();

        expect($movement->quantity)->toBe('24.0000')
            ->and($item->fresh()->currentStock()->toString())->toBe('-24.0000');
    });

    $tenant->delete();
});

test('a sale is refused when the free units are what push it past the available stock', function () {
    $tenant = provisionSaleBonusTestTenant('sale-bonus-shortage.tenant-test');

    $tenant->run(function () {
        saleBonusTestOpenFiscalYear();
        $admin = saleBonusTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true, 'name' => 'Bonus Short Widget']);

        // 10 in stock covers the paid quantity exactly; the 2 free pieces do
        // not exist. Counting only the paid quantity would let this through
        // and leave the store 2 short with nobody told.
        Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 50]],
            $admin,
        );

        expect(fn () => Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'bonus_quantity' => 2, 'rate' => 100]],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'Bonus Short Widget');

        expect($item->fresh()->currentStock()->toString())->toBe('10.0000');
    });

    $tenant->delete();
});

test('a negative bonus quantity is refused rather than quietly taking stock back', function () {
    $tenant = provisionSaleBonusTestTenant('sale-bonus-negative.tenant-test');

    $tenant->run(function () {
        saleBonusTestOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = saleBonusTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        expect(fn () => Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 5, 'bonus_quantity' => '-1', 'rate' => 100]],
            $admin,
        ))->toThrow(InvalidArgumentException::class, 'Bonus quantity cannot be negative.');
    });

    $tenant->delete();
});

test('an omitted bonus quantity stores 0 and moves only the paid quantity', function () {
    $tenant = provisionSaleBonusTestTenant('sale-bonus-absent.tenant-test');

    $tenant->run(function () {
        saleBonusTestOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = saleBonusTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => false, 'is_stockable' => true]);

        $sale = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'cash'],
            [['item_id' => $item->id, 'quantity' => 3, 'rate' => 100]],
            $admin,
        );

        expect($sale->lines()->firstOrFail()->bonus_quantity)->toBe('0.0000')
            ->and($item->fresh()->currentStock()->toString())->toBe('-3.0000');
    });

    $tenant->delete();
});

test('the printed bill shows the free units in their own column, and omits the column when there are none', function () {
    $tenant = provisionSaleBonusTestTenant('sale-bonus-print.tenant-test');

    $tenant->run(function () {
        saleBonusTestOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $admin = saleBonusTestAdmin();
        $customer = Customer::factory()->create();
        $item = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true, 'name' => 'Bonus Widget']);

        $withBonus = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'bonus_quantity' => 2, 'rate' => 100]],
            $admin,
        );

        $html = view('pdf.sale', [
            'sale' => $withBonus->fresh(['customer', 'agent', 'bankAccount', 'lines.item', 'lines.itemUnit']),
            'company' => CompanySetting::current(),
            'documentNumber' => $withBonus->invoice_number,
            'documentDate' => '2026-06-02',
        ])->render();

        // The customer sees the free pieces, and the money columns still only
        // know about the ten that were charged for.
        $itemsBlock = substr($html, (int) strpos($html, 'items-table'), (int) strpos($html, 'totals-table') - (int) strpos($html, 'items-table'));

        expect($itemsBlock)->toContain('Free')
            ->and($itemsBlock)->toContain('>2<')
            ->and($itemsBlock)->toContain('>10<');

        expect($html)->toContain('1,130.00')->not->toContain('1,356.00');

        // A bill that gave nothing away prints exactly as it always did.
        $withoutBonus = Sale::post(
            ['customer_id' => $customer->id, 'invoice_type' => 'full', 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 100]],
            $admin,
        );

        $plainHtml = view('pdf.sale', [
            'sale' => $withoutBonus->fresh(['customer', 'agent', 'bankAccount', 'lines.item', 'lines.itemUnit']),
            'company' => CompanySetting::current(),
            'documentNumber' => $withoutBonus->invoice_number,
            'documentDate' => '2026-06-02',
        ])->render();

        $plainItemsBlock = substr(
            $plainHtml,
            (int) strpos($plainHtml, 'items-table'),
            (int) strpos($plainHtml, 'totals-table') - (int) strpos($plainHtml, 'items-table'),
        );

        expect($plainItemsBlock)->not->toContain('Free');
    });

    $tenant->delete();
});

test('the sale endpoint accepts a bonus quantity on a line', function () {
    $domain = 'sale-bonus-http.tenant-test';
    $tenant = provisionSaleBonusTestTenant($domain);

    $itemId = null;
    $customerId = null;
    $tenant->run(function () use (&$itemId, &$customerId) {
        saleBonusTestOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        User::factory()->create(['email' => 'owner@example.com', 'role_id' => Role::where('slug', 'admin')->value('id')]);
        $customerId = Customer::factory()->create()->id;
        $itemId = Item::factory()->create(['is_vatable' => true, 'is_stockable' => true])->id;
    });

    $this->post("http://{$domain}/login", ['email' => 'owner@example.com', 'password' => 'password']);

    $this->post("http://{$domain}/sales", [
        'customer_id' => $customerId,
        'invoice_type' => 'full',
        'date' => '2026-06-02',
        'payment_mode' => 'credit',
        'expected_total' => '1130.00',
        'lines' => [
            ['item_id' => $itemId, 'quantity' => '10', 'bonus_quantity' => '2', 'rate' => '100', 'discount' => '0', 'discount_type' => 'flat'],
        ],
    ])->assertSessionHasNoErrors();

    $tenant->run(function () {
        $sale = Sale::latest('id')->firstOrFail();

        expect($sale->total)->toBe('1130.00')
            ->and($sale->lines()->firstOrFail()->bonus_quantity)->toBe('2.0000');
    });

    $tenant->delete();
});
