<?php

use App\Enums\FiscalYearStatus;
use App\Models\ActivityLog;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\ItemUnit;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Inventory\ItemPurchasePriceUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| "Update the item's saved purchase price?" on the purchase form
|--------------------------------------------------------------------------
|
| A Yes is sent with the bill as `price_updates` and applied by
| ItemPurchasePriceUpdater inside the bill's own transaction: the chosen unit
| takes the typed rate, every other active unit is rescaled by its conversion
| factor. Only the master columns (items.purchase_rate,
| item_units.purchase_rate) are written - posted lines and stock movements
| keep their historical rates.
|
*/

uses(RefreshDatabase::class);

// Every tenant (and its database) is dropped here rather than at the end of
// each test, so a failing assertion never leaks a tenant database.
afterEach(function () {
    tenancy()->end();
    Tenant::all()->each(fn (Tenant $tenant) => $tenant->delete());
});

function provisionPriceUpdateTestTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function priceUpdateTestOpenFiscalYear(): FiscalYear
{
    return FiscalYear::create(['name' => 'FY1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open]);
}

function priceUpdateTestAdmin(): User
{
    return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
}

/**
 * Rice: pcs 10, Box (12) 120, Carton (24) 240.
 *
 * @return array{item: Item, box: ItemUnit, carton: ItemUnit}
 */
function priceUpdateTestRice(): array
{
    $item = Item::factory()->create(['name' => 'Rice', 'unit' => 'pcs', 'purchase_rate' => '10', 'is_vatable' => false, 'is_stockable' => true]);
    $box = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Box', 'conversion_factor' => 12, 'purchase_rate' => '120']);
    $carton = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Carton', 'conversion_factor' => 24, 'purchase_rate' => '240']);

    return ['item' => $item, 'box' => $box, 'carton' => $carton];
}

/**
 * @param  array<int, array<string, mixed>>  $lines
 */
function priceUpdateTestPost(array $lines, User $actor): Purchase
{
    return Purchase::post(
        ['supplier_id' => Supplier::factory()->create()->id, 'date' => '2026-06-01', 'payment_mode' => 'credit'],
        $lines,
        $actor,
    );
}

/**
 * @return array{0: string|null, 1: string|null, 2: string|null}
 */
function priceUpdateTestRates(Item $item, ItemUnit $box, ItemUnit $carton): array
{
    return [$item->fresh()->purchase_rate, $box->fresh()->purchase_rate, $carton->fresh()->purchase_rate];
}

function loginPriceUpdateTestUser(string $domain): void
{
    test()->post("http://{$domain}/login", [
        'email' => 'owner@example.com',
        'password' => 'password',
    ]);
}

test('a Yes on an alternate unit sets it and rescales every other active unit', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-rescale.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '132']], priceUpdateTestAdmin());

        $changed = app(ItemPurchasePriceUpdater::class)->apply($purchase, [
            ['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '132'],
        ]);

        expect($changed)->toBe(3)
            ->and(priceUpdateTestRates($item, $box, $carton))->toBe(['11.0000', '132.0000', '264.0000']);

        $log = ActivityLog::query()->where('action', 'price_updated')->sole();
        expect($log->subject_type)->toBe($item->getMorphClass())
            ->and($log->subject_id)->toBe($item->id)
            ->and($log->description)->toContain($purchase->purchase_number)
            ->and($log->description)->toContain('Box 120.00 -> 132.00')
            ->and($log->changes['purchase_id'])->toBe($purchase->id)
            ->and($log->changes['units'])->toHaveCount(3);
    });
});

test('a Yes on the base unit scales the alternate units up and leaves inactive units alone', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-base.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $carton->update(['is_active' => false]);
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'quantity' => 5, 'rate' => '11']], priceUpdateTestAdmin());

        app(ItemPurchasePriceUpdater::class)->apply($purchase, [['item_id' => $item->id, 'item_unit_id' => null, 'rate' => '11']]);

        expect(priceUpdateTestRates($item, $box, $carton))->toBe(['11.0000', '132.0000', '240.0000']);
    });
});

