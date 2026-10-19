<?php

use App\Enums\FiscalYearStatus;
use App\Models\Account;
use App\Models\CapitalPurchase;
use App\Models\CapitalPurchaseSettlement;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalesReturn;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Posting lock order (flags PUR-20, PUR-21)
|--------------------------------------------------------------------------
|
| Every posting takes its locks in one order: the supplier row, the document
| rows, the stock items in ascending id, then the fiscal year (see
| Item::lockForStockOut()). SQLite drops FOR UPDATE, so these tests cannot
| prove a lock is held; they pin down the order the locking queries run in,
| which is what a refactor would silently break, and that tenants allowing
| negative stock can still go negative now that their items are locked too.
|
*/

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionLockOrderTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function lockOrderOpenFiscalYear(): void
{
    FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function lockOrderAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

/**
 * Runs $post with the tenant connection's query log on.
 *
 * @return list<array{query: string, bindings: array<int, mixed>}>
 */
function lockOrderQueryLog(Closure $post): array
{
    $connection = DB::connection('tenant');
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $post();

        return array_values(array_map(
            fn (array $entry): array => ['query' => $entry['query'], 'bindings' => $entry['bindings']],
            $connection->getQueryLog()
        ));
    } finally {
        $connection->disableQueryLog();
    }
}

/**
 * Position of the first query starting with $prefix (and containing $contains, when given).
 *
 * @param  list<array{query: string, bindings: array<int, mixed>}>  $log
 */
function lockOrderPosition(array $log, string $prefix, ?string $contains = null): int
{
    foreach ($log as $position => $entry) {
        if (str_starts_with($entry['query'], $prefix) && ($contains === null || str_contains($entry['query'], $contains))) {
            return $position;
        }
    }

    throw new RuntimeException("No query starting with [{$prefix}] ran.");
}

/**
 * Position of Item::lockForStockOut()'s query - the only items read ordered by id.
 *
 * @param  list<array{query: string, bindings: array<int, mixed>}>  $log
 */
function lockOrderItemLockPosition(array $log): int
{
    return lockOrderPosition($log, 'select * from "items" where "id" in', 'order by "id" asc');
}

/**
 * @param  list<array{query: string, bindings: array<int, mixed>}>  $log
 */
function lockOrderVoucherPosition(array $log): int
{
    return lockOrderPosition($log, 'insert into "journal_vouchers"');
}

/**
 * @param  list<array{query: string, bindings: array<int, mixed>}>  $log
 */
function lockOrderSupplierPosition(array $log): int
{
    return lockOrderPosition($log, 'select * from "suppliers" where "suppliers"."id" = ?');
}

/**
 * The item ids a lock query was run for, ascending.
 *
 * @param  array{query: string, bindings: array<int, mixed>}  $entry
 * @return list<int>
 */
function lockOrderLockedIds(array $entry): array
{
    return collect($entry['bindings'])->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
}

