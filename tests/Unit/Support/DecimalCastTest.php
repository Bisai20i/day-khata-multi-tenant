<?php

use App\Casts\Decimal;
use App\Support\Money\InvalidAmount;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;

/**
 * `App\Casts\Decimal` replaces Laravel's `decimal:N` on every money, quantity,
 * rate and percentage column.
 *
 * Reading must stay byte-identical to `decimal:N` so the 95 existing readers
 * keep working; writing must refuse a value the column cannot hold, because
 * MySQL would otherwise round it on insert and leave the stored bill different
 * from the one the customer was shown (audit P0-5).
 *
 * The cast is pure: it needs a Model only for the interface signature, so an
 * anonymous model with no table is enough and no database is involved.
 */
function decimalCastModel(): Model
{
    return new class extends Model {};
}

test('get returns a string padded to the configured scale', function (string $scale, ?string $stored, ?string $expected) {
    $cast = new Decimal($scale);

    expect($cast->get(decimalCastModel(), 'total', $stored, []))->toBe($expected);
})->with([
    ['2', '56.5', '56.50'],
    ['2', '56', '56.00'],
    ['2', '0', '0.00'],
    ['2', '-12.3', '-12.30'],
    ['4', '1.5', '1.5000'],
    ['4', '12.3456', '12.3456'],
    ['2', null, null],
    ['2', '', null],
]);

test('set stores a string at the configured scale', function () {
    $cast = new Decimal('2');
    $model = decimalCastModel();

    expect($cast->set($model, 'total', '56.5', []))->toBe('56.50')
        ->and($cast->set($model, 'total', 56, []))->toBe('56.00')
        ->and($cast->set($model, 'total', 1.5, []))->toBe('1.50')
        ->and($cast->set($model, 'total', Money::of('12.34'), []))->toBe('12.34')
        ->and($cast->set($model, 'total', BigDecimal::of('9.9'), []))->toBe('9.90')
        ->and($cast->set($model, 'total', null, []))->toBeNull()
        ->and($cast->set($model, 'total', '', []))->toBeNull();
});

test('set stores quantities at four decimals', function () {
    $cast = new Decimal('4');
    $model = decimalCastModel();

    expect($cast->set($model, 'quantity', '1.5', []))->toBe('1.5000')
        ->and($cast->set($model, 'quantity', Quantity::of('12.3456'), []))->toBe('12.3456');
});

test('set refuses to silently round a value the column cannot hold', function () {
    $cast = new Decimal('2');

    try {
        $cast->set(decimalCastModel(), 'total', '999.999', []);
        $this->fail('The cast should have refused 999.999 on a 2 decimal column.');
    } catch (InvalidAmount $exception) {
        expect($exception->getMessage())
            ->toBe('Refusing to silently round 999.999 to 2 decimals for attribute total')
            ->and($exception->reason)->toBe('too_many_decimals');
    }
});

test('set refuses an over precise value whatever shape it arrives in', function () {
    $cast = new Decimal('2');
    $model = decimalCastModel();

    expect(fn () => $cast->set($model, 'total', '12.345', []))->toThrow(InvalidAmount::class)
        ->and(fn () => $cast->set($model, 'total', 0.30000000000000004, []))->toThrow(InvalidAmount::class)
        ->and(fn () => $cast->set($model, 'total', Quantity::of('1.2345'), []))->toThrow(InvalidAmount::class)
        ->and(fn () => $cast->set($model, 'total', BigDecimal::of('0.001'), []))->toThrow(InvalidAmount::class);
});

test('set refuses a quantity with five decimals on a four decimal column', function () {
    $cast = new Decimal('4');

    expect(fn () => $cast->set(decimalCastModel(), 'quantity', '0.00004', []))->toThrow(InvalidAmount::class);
});

test('set refuses a value that is not a number at all', function (mixed $value) {
    $cast = new Decimal('2');

    expect(fn () => $cast->set(decimalCastModel(), 'total', $value, []))->toThrow(InvalidAmount::class);
})->with([
    'a grouped string' => ['1,200.00'],
    'text' => ['abc'],
    'an array' => [['1.00']],
    'a boolean' => [true],
]);

test('a percentage column is just a two decimal scale', function () {
    $cast = new Decimal('2');
    $model = decimalCastModel();

    expect($cast->set($model, 'discount_percentage', '6.27', []))->toBe('6.27')
        ->and(fn () => $cast->set($model, 'discount_percentage', '7.125', []))->toThrow(InvalidAmount::class);
});

test('the scale defaults to two when the cast is used without a parameter', function () {
    expect((new Decimal)->set(decimalCastModel(), 'total', '1.5', []))->toBe('1.50');
});