test('a Yes never changes the rates an earlier bill was posted at', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-history.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        $admin = priceUpdateTestAdmin();
        ['item' => $item, 'box' => $box] = priceUpdateTestRice();

        $earlier = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 2, 'rate' => '120']], $admin);
        $earlierLine = $earlier->lines()->sole();
        $earlierMovement = ItemStockMovement::query()->where('reference_type', $earlierLine->getMorphClass())->where('reference_id', $earlierLine->id)->sole();

        $later = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '132']], $admin);
        app(ItemPurchasePriceUpdater::class)->apply($later, [['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '132']]);

        expect($box->fresh()->purchase_rate)->toBe('132.0000')
            ->and(PurchaseLine::find($earlierLine->id)->rate)->toBe('120.0000')
            ->and(ItemStockMovement::find($earlierMovement->id)->unit_cost_rate)->toBe($earlierMovement->unit_cost_rate)
            ->and(ItemStockMovement::find($earlierMovement->id)->value)->toBe($earlierMovement->value)
            ->and($later->lines()->sole()->rate)->toBe('132.0000');
    });
});

test('no answers (every prompt answered No) leave the saved price unchanged', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-no.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '150']], priceUpdateTestAdmin());

        $updater = app(ItemPurchasePriceUpdater::class);

        expect($updater->apply($purchase, []))->toBe(0)
            ->and($updater->apply($purchase, null))->toBe(0)
            ->and($updater->apply($purchase, 'garbage'))->toBe(0)
            ->and(priceUpdateTestRates($item, $box, $carton))->toBe(['10.0000', '120.0000', '240.0000'])
            ->and(ActivityLog::query()->where('action', 'price_updated')->count())->toBe(0);
    });
});

test('an answer whose item, unit or rate is not on the bill is ignored', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-not-on-bill.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $other = Item::factory()->create(['purchase_rate' => '50', 'is_vatable' => false]);
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '132']], priceUpdateTestAdmin());

        $changed = app(ItemPurchasePriceUpdater::class)->apply($purchase, [
            // Different rate from the line.
            ['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '140'],
            // Same rate, but a unit the bill was not entered in.
            ['item_id' => $item->id, 'item_unit_id' => null, 'rate' => '132'],
            ['item_id' => $item->id, 'item_unit_id' => $carton->id, 'rate' => '132'],
            // An item that is not on the bill at all.
            ['item_id' => $other->id, 'item_unit_id' => null, 'rate' => '132'],
        ]);

        expect($changed)->toBe(0)
            ->and(priceUpdateTestRates($item, $box, $carton))->toBe(['10.0000', '120.0000', '240.0000'])
            ->and($other->fresh()->purchase_rate)->toBe('50.0000');
    });
});

test('a unit row belonging to another item is never written', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-foreign-unit.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box] = priceUpdateTestRice();
        $other = Item::factory()->create(['purchase_rate' => '5', 'is_vatable' => false]);
        $foreignUnit = ItemUnit::factory()->create(['item_id' => $other->id, 'name' => 'Pack', 'conversion_factor' => 6, 'purchase_rate' => '30']);
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '12']], priceUpdateTestAdmin());

        // Purchase::post() itself refuses a unit of another item, so the line
        // is pointed at one afterwards and the answer below passes the
        // on-bill check. Two guards then keep the foreign unit row out of
        // reach, and this test cannot tell them apart: the answer check that
        // the unit belongs to this item, and applyToItem() only ever loading
        // the item's own units. It still passes with either one removed -
        // defence in depth, not proof of the first guard on its own.
        PurchaseLine::query()->where('purchase_id', $purchase->id)->update(['item_unit_id' => $foreignUnit->id]);
        $purchase->unsetRelation('lines');

        expect(app(ItemPurchasePriceUpdater::class)->apply($purchase, [
            ['item_id' => $item->id, 'item_unit_id' => $foreignUnit->id, 'rate' => '12'],
        ]))->toBe(0)
            ->and($foreignUnit->fresh()->purchase_rate)->toBe('30.0000')
            ->and($other->fresh()->purchase_rate)->toBe('5.0000')
            ->and($item->fresh()->purchase_rate)->toBe('10.0000')
            ->and($box->fresh()->purchase_rate)->toBe('120.0000');
    });
});

