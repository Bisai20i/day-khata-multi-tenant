<?php

use App\Support\Money\InvalidAmount;
use App\Support\Money\Money;
use App\Support\Money\Quantity;

/**
 * `Quantity` holds quantities, rates, base quantities and unit conversion
 * factors at 4 decimals. The audit found rates silently truncated to 2 on the
 * way in (so a reprint disagreed with the original bill) and a quantity of
 * 0.00004 being charged for while storing as 0.0000 (P0-5); both are rejected
 * outright here.
 */
test('of pads to four decimals', function () {
    expect(Quantity::of('1.5')->toString())->toBe('1.5000')
        ->and(Quantity::of('2')->toString())->toBe('2.0000')
        ->and(Quantity::of(12)->toString())->toBe('12.0000')
        ->and(Quantity::of('12.3456')->toString())->toBe('12.3456')
        ->and(Quantity::of('-3.75')->toString())->toBe('-3.7500')
        ->and(Quantity::zero()->toString())->toBe('0.0000');
});

test('of accepts floats through their shortest round-trip literal', function () {
    expect(Quantity::of(1.5)->toString())->toBe('1.5000')
        ->and(Quantity::of(0.125)->toString())->toBe('0.1250');
});

test('of rejects a quantity with more than four decimals', function (string $value) {
    expect(fn () => Quantity::of($value))->toThrow(InvalidAmount::class);
})->with(['0.00004', '1.23456', '-0.12345']);

test('of rejects anything that is not a plain decimal literal', function (mixed $value) {
    expect(fn () => Quantity::of($value))->toThrow(InvalidAmount::class);
})->with(['1,5', '1e3', 'two', '', NAN, INF]);

test('round is the only door that discards precision', function () {
    expect(Quantity::round('0.00004')->toString())->toBe('0.0000')
        ->and(Quantity::round('1.23455')->toString())->toBe('1.2346')
        ->and(Quantity::round('-1.23455')->toString())->toBe('-1.2346');
});

test('a base quantity is the quantity times the unit conversion factor', function () {
    expect(Quantity::of('2')->multipliedBy(Quantity::of('12'))->toString())->toBe('24.0000')
        ->and(Quantity::of('1.5')->multipliedBy(Quantity::of('12'))->toString())->toBe('18.0000')
        ->and(Quantity::of('1')->multipliedBy(Quantity::of('0.0833'))->toString())->toBe('0.0833');
});

test('quantity arithmetic is exact', function () {
    expect(Quantity::of('0.1')->plus('0.2')->toString())->toBe('0.3000')
        ->and(Quantity::of('10')->minus('2.5')->toString())->toBe('7.5000')
        ->and(Quantity::sum(['1.25', '2.75', 4])->toString())->toBe('8.0000')
        ->and(Quantity::of('5')->negated()->toString())->toBe('-5.0000')
        ->and(Quantity::of('-5')->abs()->toString())->toBe('5.0000');
});

test('quantity comparisons have no tolerance', function () {
    expect(Quantity::of('1.0001')->isGreaterThan('1'))->toBeTrue()
        ->and(Quantity::of('1.0001')->isEqualTo('1'))->toBeFalse()
        ->and(Quantity::of('-0.0000')->isZero())->toBeTrue();
});

test('a returned share of a quantity rounds only once', function () {
    expect(Quantity::of('10')->multipliedByFraction('1', '3')->toString())->toBe('3.3333');
});

test('formatQuantity drops the padding zeros', function (string $value, string $expected) {
    expect(Quantity::of($value)->formatQuantity())->toBe($expected);
})->with([
    ['1.5', '1.5'],
    ['2', '2'],
    ['0', '0'],
    ['0.125', '0.125'],
    ['-3.7500', '-3.75'],
    ['12.3456', '12.3456'],
]);

test('formatRate keeps two to four decimals', function (string $value, string $expected) {
    expect(Quantity::of($value)->formatRate())->toBe($expected);
})->with([
    ['12.5', '12.50'],
    ['12', '12.00'],
    ['12.3456', '12.3456'],
    ['12.345', '12.345'],
    ['0', '0.00'],
]);

test('a quantity serialises to a plain decimal string', function () {
    expect(json_encode(['quantity' => Quantity::of('1.5')]))->toBe('{"quantity":"1.5000"}')
        ->and((string) Quantity::of('1.5'))->toBe('1.5000');
});

test('a quantity can be converted to money when it fits two decimals', function () {
    expect(Money::of(Quantity::of('1.5'))->toString())->toBe('1.50');
});

test('a quantity with four decimals refuses to become money', function () {
    Money::of(Quantity::of('1.2345'));
})->throws(InvalidAmount::class);
