<?php

use App\Support\Money\InvalidAmount;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;

/**
 * `Money` is the foundation every rupee amount in the application is built on,
 * so these tests are deliberately exhaustive. The rounding cases are the exact
 * proofs recorded in the 2026-09-11 audit (P0-1): each one billed a paisa too
 * little under PHP's `round()` before this class existed.
 */
test('of accepts decimal strings and pads them to two decimals', function () {
    expect(Money::of('56.5')->toString())->toBe('56.50')
        ->and(Money::of('56')->toString())->toBe('56.00')
        ->and(Money::of('0')->toString())->toBe('0.00')
        ->and(Money::of('-12.34')->toString())->toBe('-12.34')
        ->and(Money::of('+12.34')->toString())->toBe('12.34');
});

test('of accepts integers, BigDecimals and other decimal values', function () {
    expect(Money::of(5)->toString())->toBe('5.00')
        ->and(Money::of(BigDecimal::of('7.1'))->toString())->toBe('7.10')
        ->and(Money::of(Money::of('2.50'))->toString())->toBe('2.50')
        ->and(Money::of(Quantity::of('2.5000'))->toString())->toBe('2.50');
});

test('of converts floats through their shortest round-trip literal', function () {
    expect(Money::of(0.1)->toString())->toBe('0.10')
        ->and(Money::of(1.5)->toString())->toBe('1.50')
        ->and(Money::of(100.0)->toString())->toBe('100.00')
        ->and(Money::of(-3.25)->toString())->toBe('-3.25');
});

test('of rejects a float that carries float noise', function () {
    Money::of(0.30000000000000004);
})->throws(InvalidAmount::class);

test('of rejects values with more decimals than the scale', function (string $value) {
    expect(fn () => Money::of($value))->toThrow(InvalidAmount::class);
})->with(['1.234', '0.001', '-9.999', '1.005']);

test('of reports too many decimals as the shared reason code', function () {
    try {
        Money::of('1.234');
        $this->fail('Money::of should have rejected 1.234');
    } catch (InvalidAmount $exception) {
        expect($exception->reason)->toBe('too_many_decimals');
    }
});

test('of rejects anything that is not a plain decimal literal', function (mixed $value) {
    expect(fn () => Money::of($value))->toThrow(InvalidAmount::class);
})->with(['1,200.00', '1e3', '1 200', 'abc', '', ' 12.00', '12.', '.5', '1/2', NAN, INF, -INF]);

test('of reports a malformed value as the shared reason code', function () {
    try {
        Money::of('1,200.00');
        $this->fail('Money::of should have rejected a grouped string');
    } catch (InvalidAmount $exception) {
        expect($exception->reason)->toBe('invalid_number');
    }
});

test('ofNullable treats null and the empty string as no value', function () {
    expect(Money::ofNullable(null))->toBeNull()
        ->and(Money::ofNullable(''))->toBeNull()
        ->and(Money::ofNullable('4')->toString())->toBe('4.00');
});

test('round is the only door that discards precision', function (string $value, string $expected) {
    expect(Money::round($value)->toString())->toBe($expected);
})->with([
    ['1.234', '1.23'],
    ['1.235', '1.24'],
    ['1.005', '1.01'],
    ['0.125', '0.13'],
    ['-0.125', '-0.13'],
    ['-1.235', '-1.24'],
    ['-0.004', '0.00'],
]);

test('rounding is half up away from zero, the audit P0-1 proofs', function (string $left, string $right, string $expected) {
    $product = Quantity::of($left)->toBigDecimal()->multipliedBy(Quantity::of($right)->toBigDecimal());

    expect(Money::round($product)->toString())->toBe($expected);
})->with([
    'a 1.5 kg line at 33.33' => ['1.5', '33.33', '50.00'],
    'a 1.5 unit line at 0.15' => ['1.5', '0.15', '0.23'],
    '28.5 x 14209.99' => ['28.5', '14209.99', '404984.72'],
    '1632.5 x 78.13' => ['1632.5', '78.13', '127547.23'],
]);

test('percent rounds once, the audit P0-1 percentage proofs', function (string $amount, string $percentage, string $expected) {
    expect(Money::of($amount)->percent($percentage)->toString())->toBe($expected);
})->with([
    '15 percent discount on 4.10' => ['4.10', '15', '0.62'],
    '13 percent VAT on 1001.50' => ['1001.50', '13', '130.20'],
    '6.27 percent discount on 69350' => ['69350.00', '6.27', '4348.25'],
    '15 percent depreciation on 4314071.10' => ['4314071.10', '15', '647110.67'],
    '13 percent VAT on 50.00' => ['50.00', '13', '6.50'],
    'zero percent' => ['1234.56', '0', '0.00'],
    'a hundred percent' => ['1234.56', '100', '1234.56'],
]);

test('plus and minus are exact', function () {
    expect(Money::of('0.10')->plus('0.20')->toString())->toBe('0.30')
        ->and(Money::of('1000000.01')->plus('0.99')->toString())->toBe('1000000.99')
        ->and(Money::of('56.50')->minus('56.50')->toString())->toBe('0.00')
        ->and(Money::of('10.00')->minus('25.50')->toString())->toBe('-15.50');
});

test('a zero result never prints as negative zero', function () {
    expect(Money::of('-0.00')->toString())->toBe('0.00')
        ->and(Money::of('0.00')->negated()->toString())->toBe('0.00')
        ->and(Money::of('5.00')->minus('5.00')->toString())->toBe('0.00')
        ->and(Money::round('-0.001')->toString())->toBe('0.00')
        ->and(Money::of('-0.00')->format())->toBe('0.00');
});