test('invalid rates are ignored', function (mixed $rate) {
    $tenant = provisionPriceUpdateTestTenant('price-update-invalid-'.md5(json_encode($rate)).'.tenant-test');

    $tenant->run(function () use ($rate) {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        // A zero-rate line is legal on a bill (a free sample), so the
        // "rate above zero" rule is what stops it, not the on-bill check.
        $purchase = priceUpdateTestPost([
            ['item_id' => $item->id, 'quantity' => 1, 'rate' => '0'],
            ['item_id' => $item->id, 'quantity' => 1, 'rate' => '12'],
        ], priceUpdateTestAdmin());

        expect(app(ItemPurchasePriceUpdater::class)->apply($purchase, [['item_id' => $item->id, 'item_unit_id' => null, 'rate' => $rate]]))->toBe(0)
            ->and(priceUpdateTestRates($item, $box, $carton))->toBe(['10.0000', '120.0000', '240.0000']);
    });
})->with([
    'zero' => ['0'],
    'negative' => ['-12'],
    'not a number' => ['abc'],
    'exponent' => ['1.2e1'],
    'too many decimals' => ['12.00001'],
    'over the column maximum' => ['100000000000'],
    'missing' => [null],
    'an array' => [['12']],
]);

test('a malformed answer for an item is skipped as a whole', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-malformed.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'quantity' => 1, 'rate' => '12']], priceUpdateTestAdmin());

        expect(app(ItemPurchasePriceUpdater::class)->apply($purchase, [
            'not an array',
            ['rate' => '12'],
            ['item_id' => 'abc', 'rate' => '12'],
            ['item_id' => $item->id, 'item_unit_id' => 'abc', 'rate' => '12'],
        ]))->toBe(0)
            ->and($item->fresh()->purchase_rate)->toBe('10.0000');
    });
});

test('an invalid later answer for the same item does not cancel a valid earlier one', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-invalid-later.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '132']], priceUpdateTestAdmin());

        app(ItemPurchasePriceUpdater::class)->apply($purchase, [
            ['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '132'],
            ['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => 'abc'],
            ['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '999'],
        ]);

        expect(priceUpdateTestRates($item, $box, $carton))->toBe(['11.0000', '132.0000', '264.0000']);
    });
});

test('the last valid answer for an item wins across units', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-last-wins.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([
            ['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '132'],
            ['item_id' => $item->id, 'quantity' => 1, 'rate' => '12'],
        ], priceUpdateTestAdmin());

        app(ItemPurchasePriceUpdater::class)->apply($purchase, [
            ['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '132'],
            ['item_id' => $item->id, 'item_unit_id' => null, 'rate' => '12'],
        ]);

        expect(priceUpdateTestRates($item, $box, $carton))->toBe(['12.0000', '144.0000', '288.0000'])
            ->and(ActivityLog::query()->where('action', 'price_updated')->count())->toBe(1);
    });
});

test('two lines of the same item and unit at different rates: the last answer wins, as the form sends it', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-same-unit-last-wins.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([
            ['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '132'],
            ['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '144'],
        ], priceUpdateTestAdmin());

        app(ItemPurchasePriceUpdater::class)->apply($purchase, [
            ['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '132.0000'],
            ['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '144.0000'],
        ]);

        expect(priceUpdateTestRates($item, $box, $carton))->toBe(['12.0000', '144.0000', '288.0000'])
            ->and(ActivityLog::query()->where('action', 'price_updated')->count())->toBe(1);
    });
});

test('duplicate answers write once and a resubmit writes nothing', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-duplicates.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '132']], priceUpdateTestAdmin());
        $answer = ['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '132'];
        $updater = app(ItemPurchasePriceUpdater::class);

        expect($updater->apply($purchase, [$answer, $answer]))->toBe(3)
            ->and($updater->apply($purchase, [$answer]))->toBe(0)
            ->and(ActivityLog::query()->where('action', 'price_updated')->count())->toBe(1)
            ->and(priceUpdateTestRates($item, $box, $carton))->toBe(['11.0000', '132.0000', '264.0000']);
    });
});