test('a purchase locks its supplier, then its stockable items, then the fiscal year, with negative stock allowed too', function () {
    $tenant = provisionLockOrderTenant('lock-order-purchase.tenant-test');

    $tenant->run(function () {
        lockOrderOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $first = Item::factory()->create(['is_stockable' => true]);
        $second = Item::factory()->create(['is_stockable' => true]);
        $service = Item::factory()->create(['is_stockable' => false]);
        $supplier = Supplier::factory()->create();
        $admin = lockOrderAdmin();

        $log = lockOrderQueryLog(fn () => Purchase::post(
            ['supplier_id' => $supplier->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [
                ['item_id' => $second->id, 'quantity' => 1, 'rate' => 10],
                ['item_id' => $service->id, 'quantity' => 1, 'rate' => 10],
                ['item_id' => $first->id, 'quantity' => 1, 'rate' => 10],
            ],
            $admin,
        ));

        $itemLock = lockOrderItemLockPosition($log);

        expect(lockOrderSupplierPosition($log))->toBeLessThan($itemLock)
            ->and($itemLock)->toBeLessThan(lockOrderVoucherPosition($log))
            ->and(lockOrderLockedIds($log[$itemLock]))->toBe([$first->id, $second->id]);
    });

    $tenant->delete();
});

test('a sale with negative stock allowed locks its stockable items before the fiscal year and still oversells', function () {
    $tenant = provisionLockOrderTenant('lock-order-sale.tenant-test');

    $tenant->run(function () {
        lockOrderOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $first = Item::factory()->create(['is_stockable' => true]);
        $second = Item::factory()->create(['is_stockable' => true]);
        $customer = Customer::factory()->create();
        $admin = lockOrderAdmin();

        $log = lockOrderQueryLog(fn () => Sale::post(
            ['customer_id' => $customer->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [
                ['item_id' => $second->id, 'quantity' => 3, 'rate' => 10],
                ['item_id' => $first->id, 'quantity' => 3, 'rate' => 10],
            ],
            $admin,
        ));

        $itemLock = lockOrderItemLockPosition($log);

        expect($itemLock)->toBeLessThan(lockOrderVoucherPosition($log))
            ->and(lockOrderLockedIds($log[$itemLock]))->toBe([$first->id, $second->id])
            ->and($first->fresh()->currentStock()->toString())->toBe('-3.0000')
            ->and($second->fresh()->currentStock()->toString())->toBe('-3.0000');
    });

    $tenant->delete();
});

test('a linked purchase return with negative stock allowed locks its items before the fiscal year and still goes negative', function () {
    $tenant = provisionLockOrderTenant('lock-order-linked-return.tenant-test');

    $tenant->run(function () {
        lockOrderOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $item = Item::factory()->create(['is_stockable' => true]);
        $admin = lockOrderAdmin();

        $purchase = Purchase::post(
            ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 10, 'rate' => 10]],
            $admin,
        );
        Sale::post(
            ['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-02', 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 8, 'rate' => 20]],
            $admin,
        );
        $line = PurchaseLine::where('purchase_id', $purchase->id)->firstOrFail();

        $log = lockOrderQueryLog(fn () => PurchaseReturn::post(
            ['purchase_id' => $purchase->id, 'date' => '2026-06-03'],
            [['purchase_line_id' => $line->id, 'quantity' => 5]],
            $admin,
        ));

        expect(lockOrderItemLockPosition($log))->toBeLessThan(lockOrderVoucherPosition($log))
            ->and($item->fresh()->currentStock()->toString())->toBe('-3.0000');
    });

    $tenant->delete();
});

test('an unlinked purchase return locks the supplier, then its items, then the fiscal year, and still goes negative when allowed', function () {
    $tenant = provisionLockOrderTenant('lock-order-unlinked-return.tenant-test');

    $tenant->run(function () {
        lockOrderOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $item = Item::factory()->create(['is_stockable' => true]);
        $supplier = Supplier::factory()->create();
        $admin = lockOrderAdmin();

        $log = lockOrderQueryLog(fn () => PurchaseReturn::postUnlinked(
            ['date' => '2026-06-01', 'supplier_id' => $supplier->id, 'payment_mode' => 'credit'],
            [['item_id' => $item->id, 'quantity' => 3, 'rate' => 10]],
            $admin,
        ));

        $itemLock = lockOrderItemLockPosition($log);

        expect(lockOrderSupplierPosition($log))->toBeLessThan($itemLock)
            ->and($itemLock)->toBeLessThan(lockOrderVoucherPosition($log))
            ->and($item->fresh()->currentStock()->toString())->toBe('-3.0000');
    });

    $tenant->delete();
});