test('sum adds an iterable exactly and an empty set gives zero', function () {
    expect(Money::sum([Money::of('1.01'), '2.02', 3])->toString())->toBe('6.03')
        ->and(Money::sum([])->toString())->toBe('0.00');
});

test('max and min compare exactly', function () {
    expect(Money::max('1.00', '9.50', '-3.00')->toString())->toBe('9.50')
        ->and(Money::min('1.00', '9.50', '-3.00')->toString())->toBe('-3.00');
});

test('max without any value is an error', function () {
    Money::max();
})->throws(InvalidAmount::class);

test('multipliedBy rounds the exact product exactly once', function () {
    expect(Money::of('33.33')->multipliedBy(Quantity::of('1.5'))->toString())->toBe('50.00')
        ->and(Money::of('14209.99')->multipliedBy(Quantity::of('28.5'))->toString())->toBe('404984.72')
        ->and(Money::of('10.00')->multipliedBy(-3)->toString())->toBe('-30.00');
});

test('multipliedByFraction splits a value with a single rounding', function () {
    expect(Money::of('100.00')->multipliedByFraction('1', '3')->toString())->toBe('33.33')
        ->and(Money::of('100.00')->multipliedByFraction('2', '3')->toString())->toBe('66.67')
        ->and(Money::of('56.50')->multipliedByFraction('1', '2')->toString())->toBe('28.25');
});

test('multipliedByFraction refuses to divide by zero', function () {
    Money::of('1.00')->multipliedByFraction('1', '0');
})->throws(InvalidAmount::class);

test('comparisons are exact with no tolerance', function () {
    $amount = Money::of('56.50');

    expect($amount->isEqualTo('56.50'))->toBeTrue()
        ->and($amount->isEqualTo('56.49'))->toBeFalse()
        ->and($amount->isGreaterThan('56.49'))->toBeTrue()
        ->and($amount->isGreaterThanOrEqualTo('56.50'))->toBeTrue()
        ->and($amount->isLessThan('56.51'))->toBeTrue()
        ->and($amount->isLessThanOrEqualTo('56.50'))->toBeTrue()
        ->and($amount->compareTo('56.51'))->toBe(-1)
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and($amount->isPositive())->toBeTrue()
        ->and($amount->negated()->isNegative())->toBeTrue();
});

test('allocate splits proportionally with the largest remainder', function () {
    $parts = Money::of('100.00')->allocate(['1000.00', '500.00']);

    expect(array_map(fn (Money $part) => $part->toString(), $parts))->toBe(['66.67', '33.33'])
        ->and(Money::sum($parts)->toString())->toBe('100.00');
});

test('allocate gives the leftover paisa to the lowest index on a tie', function () {
    $parts = Money::of('0.05')->allocate(['1', '1', '1']);

    expect(array_map(fn (Money $part) => $part->toString(), $parts))->toBe(['0.02', '0.02', '0.01']);
});

test('allocate mirrors a negative amount part for part', function () {
    $parts = Money::of('-100.00')->allocate(['1000.00', '500.00']);

    expect(array_map(fn (Money $part) => $part->toString(), $parts))->toBe(['-66.67', '-33.33'])
        ->and(Money::sum($parts)->toString())->toBe('-100.00');
});

test('allocate gives a zero weight a zero share', function () {
    $parts = Money::of('10.00')->allocate(['1', '0']);

    expect(array_map(fn (Money $part) => $part->toString(), $parts))->toBe(['10.00', '0.00']);
});

test('allocate rejects weights it cannot split by', function (array $weights) {
    expect(fn () => Money::of('10.00')->allocate($weights))->toThrow(InvalidAmount::class);
})->with([
    'no weights' => [[]],
    'every weight zero' => [['0', '0.00']],
    'a negative weight' => [['-1', '2']],
]);

test('allocated parts always add back up to the original amount', function () {
    mt_srand(20260912);

    for ($run = 0; $run < 500; $run++) {
        $amount = Money::of(sprintf('%d.%02d', mt_rand(0, 999999), mt_rand(0, 99)));
        $weights = [];

        for ($index = 0, $count = mt_rand(2, 6); $index < $count; $index++) {
            $weights[] = sprintf('%d.%04d', mt_rand(0, 5000), mt_rand(1, 9999));
        }

        expect(Money::sum($amount->allocate($weights))->toString())->toBe($amount->toString());
    }
});

test('format groups digits the Indian way', function (string $value, string $expected) {
    expect(Money::of($value)->format())->toBe($expected);
})->with([
    ['1234567.5', '12,34,567.50'],
    ['123456789', '12,34,56,789.00'],
    ['-1234', '-1,234.00'],
    ['999.99', '999.99'],
    ['1000', '1,000.00'],
    ['0', '0.00'],
]);

test('a Money serialises to a plain decimal string', function () {
    expect(json_encode(['total' => Money::of('1.5')]))->toBe('{"total":"1.50"}')
        ->and((string) Money::of('1.5'))->toBe('1.50')
        ->and(Money::of('1.5')->jsonSerialize())->toBe('1.50');
});

test('toBigDecimal hands back the value at the scale', function () {
    expect(Money::of('1.5')->toBigDecimal()->toString())->toBe('1.50');
});