test('many repeats of one answer are checked once and written once', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-repeats.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '132']], priceUpdateTestAdmin());
        // The same Yes 300 times, in every spelling of the line's rate.
        $answers = [];
        foreach (range(1, 100) as $ignored) {
            $answers[] = ['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '132'];
            $answers[] = ['item_id' => (string) $item->id, 'item_unit_id' => (string) $box->id, 'rate' => '132.0000'];
            $answers[] = ['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => 132];
        }

        DB::connection('tenant')->flushQueryLog();
        DB::connection('tenant')->enableQueryLog();
        $changed = app(ItemPurchasePriceUpdater::class)->apply($purchase, $answers);
        $queries = collect(DB::connection('tenant')->getQueryLog())->pluck('query');
        DB::connection('tenant')->disableQueryLog();

        // One "still active" check for the item and one for the unit, not one pair per answer.
        expect($queries->filter(fn (string $query): bool => str_starts_with($query, 'select exists'))->count())->toBe(2)
            ->and($changed)->toBe(3)
            ->and(priceUpdateTestRates($item, $box, $carton))->toBe(['11.0000', '132.0000', '264.0000'])
            ->and(ActivityLog::query()->where('action', 'price_updated')->count())->toBe(1);
    });
});

test('a rate typed with three decimals matches its line and is saved as typed', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-three-decimals.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'quantity' => 1, 'rate' => '10.125']], priceUpdateTestAdmin());

        app(ItemPurchasePriceUpdater::class)->apply($purchase, [['item_id' => $item->id, 'item_unit_id' => null, 'rate' => '10.125']]);

        // The typed unit keeps all its decimals; the rescaled ones round once to 2.
        expect(priceUpdateTestRates($item, $box, $carton))->toBe(['10.1250', '121.5000', '243.0000']);
    });
});

test('rescaled rates round once, half up', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-rounding.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '100.14']], priceUpdateTestAdmin());

        app(ItemPurchasePriceUpdater::class)->apply($purchase, [['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '100.14']]);

        // 100.14 / 12 = 8.345 exactly: a tie on the third decimal whose
        // preceding digit (4) is even, so HalfUp gives 8.35 where HalfEven
        // (banker's rounding) and HalfDown would give 8.34 - chosen so this
        // test fails if the mode ever changes. 100.14 x 24 / 12 = 200.28
        // exactly (multiplied first), so the Carton needs no rounding.
        expect(priceUpdateTestRates($item, $box, $carton))->toBe(['8.3500', '100.1400', '200.2800']);
    });
});

test('a chosen unit without a usable conversion factor updates only itself', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-zero-factor.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '150']], priceUpdateTestAdmin());
        // Simulates a unit whose factor was never set up properly.
        $box->update(['conversion_factor' => 0]);

        expect(app(ItemPurchasePriceUpdater::class)->apply($purchase, [['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '150']]))->toBe(1)
            ->and(priceUpdateTestRates($item, $box, $carton))->toBe(['10.0000', '150.0000', '240.0000']);
    });
});

test('a chosen unit whose conversion factor is null updates only itself, as the form previews', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-null-factor.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '150']], priceUpdateTestAdmin());

        // item_units.conversion_factor is NOT NULL, so the null is put on the
        // row as the updater reads it rather than stored.
        ItemUnit::retrieved(function (ItemUnit $unit) use ($box): void {
            if ($unit->id === $box->id) {
                $unit->setRawAttributes(['conversion_factor' => null] + $unit->getAttributes(), true);
            }
        });

        expect(app(ItemPurchasePriceUpdater::class)->apply($purchase, [['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '150']]))->toBe(1)
            ->and(priceUpdateTestRates($item, $box, $carton))->toBe(['10.0000', '150.0000', '240.0000']);
    });
});

test('an inactive chosen unit is never written', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-inactive-chosen.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '150']], priceUpdateTestAdmin());
        $box->update(['is_active' => false]);

        expect(app(ItemPurchasePriceUpdater::class)->apply($purchase, [['item_id' => $item->id, 'item_unit_id' => $box->id, 'rate' => '150']]))->toBe(0)
            ->and(priceUpdateTestRates($item, $box, $carton))->toBe(['10.0000', '120.0000', '240.0000']);
    });
});