test('a capital purchase settlement locks the supplier before the bill and the fiscal year', function () {
    $tenant = provisionLockOrderTenant('lock-order-capital-settlement.tenant-test');

    $tenant->run(function () {
        lockOrderOpenFiscalYear();
        $admin = lockOrderAdmin();
        $capitalPurchase = CapitalPurchase::post(
            ['type' => 'service', 'supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [['account_id' => Account::factory()->create()->id, 'amount' => '1000']],
            $admin,
        );

        $log = lockOrderQueryLog(fn () => CapitalPurchaseSettlement::settle($capitalPurchase, [
            'date' => '2026-06-10', 'amount' => '400', 'payment_mode' => 'cash',
        ], $admin));

        $billLock = lockOrderPosition($log, 'select * from "capital_purchases" where "capital_purchases"."id" = ?');

        expect(lockOrderSupplierPosition($log))->toBeLessThan($billLock)
            ->and($billLock)->toBeLessThan(lockOrderVoucherPosition($log));
    });

    $tenant->delete();
});

test('a linked sales return and an approved return request lock their items before the fiscal year', function () {
    $tenant = provisionLockOrderTenant('lock-order-sales-return.tenant-test');

    $tenant->run(function () {
        lockOrderOpenFiscalYear();
        CompanySetting::current()->update(['allow_negative_stock' => true]);
        $first = Item::factory()->create(['is_stockable' => true]);
        $second = Item::factory()->create(['is_stockable' => true]);
        $admin = lockOrderAdmin();

        $sale = Sale::post(
            ['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
            [
                ['item_id' => $second->id, 'quantity' => 5, 'rate' => 10],
                ['item_id' => $first->id, 'quantity' => 5, 'rate' => 10],
            ],
            $admin,
        );
        $saleLines = SaleLine::where('sale_id', $sale->id)->get()->keyBy('item_id');
        $returnLines = [
            ['sale_line_id' => $saleLines[$second->id]->id, 'quantity' => 2],
            ['sale_line_id' => $saleLines[$first->id]->id, 'quantity' => 2],
        ];

        $posted = lockOrderQueryLog(fn () => SalesReturn::post(['sale_id' => $sale->id, 'date' => '2026-06-02'], $returnLines, $admin));

        $request = SalesReturn::request(['sale_id' => $sale->id, 'date' => '2026-06-03'], $returnLines, $admin);
        $approved = lockOrderQueryLog(fn () => $request->approve($admin));

        foreach ([$posted, $approved] as $log) {
            $itemLock = lockOrderItemLockPosition($log);

            expect($itemLock)->toBeLessThan(lockOrderVoucherPosition($log))
                ->and(lockOrderLockedIds($log[$itemLock]))->toBe([$first->id, $second->id]);
        }

        // Sold 5 from nothing, then 2 + 2 back.
        expect($first->fresh()->currentStock()->toString())->toBe('-1.0000');
    });

    $tenant->delete();
});

test('an unlinked sales return locks its stockable items before the fiscal year', function () {
    $tenant = provisionLockOrderTenant('lock-order-unlinked-sales-return.tenant-test');

    $tenant->run(function () {
        lockOrderOpenFiscalYear();
        $item = Item::factory()->create(['is_stockable' => true]);
        $service = Item::factory()->create(['is_stockable' => false]);
        $admin = lockOrderAdmin();

        $log = lockOrderQueryLog(fn () => SalesReturn::postUnlinked(
            ['customer_id' => Customer::factory()->create()->id, 'date' => '2026-06-01'],
            [
                ['item_id' => $service->id, 'quantity' => '1', 'rate' => '50.00'],
                ['item_id' => $item->id, 'quantity' => '3', 'rate' => '100.00'],
            ],
            $admin,
        ));

        $itemLock = lockOrderItemLockPosition($log);

        expect($itemLock)->toBeLessThan(lockOrderVoucherPosition($log))
            ->and(lockOrderLockedIds($log[$itemLock]))->toBe([$item->id])
            ->and($item->fresh()->currentStock()->toString())->toBe('3.0000');
    });

    $tenant->delete();
});

test('a stock adjustment locks its stock-in items together with its stock-out items, in id order', function () {
    $tenant = provisionLockOrderTenant('lock-order-adjustment.tenant-test');

    $tenant->run(function () {
        lockOrderOpenFiscalYear();
        $admin = lockOrderAdmin();
        $taken = Item::factory()->create(['is_stockable' => true]);
        $added = Item::factory()->create(['is_stockable' => true]);

        StockAdjustment::post(
            ['date' => '2026-06-01'],
            [['item_id' => $taken->id, 'direction' => 'in', 'reason_type' => 'opening', 'quantity' => 10]],
            $admin,
        );

        $log = lockOrderQueryLog(fn () => StockAdjustment::post(
            ['date' => '2026-06-02'],
            [
                ['item_id' => $added->id, 'direction' => 'in', 'reason_type' => 'found', 'quantity' => 5],
                ['item_id' => $taken->id, 'direction' => 'out', 'reason_type' => 'correction', 'quantity' => 4],
            ],
            $admin,
        ));

        expect(lockOrderLockedIds($log[lockOrderItemLockPosition($log)]))->toBe([$taken->id, $added->id])
            ->and($taken->fresh()->currentStock()->toString())->toBe('6.0000')
            ->and($added->fresh()->currentStock()->toString())->toBe('5.0000');
    });

    $tenant->delete();
});
