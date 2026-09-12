<?php

use App\Support\Billing\BillingException;
use App\Support\Billing\DocumentCalculator;
use App\Support\Money\Money;

/**
 * The golden vectors in `tests/fixtures/billing-vectors.json` are the parity
 * lock between this calculator and its frontend mirror
 * (`resources/js/lib/money.js`, run by `tests/js/money.test.mjs`). Both sides
 * read the same file, so a bill can never add up to one amount in the browser
 * and another on the server again (audit P0-8, P0-9).
 *
 * Every `expected` value in that file was computed independently with bcmath
 * from the CONTRACTS C3 text, not by this calculator.
 */
dataset('billing_vectors', function () {
    $vectors = json_decode(
        file_get_contents(__DIR__.'/../../fixtures/billing-vectors.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $rows = [];

    foreach ($vectors as $vector) {
        $rows[$vector['name']] = [$vector];
    }

    return $rows;
});

test('the calculator agrees with every golden vector', function (array $vector) {
    if (isset($vector['expected_error'])) {
        try {
            DocumentCalculator::calculate($vector['lines'], $vector['header']);
            $this->fail("Expected the reason {$vector['expected_error']} but the document calculated cleanly.");
        } catch (BillingException $exception) {
            expect($exception->reason)->toBe($vector['expected_error'])
                ->and($exception->getMessage())->not->toBeEmpty();
        }

        return;
    }

    expect(DocumentCalculator::calculate($vector['lines'], $vector['header'])->toArray())
        ->toBe($vector['expected']);
})->with('billing_vectors');

test('the golden vector fixture covers both outcomes', function () {
    $vectors = json_decode(
        file_get_contents(__DIR__.'/../../fixtures/billing-vectors.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $errors = array_filter($vectors, fn (array $vector) => isset($vector['expected_error']));

    expect(count($vectors))->toBeGreaterThanOrEqual(30)
        ->and(count($errors))->toBeGreaterThanOrEqual(9);
});

test('VAT is rounded once per document, not once per line', function () {
    $lines = array_fill(0, 3, ['quantity' => '1', 'rate' => '33.33', 'vatable' => true]);

    $totals = DocumentCalculator::calculate($lines, ['vat_rate' => '13']);

    // 99.99 x 13% = 12.9987, one rounding gives 13.00. Rounding each line
    // first (4.33 x 3) would give 12.99 and a bill one paisa light.
    expect($totals->vatAmount->toString())->toBe('13.00')
        ->and($totals->total->toString())->toBe('112.99');
});

test('the totals object exposes the subtotal before the header discount', function () {
    $totals = DocumentCalculator::calculate(
        [
            ['quantity' => '1', 'rate' => '1000.00', 'vatable' => true],
            ['quantity' => '1', 'rate' => '500.00', 'vatable' => false],
        ],
        ['vat_rate' => '13', 'discount' => '100', 'discount_type' => 'flat']
    );

    expect($totals->subtotal()->toString())->toBe('1500.00')
        ->and($totals->headerDiscount->toString())->toBe('100.00')
        ->and($totals->headerDiscountVatable->plus($totals->headerDiscountNonVatable)->toString())->toBe('100.00');
});

test('a header discount is split so that no paisa is invented or lost', function () {
    $totals = DocumentCalculator::calculate(
        [
            ['quantity' => '3', 'rate' => '33.33', 'vatable' => true],
            ['quantity' => '1', 'rate' => '33.34', 'vatable' => false],
        ],
        ['vat_rate' => '13', 'discount' => '10.01', 'discount_type' => 'flat']
    );

    expect($totals->headerDiscountVatable->plus($totals->headerDiscountNonVatable)->toString())->toBe('10.01')
        ->and($totals->taxableAmount->plus($totals->nontaxableAmount)->toString())->toBe('123.32');
});

test('a document with no lines has no subtotal to bill', function () {
    expect(fn () => DocumentCalculator::calculate([], ['vat_rate' => '13']))
        ->toThrow(BillingException::class);
});

test('the VAT rate itself is validated', function (mixed $rate) {
    expect(fn () => DocumentCalculator::calculate(
        [['quantity' => '1', 'rate' => '100.00', 'vatable' => true]],
        ['vat_rate' => $rate]
    ))->toThrow(BillingException::class);
})->with([
    'missing' => [null],
    'above a hundred' => ['101'],
    'negative' => ['-1'],
    'too precise' => ['13.005'],
    'not a number' => ['thirteen'],
]);

test('assertExactSplit accepts a split that lands on the amount due', function () {
    DocumentCalculator::assertExactSplit(Money::of('56.50'), Money::of('56.00'), Money::of('0.50'));
    DocumentCalculator::assertExactSplit(Money::of('56.50'), Money::of('56.50'), Money::zero());

    expect(true)->toBeTrue();
});

test('assertExactSplit refuses a split that is even one paisa out', function (string $cash, string $bank) {
    try {
        DocumentCalculator::assertExactSplit(Money::of('56.50'), Money::of($cash), Money::of($bank));
        $this->fail("A split of {$cash} and {$bank} should not have been accepted.");
    } catch (BillingException $exception) {
        expect($exception->reason)->toBe('split_mismatch');
    }
})->with([
    'one paisa short' => ['56.49', '0.00'],
    'one paisa over' => ['56.51', '0.00'],
    'a negative part' => ['-1.00', '57.50'],
]);