test('a rescaled price that would round to zero leaves every other unit alone', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-rescale-zero.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $crate = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Crate', 'conversion_factor' => 1000, 'purchase_rate' => '9999']);
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'item_unit_id' => $crate->id, 'quantity' => 1, 'rate' => '4']], priceUpdateTestAdmin());

        // pcs would be 4 / 1000 = 0.004 -> 0.00. Box (0.05) and Carton (0.10)
        // would fit, but rescaling is all or nothing: only the Crate changes.
        expect(app(ItemPurchasePriceUpdater::class)->apply($purchase, [['item_id' => $item->id, 'item_unit_id' => $crate->id, 'rate' => '4']]))->toBe(1)
            ->and(priceUpdateTestRates($item, $box, $carton))->toBe(['10.0000', '120.0000', '240.0000'])
            ->and($crate->fresh()->purchase_rate)->toBe('4.0000');

        $log = ActivityLog::query()->where('action', 'price_updated')->sole();
        expect($log->changes['units'])->toHaveCount(1)
            ->and($log->changes['units'][0]['unit'])->toBe('Crate')
            ->and($log->changes['rescale_skipped'])->toBeTrue()
            ->and($log->description)->toContain('other units unchanged');
    });
});

test('a rescaled price too large for the column leaves every other unit alone', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-rescale-overflow.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $container = ItemUnit::factory()->create(['item_id' => $item->id, 'name' => 'Container', 'conversion_factor' => 1000000, 'purchase_rate' => null]);
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'quantity' => 1, 'rate' => '100000']], priceUpdateTestAdmin());

        // Container would be 100000 x 1,000,000 = 100,000,000,000, over
        // DECIMAL(15,4): no unit is rescaled, the base unit still takes the rate.
        expect(app(ItemPurchasePriceUpdater::class)->apply($purchase, [['item_id' => $item->id, 'item_unit_id' => null, 'rate' => '100000']]))->toBe(1)
            ->and(priceUpdateTestRates($item, $box, $carton))->toBe(['100000.0000', '120.0000', '240.0000'])
            ->and($container->fresh()->purchase_rate)->toBeNull()
            ->and(ActivityLog::query()->where('action', 'price_updated')->sole()->changes['rescale_skipped'])->toBeTrue();
    });
});

test('a rate sent in another spelling of the line\'s rate still matches it', function (mixed $rate) {
    $tenant = provisionPriceUpdateTestTenant('price-update-spelling-'.md5(gettype($rate).':'.$rate).'.tenant-test');

    $tenant->run(function () use ($rate) {
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $purchase = priceUpdateTestPost([['item_id' => $item->id, 'quantity' => 1, 'rate' => '12']], priceUpdateTestAdmin());

        expect(app(ItemPurchasePriceUpdater::class)->apply($purchase, [['item_id' => $item->id, 'item_unit_id' => null, 'rate' => $rate]]))->toBe(3)
            ->and(priceUpdateTestRates($item, $box, $carton))->toBe(['12.0000', '144.0000', '288.0000']);
    });
})->with([
    'one decimal' => ['12.0'],
    'four decimals' => ['12.0000'],
    'an integer' => [12],
    'a float' => [12.0],
]);

test('answers given out of item id order are all applied, in ascending item id order', function () {
    $tenant = provisionPriceUpdateTestTenant('price-update-id-order.tenant-test');

    $tenant->run(function () {
        priceUpdateTestOpenFiscalYear();
        ['item' => $rice, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $oil = Item::factory()->create(['name' => 'Oil', 'unit' => 'ltr', 'purchase_rate' => '200', 'is_vatable' => false, 'is_stockable' => true]);
        $purchase = priceUpdateTestPost([
            ['item_id' => $oil->id, 'quantity' => 1, 'rate' => '250'],
            ['item_id' => $rice->id, 'item_unit_id' => $box->id, 'quantity' => 1, 'rate' => '132'],
        ], priceUpdateTestAdmin());

        expect($oil->id)->toBeGreaterThan($rice->id);

        expect(app(ItemPurchasePriceUpdater::class)->apply($purchase, [
            ['item_id' => $oil->id, 'item_unit_id' => null, 'rate' => '250'],
            ['item_id' => $rice->id, 'item_unit_id' => $box->id, 'rate' => '132'],
        ]))->toBe(4)
            ->and(priceUpdateTestRates($rice, $box, $carton))->toBe(['11.0000', '132.0000', '264.0000'])
            ->and($oil->fresh()->purchase_rate)->toBe('250.0000')
            ->and(ActivityLog::query()->where('action', 'price_updated')->orderBy('id')->pluck('subject_id')->all())->toBe([$rice->id, $oil->id]);
    });
});

test('a failure inside the price update rolls the whole bill back', function () {
    $domain = 'price-update-http-failure.tenant-test';
    $tenant = provisionPriceUpdateTestTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        User::factory()->create(['email' => 'owner@example.com']);
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $ids = ['supplier' => Supplier::factory()->create()->id, 'item' => $item->id, 'box' => $box->id, 'carton' => $carton->id, 'movements' => ItemStockMovement::query()->count()];
    });

    loginPriceUpdateTestUser($domain);

    // ItemPurchasePriceUpdater is final, so it can't be swapped for a fake in
    // the container; instead its own last step - the audit row, written after
    // the unit prices - fails, as any error inside it would.
    ActivityLog::creating(function (ActivityLog $log): void {
        if ($log->action === 'price_updated') {
            throw new RuntimeException('Simulated failure while recording the price update.');
        }
    });

    $this->post("http://{$domain}/purchases", [
        'supplier_id' => $ids['supplier'],
        'date' => '2026-06-01',
        'payment_mode' => 'credit',
        'expected_total' => '264.00',
        'lines' => [['item_id' => $ids['item'], 'item_unit_id' => $ids['box'], 'quantity' => '2', 'rate' => '132']],
        'price_updates' => [['item_id' => $ids['item'], 'item_unit_id' => $ids['box'], 'rate' => '132']],
    ])->assertServerError();

    $tenant->run(function () use ($ids) {
        expect(Purchase::query()->count())->toBe(0)
            ->and(PurchaseLine::query()->count())->toBe(0)
            ->and(ItemStockMovement::query()->count())->toBe($ids['movements'])
            ->and(Item::find($ids['item'])->purchase_rate)->toBe('10.0000')
            ->and(ItemUnit::find($ids['box'])->purchase_rate)->toBe('120.0000')
            ->and(ItemUnit::find($ids['carton'])->purchase_rate)->toBe('240.0000');
    });
});

test('saving a bill through the store route with a Yes updates the saved price', function () {
    $domain = 'price-update-http-yes.tenant-test';
    $tenant = provisionPriceUpdateTestTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        User::factory()->create(['email' => 'owner@example.com']);
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $ids = ['supplier' => Supplier::factory()->create()->id, 'item' => $item->id, 'box' => $box->id, 'carton' => $carton->id];
    });

    loginPriceUpdateTestUser($domain);

    $this->post("http://{$domain}/purchases", [
        'supplier_id' => $ids['supplier'],
        'date' => '2026-06-01',
        'payment_mode' => 'credit',
        'expected_total' => '264.00',
        'lines' => [['item_id' => $ids['item'], 'item_unit_id' => $ids['box'], 'quantity' => '2', 'rate' => '132']],
        'price_updates' => [['item_id' => $ids['item'], 'item_unit_id' => $ids['box'], 'rate' => '132']],
    ])->assertSessionHasNoErrors()->assertRedirect("http://{$domain}/purchases");

    $tenant->run(function () use ($ids) {
        expect(Purchase::query()->count())->toBe(1)
            ->and(Item::find($ids['item'])->purchase_rate)->toBe('11.0000')
            ->and(ItemUnit::find($ids['box'])->purchase_rate)->toBe('132.0000')
            ->and(ItemUnit::find($ids['carton'])->purchase_rate)->toBe('264.0000')
            ->and(ActivityLog::query()->where('action', 'price_updated')->sole()->user_id)->not->toBeNull();
    });
});

test('saving a bill through the store route without a Yes, or with a bad answer, keeps the saved price', function () {
    $domain = 'price-update-http-no.tenant-test';
    $tenant = provisionPriceUpdateTestTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        User::factory()->create(['email' => 'owner@example.com']);
        priceUpdateTestOpenFiscalYear();
        $ids = ['supplier' => Supplier::factory()->create()->id, 'item' => priceUpdateTestRice()['item']->id];
    });

    loginPriceUpdateTestUser($domain);

    $bill = fn (array $extra): array => [
        'supplier_id' => $ids['supplier'],
        'date' => '2026-06-01',
        'payment_mode' => 'credit',
        'expected_total' => '15.00',
        'lines' => [['item_id' => $ids['item'], 'quantity' => '1', 'rate' => '15']],
        ...$extra,
    ];

    $this->post("http://{$domain}/purchases", $bill([]))->assertSessionHasNoErrors();
    // A malformed answer is skipped - never a reason to reject the bill.
    $this->post("http://{$domain}/purchases", $bill(['price_updates' => [['item_id' => $ids['item'], 'rate' => 'abc'], 'junk']]))->assertSessionHasNoErrors();
    // The list itself is validated, though: not an array, or too long, refuses the bill.
    $this->post("http://{$domain}/purchases", $bill(['price_updates' => 'junk']))->assertSessionHasErrors('price_updates');
    $this->post("http://{$domain}/purchases", $bill(['price_updates' => array_fill(0, 501, ['item_id' => $ids['item'], 'item_unit_id' => null, 'rate' => '15'])]))
        ->assertSessionHasErrors('price_updates');

    $tenant->run(function () use ($ids) {
        expect(Purchase::query()->count())->toBe(2)
            ->and(Item::find($ids['item'])->purchase_rate)->toBe('10.0000');
    });
});

test('a rejected bill never changes the saved price', function () {
    $domain = 'price-update-http-rejected.tenant-test';
    $tenant = provisionPriceUpdateTestTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        User::factory()->create(['email' => 'owner@example.com']);
        priceUpdateTestOpenFiscalYear();
        $ids = ['supplier' => Supplier::factory()->create()->id, 'item' => priceUpdateTestRice()['item']->id];
    });

    loginPriceUpdateTestUser($domain);

    $bill = fn (array $extra): array => [
        'supplier_id' => $ids['supplier'],
        'bill_number' => 'B-100',
        'date' => '2026-06-01',
        'payment_mode' => 'credit',
        'expected_total' => '15.00',
        'lines' => [['item_id' => $ids['item'], 'quantity' => '1', 'rate' => '15']],
        'price_updates' => [['item_id' => $ids['item'], 'item_unit_id' => null, 'rate' => '15']],
        ...$extra,
    ];

    // Refused inside Purchase::post()'s transaction: the total on screen was not the bill's.
    $this->post("http://{$domain}/purchases", $bill(['expected_total' => '99.00']))->assertSessionHasErrors('expected_total');

    $tenant->run(function () use ($ids) {
        expect(Purchase::query()->count())->toBe(0)
            ->and(Item::find($ids['item'])->purchase_rate)->toBe('10.0000');
    });

    // The first bill posts without a Yes; the duplicate that carries one is
    // refused by the bill_number unique rule in the controller's validation,
    // before the transaction starts - so this case shows a refused bill never
    // reaches the updater, not a rollback. The expected_total case above is
    // the one refused inside the transaction.
    $this->post("http://{$domain}/purchases", $bill(['price_updates' => []]))->assertSessionHasNoErrors();
    $this->post("http://{$domain}/purchases", $bill([]))->assertSessionHasErrors('bill_number');

    $tenant->run(function () use ($ids) {
        expect(Purchase::query()->count())->toBe(1)
            ->and(Item::find($ids['item'])->purchase_rate)->toBe('10.0000')
            ->and(ActivityLog::query()->where('action', 'price_updated')->count())->toBe(0);
    });
});

test('a price update never reaches another tenant', function () {
    $domain = 'price-update-tenant-a.tenant-test';
    $tenantA = provisionPriceUpdateTestTenant($domain);
    $tenantB = provisionPriceUpdateTestTenant('price-update-tenant-b.tenant-test');

    // Each tenant has its own database, so the ids collide on purpose: both
    // have an item 1 and an item 2 whose unit is unit 1. Every answer below
    // names an item, unit and rate that IS on tenant A's bill, so it passes
    // the on-bill check - only the per-tenant database keeps tenant B's rows
    // with the same ids out of reach.
    $bIds = [];
    $tenantB->run(function () use (&$bIds) {
        $first = Item::factory()->create(['purchase_rate' => '10', 'is_vatable' => false]);
        $second = Item::factory()->create(['purchase_rate' => '20', 'is_vatable' => false]);
        $bIds = [
            'first' => $first->id,
            'second' => $second->id,
            'unit' => ItemUnit::factory()->create(['item_id' => $second->id, 'name' => 'Box', 'conversion_factor' => 12, 'purchase_rate' => '240'])->id,
        ];
    });

    $aIds = [];
    $tenantA->run(function () use (&$aIds) {
        User::factory()->create(['email' => 'owner@example.com']);
        priceUpdateTestOpenFiscalYear();
        $first = Item::factory()->create(['purchase_rate' => '10', 'is_vatable' => false]);
        $second = Item::factory()->create(['purchase_rate' => '10', 'is_vatable' => false]);
        $aIds = [
            'supplier' => Supplier::factory()->create()->id,
            'first' => $first->id,
            'second' => $second->id,
            'unit' => ItemUnit::factory()->create(['item_id' => $second->id, 'name' => 'Box', 'conversion_factor' => 12, 'purchase_rate' => '120'])->id,
        ];
    });

    expect([$aIds['first'], $aIds['second'], $aIds['unit']])->toBe([$bIds['first'], $bIds['second'], $bIds['unit']]);

    loginPriceUpdateTestUser($domain);

    $this->post("http://{$domain}/purchases", [
        'supplier_id' => $aIds['supplier'],
        'date' => '2026-06-01',
        'payment_mode' => 'credit',
        'expected_total' => '144.00',
        'lines' => [
            ['item_id' => $aIds['first'], 'quantity' => '1', 'rate' => '12'],
            ['item_id' => $aIds['second'], 'item_unit_id' => $aIds['unit'], 'quantity' => '1', 'rate' => '132'],
        ],
        'price_updates' => [
            ['item_id' => $aIds['first'], 'item_unit_id' => null, 'rate' => '12'],
            ['item_id' => $aIds['second'], 'item_unit_id' => $aIds['unit'], 'rate' => '132'],
        ],
    ])->assertSessionHasNoErrors();

    tenancy()->end();

    $tenantA->run(function () use ($aIds) {
        expect(Item::find($aIds['first'])->purchase_rate)->toBe('12.0000')
            ->and(Item::find($aIds['second'])->purchase_rate)->toBe('11.0000')
            ->and(ItemUnit::find($aIds['unit'])->purchase_rate)->toBe('132.0000')
            ->and(ActivityLog::query()->where('action', 'price_updated')->count())->toBe(2);
    });

    $tenantB->run(function () use ($bIds) {
        expect(Item::find($bIds['first'])->purchase_rate)->toBe('10.0000')
            ->and(Item::find($bIds['second'])->purchase_rate)->toBe('20.0000')
            ->and(ItemUnit::find($bIds['unit'])->purchase_rate)->toBe('240.0000')
            ->and(ActivityLog::query()->where('action', 'price_updated')->count())->toBe(0);
    });
});

test('the purchase form receives each item\'s saved price and its active units\' factors and prices', function () {
    $domain = 'price-update-form-props.tenant-test';
    $tenant = provisionPriceUpdateTestTenant($domain);

    $ids = [];
    $tenant->run(function () use (&$ids) {
        User::factory()->create(['email' => 'owner@example.com']);
        priceUpdateTestOpenFiscalYear();
        ['item' => $item, 'box' => $box, 'carton' => $carton] = priceUpdateTestRice();
        $carton->update(['is_active' => false]);
        $unpriced = Item::factory()->create(['name' => 'Unpriced', 'purchase_rate' => null]);
        $ids = ['item' => $item->id, 'box' => $box->id, 'unpriced' => $unpriced->id];
    });

    loginPriceUpdateTestUser($domain);

    $this->get("http://{$domain}/purchases")
        ->assertOk()
        ->assertInertia(function ($page) use ($ids) {
            $items = collect($page->toArray()['props']['items'])->keyBy('id');
            $rice = $items[$ids['item']];

            expect($rice['purchase_rate'])->toBe('10.0000')
                ->and($rice['unit'])->toBe('pcs')
                ->and(collect($rice['units'])->pluck('id')->all())->toBe([$ids['box']])
                ->and($rice['units'][0]['conversion_factor'])->toBe('12.0000')
                ->and($rice['units'][0]['purchase_rate'])->toBe('120.0000')
                ->and($rice['units'][0]['is_active'])->toBeTrue()
                // A null saved price arrives as null, which the form prefills as blank.
                ->and($items[$ids['unpriced']]['purchase_rate'])->toBeNull();
        });
});
