/**
 * Tests for `resources/js/lib/money.js` (contract C8).
 *
 * Run with: node --test tests/js
 *
 * Two jobs:
 *
 * 1. Replay every golden vector in `tests/fixtures/billing-vectors.json` - the same file
 *    `tests/Unit/Support/DocumentCalculatorTest.php` replays against the PHP calculator. If
 *    the two implementations ever drift, one of the two suites goes red on the same vector.
 * 2. Pin the JavaScript-specific traps that made the bill preview disagree with the stored
 *    bill (audit P0-8): float products, `toFixed` rounding down on a tie, `Math.round`
 *    rounding negative ties the wrong way, and integer precision past 2^53.
 */

import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import test from 'node:test';

import {
    addMoney,
    allocateMoney,
    calculateDocument,
    compareMoney,
    formatMoney,
    formatQuantity,
    formatRate,
    isZeroMoney,
    moneyEquals,
    multiplyMoney,
    parseMoney,
    parseQuantity,
    percentOf,
    rateExcludingVat,
    subtractMoney,
    sumMoney,
} from '../../resources/js/lib/money.js';

const VECTORS_PATH = new URL('../fixtures/billing-vectors.json', import.meta.url);

/**
 * Every value in a vector is compared character for character, because the exact decimal
 * string is part of the contract: `"56.50"`, not `56.5`. The one exception is `vatable`,
 * which is a boolean rather than a decimal, so it is compared by truthiness in case the PHP
 * side ever serialises it as `"1"`.
 */
const BOOLEAN_KEYS = new Set(['vatable']);

function loadVectors() {
    let raw;

    try {
        raw = readFileSync(VECTORS_PATH, 'utf8');
    } catch (error) {
        return { ok: false, error };
    }

    return { ok: true, vectors: JSON.parse(raw) };
}

function sameBoolean(actual, expected, where) {
    const truthy = (value) => value === true || value === 1 || value === '1' || value === 'true';

    assert.equal(truthy(actual), truthy(expected), where);
}

function assertShapeMatches(actual, expected, path) {
    const actualKeys = Object.keys(actual).sort();
    const expectedKeys = Object.keys(expected).sort();
    assert.deepEqual(actualKeys, expectedKeys, `${path}: the calculated keys must match the vector's keys`);

    for (const [key, expectedValue] of Object.entries(expected)) {
        const where = `${path}.${key}`;

        if (BOOLEAN_KEYS.has(key)) {
            sameBoolean(actual[key], expectedValue, where);
        } else {
            assert.equal(actual[key], expectedValue, where);
        }
    }
}

function assertTotalsMatch(actual, expected, name) {
    const { lines: expectedLines, ...expectedHeader } = expected;
    const { lines: actualLines, ...actualHeader } = actual;

    assertShapeMatches(actualHeader, expectedHeader, name);

    assert.equal(Array.isArray(expectedLines), true, `${name}: the vector must carry its lines`);
    assert.equal(actualLines.length, expectedLines.length, `${name}: line count`);
    expectedLines.forEach((expectedLine, index) => {
        assertShapeMatches(actualLines[index], expectedLine, `${name}.lines[${index}]`);
    });
}

// ---------------------------------------------------------------------------
// Golden vectors, shared with the PHP calculator
// ---------------------------------------------------------------------------

test('golden vectors: the fixture file exists', () => {
    const loaded = loadVectors();

    assert.equal(
        loaded.ok,
        true,
        'tests/fixtures/billing-vectors.json is missing or unreadable. It is the shared ' +
            'golden-vector file for the PHP and JS calculators.',
    );
    assert.equal(Array.isArray(loaded.vectors), true, 'The fixture must be an array of vectors.');
    assert.equal(loaded.vectors.length > 0, true, 'The fixture must contain at least one vector.');
});

const loaded = loadVectors();

if (loaded.ok && Array.isArray(loaded.vectors)) {
    loaded.vectors.forEach((vector, index) => {
        const name = vector.name ?? `vector ${index + 1}`;

        test(`golden vector: ${name}`, () => {
            const result = calculateDocument(vector.lines, vector.header);

            if (Object.prototype.hasOwnProperty.call(vector, 'expected_error')) {
                assert.equal(result.ok, false, `${name}: expected a failure, got totals`);
                assert.equal(result.reason, vector.expected_error, `${name}: failure reason`);
                assert.equal(typeof result.message, 'string');
                assert.equal(result.message.length > 0, true, `${name}: the failure needs a message`);

                return;
            }

            assert.equal(result.ok, true, `${name}: ${result.ok === false ? result.message : ''}`);
            assertTotalsMatch(result.totals, vector.expected, name);
        });
    });
}

// ---------------------------------------------------------------------------
// The float traps this module exists to remove
// ---------------------------------------------------------------------------

test('1.5 x 33.33 with 13% VAT totals 56.50, the float pipeline says 56.49', () => {
    const result = calculateDocument([{ quantity: '1.5', rate: '33.33', vatable: true }], { vat_rate: '13' });

    assert.equal(result.ok, true);
    assert.equal(result.totals.lines[0].gross, '50.00');
    assert.equal(result.totals.vat_amount, '6.50');
    assert.equal(result.totals.total, '56.50');

    // What the old preview did: sum unrounded line values, then round once at the end.
    const floatSubtotal = 1.5 * 33.33;
    const floatTotal = Math.round((floatSubtotal + floatSubtotal * 0.13) * 100) / 100;
    assert.equal(floatTotal, 56.49);
});

test('toFixed rounds a tie down, this module rounds it away from zero', () => {
    // The textbook JS surprise: the float nearest 1.005 is 1.00499999999999989.
    assert.equal((1.005).toFixed(2), '1.00');

    // The same value reached exactly - 0.67 x 1.5 is 1.005 - rounds away from zero instead.
    assert.equal(multiplyMoney('0.67', '1.5'), '1.01');
    assert.equal(percentOf('20.10', '5'), '1.01');
});

test('negative ties round away from zero, not toward +Infinity', () => {
    assert.equal(Math.round(-12.5), -12); // what the old preview did
    assert.equal(multiplyMoney('-0.25', '0.5'), '-0.13');
    assert.equal(multiplyMoney('0.25', '0.5'), '0.13');
    assert.equal(percentOf('-2.50', '5'), '-0.13');
});

test('0.1 + 0.2 style sums stay exact', () => {
    assert.notEqual(0.1 + 0.2, 0.3);

    assert.equal(addMoney('0.10', '0.20'), '0.30');
    assert.equal(sumMoney(Array.from({ length: 10 }, () => '0.07')), '0.70');
    assert.equal(sumMoney(Array.from({ length: 100 }, () => '0.01')), '1.00');
    assert.equal(subtractMoney('0.30', '0.10'), '0.20');
    assert.equal(sumMoney([]), '0.00');
});

test('a negative zero is never produced', () => {
    assert.equal(multiplyMoney('-0.01', '0.0001'), '0.00');
    assert.equal(percentOf('-0.01', '1'), '0.00');
    assert.equal(subtractMoney('-1.00', '-1.00'), '0.00');
    assert.equal(formatMoney('-0.00'), '0.00');
    assert.equal(formatQuantity('-0.0000'), '0');
    assert.equal(isZeroMoney('-0.00'), true);
});

test('4dp x 4dp stays exact past 2^53', () => {
    // 12345.6789 x 9876.5432 = 121932630.98917848 exactly; the 4dp result rounds HalfUp.
    const result = calculateDocument(
        [{ quantity: '12345.6789', rate: '2', vatable: false, conversion_factor: '9876.5432' }],
        { vat_rate: '0' },
    );

    assert.equal(result.ok, true);
    assert.equal(result.totals.lines[0].base_quantity, '121932630.9892');
    // The scaled product is 12193263098917848, well past Number.MAX_SAFE_INTEGER.
    assert.equal(123456789n * 98765432n > 9007199254740991n, true);

    // A DECIMAL(15,2) amount times a 4dp factor, still exact.
    assert.equal(multiplyMoney('99999999999.99', '1.0001'), '100009999999.99');
    assert.equal(multiplyMoney('9999999999999.99', '1'), '9999999999999.99');
});

// ---------------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------------

test('parseMoney accepts what a money column can store', () => {
    assert.deepEqual(parseMoney('56.5'), { ok: true, value: '56.50' });
    assert.deepEqual(parseMoney('56'), { ok: true, value: '56.00' });
    assert.deepEqual(parseMoney('-56.50'), { ok: true, value: '-56.50' });
    assert.deepEqual(parseMoney('+56.50'), { ok: true, value: '56.50' });
    assert.deepEqual(parseMoney(' 56.50 '), { ok: true, value: '56.50' });
    assert.deepEqual(parseMoney('1.500'), { ok: true, value: '1.50' }); // trailing zeros do not count
    assert.deepEqual(parseMoney(56.5), { ok: true, value: '56.50' });
    assert.deepEqual(parseMoney(0), { ok: true, value: '0.00' });
    assert.deepEqual(parseMoney('-0.00'), { ok: true, value: '0.00' });
});

test('parseMoney refuses anything it would have to round or guess at', () => {
    assert.deepEqual(parseMoney('1.005'), { ok: false, reason: 'too_many_decimals' });
    assert.deepEqual(parseMoney(1.005), { ok: false, reason: 'too_many_decimals' });
    assert.deepEqual(parseMoney('1,234.00'), { ok: false, reason: 'invalid_number' });
    assert.deepEqual(parseMoney('1e3'), { ok: false, reason: 'invalid_number' });
    assert.deepEqual(parseMoney(1e21), { ok: false, reason: 'invalid_number' });
    assert.deepEqual(parseMoney('.5'), { ok: false, reason: 'invalid_number' });
    assert.deepEqual(parseMoney('5.'), { ok: false, reason: 'invalid_number' });
    assert.deepEqual(parseMoney(''), { ok: false, reason: 'invalid_number' });
    assert.deepEqual(parseMoney(null), { ok: false, reason: 'invalid_number' });
    assert.deepEqual(parseMoney(undefined), { ok: false, reason: 'invalid_number' });
    assert.deepEqual(parseMoney(Number.NaN), { ok: false, reason: 'invalid_number' });
    assert.deepEqual(parseMoney(Number.POSITIVE_INFINITY), { ok: false, reason: 'invalid_number' });
    assert.deepEqual(parseMoney('abc'), { ok: false, reason: 'invalid_number' });
});

test('parseQuantity holds four decimals', () => {
    assert.deepEqual(parseQuantity('1.5'), { ok: true, value: '1.5000' });
    assert.deepEqual(parseQuantity('12.3456'), { ok: true, value: '12.3456' });
    assert.deepEqual(parseQuantity('0.00004'), { ok: false, reason: 'too_many_decimals' });
    assert.deepEqual(parseQuantity('1.23456'), { ok: false, reason: 'too_many_decimals' });
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

test('comparisons are exact, with no tolerance', () => {
    assert.equal(compareMoney('1.00', '1.01'), -1);
    assert.equal(compareMoney('1.01', '1.00'), 1);
    assert.equal(compareMoney('1.00', '1.000'), 0);
    assert.equal(moneyEquals('1.00', '1.01'), false);
    assert.equal(moneyEquals('-0.00', '0.00'), true);
    assert.equal(isZeroMoney('0.00'), true);
    assert.equal(isZeroMoney('0.01'), false);
});

test('allocateMoney splits by largest remainder and always sums back exactly', () => {
    assert.deepEqual(allocateMoney('100.00', ['1', '1', '1']), ['33.34', '33.33', '33.33']);
    assert.deepEqual(allocateMoney('-100.00', ['1', '1', '1']), ['-33.34', '-33.33', '-33.33']);
    assert.deepEqual(allocateMoney('100.00', ['1000.00', '500.00']), ['66.67', '33.33']);
    assert.deepEqual(allocateMoney('100.00', ['1', '0']), ['100.00', '0.00']);
    assert.deepEqual(allocateMoney('0.01', ['1', '1']), ['0.01', '0.00']); // ties to the lowest index
    assert.deepEqual(allocateMoney('0.00', ['1', '1']), ['0.00', '0.00']);

    const parts = allocateMoney('999.99', ['7.3333', '2.1111', '0.5556']);
    assert.equal(sumMoney(parts), '999.99');

    assert.throws(() => allocateMoney('10.00', ['0', '0']), /cannot all be zero/);
    assert.throws(() => allocateMoney('10.00', ['1', '-1']), /cannot be negative/);
    assert.throws(() => allocateMoney('10.00', []), /at least one weight/);
});

test('formatting follows the Indian convention', () => {
    assert.equal(formatMoney('1234567.5'), '12,34,567.50');
    assert.equal(formatMoney('-1234'), '-1,234.00');
    assert.equal(formatMoney('100000000'), '10,00,00,000.00');
    assert.equal(formatMoney('999.5'), '999.50');
    assert.equal(formatMoney('0'), '0.00');
    assert.equal(formatMoney('12345'), '12,345.00');

    assert.equal(formatQuantity('1.5000'), '1.5');
    assert.equal(formatQuantity('2.0000'), '2');
    assert.equal(formatQuantity('0.0001'), '0.0001');
    assert.equal(formatQuantity('-1.2500'), '-1.25');

    assert.equal(formatRate('12.5000'), '12.50');
    assert.equal(formatRate('12.3456'), '12.3456');
    assert.equal(formatRate('12'), '12.00');
    assert.equal(formatRate('12.3400'), '12.34');
});

test('an MRP is turned back into a VAT-exclusive rate exactly', () => {
    // The case the whole feature exists for: a Rs 113 sticker price at 13% is
    // a rate of exactly 100, not 99.9999 and not 100.0001 (a float divide gives
    // 99.99999999999999).
    assert.deepEqual(rateExcludingVat('113', '13'), { ok: true, value: '100.0000' });
    assert.deepEqual(rateExcludingVat('113.00', '13.00'), { ok: true, value: '100.0000' });
    assert.deepEqual(rateExcludingVat(113, 13), { ok: true, value: '100.0000' });

    // No VAT to strip: an exempt line, or any line on a PAN invoice.
    assert.deepEqual(rateExcludingVat('113', '0'), { ok: true, value: '113.0000' });

    // A quotient that does not land on 4 decimals rounds HalfUp exactly once:
    // 100 / 1.13 = 88.495575..., and 565 / 1.13 = 500 exactly.
    assert.deepEqual(rateExcludingVat('100', '13'), { ok: true, value: '88.4956' });
    assert.deepEqual(rateExcludingVat('565', '13'), { ok: true, value: '500.0000' });
    assert.deepEqual(rateExcludingVat('0', '13'), { ok: true, value: '0.0000' });

    // 1.005 at 0% VAT would round to 1.0050 if it were allowed at all, but the
    // scale guard rejects an MRP with more decimals than a rate column holds.
    assert.deepEqual(rateExcludingVat('1.00005', '13'), { ok: false, reason: 'too_many_decimals' });
    assert.deepEqual(rateExcludingVat('abc', '13'), { ok: false, reason: 'invalid_number' });
    assert.deepEqual(rateExcludingVat('', '13'), { ok: false, reason: 'invalid_number' });
    assert.deepEqual(rateExcludingVat('-113', '13'), { ok: false, reason: 'negative_rate' });
    assert.deepEqual(rateExcludingVat('113', '-1'), { ok: false, reason: 'percentage_out_of_range' });
    assert.deepEqual(rateExcludingVat('113', '101'), { ok: false, reason: 'percentage_out_of_range' });
    assert.deepEqual(rateExcludingVat('113', '13.005'), { ok: false, reason: 'too_many_decimals' });
});

test('an MRP-derived rate reprices to the MRP through the document calculator', () => {
    // The round trip the cashier actually sees: MRP 113 becomes rate 100, and
    // one unit at that rate bills 100 + 13 VAT = 113, the sticker price.
    const rate = rateExcludingVat('113', '13');
    assert.equal(rate.ok, true);

    const result = calculateDocument([{ quantity: '1', rate: rate.value, vatable: true }], { vat_rate: '13' });

    assert.equal(result.ok, true);
    assert.equal(result.totals.taxable_amount, '100.00');
    assert.equal(result.totals.vat_amount, '13.00');
    assert.equal(result.totals.total, '113.00');
});

// ---------------------------------------------------------------------------
// calculateDocument, step by step against C3
// ---------------------------------------------------------------------------

test('a header discount is split proportionally between the VAT and exempt subtotals', () => {
    // The case the audit used for P0-9: a 1000 VAT item, a 500 exempt item, 100 off the bill.
    const result = calculateDocument(
        [
            { quantity: '1', rate: '1000', vatable: true },
            { quantity: '1', rate: '500', vatable: false },
        ],
        { vat_rate: '13', discount: '100' },
    );

    assert.equal(result.ok, true);
    assert.equal(result.totals.header_discount_vatable, '66.67');
    assert.equal(result.totals.header_discount_non_vatable, '33.33');
    assert.equal(addMoney(result.totals.header_discount_vatable, result.totals.header_discount_non_vatable), '100.00');
    assert.equal(result.totals.taxable_amount, '933.33');
    assert.equal(result.totals.nontaxable_amount, '466.67');
    assert.equal(result.totals.vat_amount, '121.33');
    assert.equal(result.totals.total, '1521.33');
});

test('a percentage header discount rounds once against the subtotal', () => {
    const result = calculateDocument([{ quantity: '1', rate: '1001.50', vatable: true }], {
        vat_rate: '13',
        discount: '7.13',
        discount_type: 'percentage',
    });

    assert.equal(result.ok, true);
    assert.equal(result.totals.header_discount, '71.41'); // 1001.50 x 7.13% = 71.40695
    assert.equal(result.totals.taxable_amount, '930.09');
    assert.equal(result.totals.vat_amount, '120.91'); // 930.09 x 13% = 120.9117
    assert.equal(result.totals.total, '1051.00');
});

test('purchase VAT on 1 x 1001.50 is 130.20, not the float preview 130.19', () => {
    const result = calculateDocument([{ quantity: '1', rate: '1001.50', vatable: true }], { vat_rate: '13' });

    assert.equal(result.ok, true);
    assert.equal(result.totals.vat_amount, '130.20');
    assert.equal(result.totals.total, '1131.70');
});

test('force_non_taxable zeroes the VAT on a PAN invoice even for vatable lines', () => {
    const result = calculateDocument([{ quantity: '2', rate: '500', vatable: true }], {
        vat_rate: '13',
        force_non_taxable: true,
    });

    assert.equal(result.ok, true);
    assert.equal(result.totals.lines[0].vatable, false);
    assert.equal(result.totals.vatable_subtotal, '0.00');
    assert.equal(result.totals.taxable_amount, '0.00');
    assert.equal(result.totals.vat_amount, '0.00');
    assert.equal(result.totals.nontaxable_amount, '1000.00');
    assert.equal(result.totals.total, '1000.00');
});

test('a line discount moves the line toward zero, in both directions', () => {
    const flat = calculateDocument([{ quantity: '2', rate: '100', discount: '15', vatable: false }], {
        vat_rate: '13',
    });
    assert.equal(flat.ok, true);
    assert.equal(flat.totals.lines[0].discount_amount, '15.00');
    assert.equal(flat.totals.lines[0].line_total, '185.00');

    const percentage = calculateDocument(
        [{ quantity: '1', rate: '4.10', discount: '15', discount_type: 'percentage', vatable: false }],
        { vat_rate: '13' },
    );
    assert.equal(percentage.ok, true);
    // 15% of 4.10 is 0.615: HalfUp gives 0.62, PHP 8.4's round() gave 0.61 (audit P0-1).
    assert.equal(percentage.totals.lines[0].discount_amount, '0.62');
    assert.equal(percentage.totals.lines[0].line_total, '3.48');

    const negative = calculateDocument(
        [
            { quantity: '-1', rate: '100', discount: '10', vatable: false },
            { quantity: '5', rate: '100', vatable: false },
        ],
        { vat_rate: '13' },
    );
    assert.equal(negative.ok, true);
    assert.equal(negative.totals.lines[0].gross, '-100.00');
    assert.equal(negative.totals.lines[0].discount_amount, '-10.00');
    assert.equal(negative.totals.lines[0].line_total, '-90.00');
    assert.equal(negative.totals.total, '410.00');
});

test('the base quantity uses the unit conversion factor', () => {
    const result = calculateDocument(
        [{ quantity: '2', rate: '1200', vatable: false, conversion_factor: '12' }],
        { vat_rate: '13' },
    );

    assert.equal(result.ok, true);
    assert.equal(result.totals.lines[0].base_quantity, '24.0000');
    assert.equal(result.totals.lines[0].gross, '2400.00');
});

test('TDS reduces the settlement due but not the total', () => {
    const result = calculateDocument([{ quantity: '1', rate: '1000', vatable: true }], {
        vat_rate: '13',
        tds_amount: '15',
    });

    assert.equal(result.ok, true);
    assert.equal(result.totals.total, '1130.00');
    assert.equal(result.totals.tds_amount, '15.00');
    assert.equal(result.totals.settlement_due, '1115.00');
});

test('expected_total guards the preview the user actually saw', () => {
    const agreed = calculateDocument([{ quantity: '1.5', rate: '33.33', vatable: true }], {
        vat_rate: '13',
        expected_total: '56.50',
    });
    assert.equal(agreed.ok, true);

    const drifted = calculateDocument([{ quantity: '1.5', rate: '33.33', vatable: true }], {
        vat_rate: '13',
        expected_total: '56.49',
    });
    assert.equal(drifted.ok, false);
    assert.equal(drifted.reason, 'total_mismatch');
});

test('every rejection carries a shared reason code and a readable message', () => {
    const cases = [
        ['negative_rate', [{ quantity: '1', rate: '-1', vatable: false }], { vat_rate: '13' }],
        [
            'invalid_conversion_factor',
            [{ quantity: '1', rate: '10', vatable: false, conversion_factor: '0' }],
            { vat_rate: '13' },
        ],
        ['too_many_decimals', [{ quantity: '1', rate: '1.23456', vatable: false }], { vat_rate: '13' }],
        ['invalid_number', [{ quantity: 'abc', rate: '10', vatable: false }], { vat_rate: '13' }],
        ['percentage_out_of_range', [{ quantity: '1', rate: '10', vatable: false }], { vat_rate: '101' }],
        [
            'percentage_out_of_range',
            [{ quantity: '1', rate: '10', discount: '120', discount_type: 'percentage', vatable: false }],
            { vat_rate: '13' },
        ],
        ['negative_discount', [{ quantity: '1', rate: '10', discount: '-1', vatable: false }], { vat_rate: '13' }],
        [
            'line_discount_exceeds_line',
            [{ quantity: '1', rate: '10', discount: '11', vatable: false }],
            { vat_rate: '13' },
        ],
        ['subtotal_not_positive', [{ quantity: '0', rate: '10', vatable: false }], { vat_rate: '13' }],
        ['subtotal_not_positive', [], { vat_rate: '13' }],
        [
            'header_discount_exceeds_subtotal',
            [{ quantity: '1', rate: '10', vatable: false }],
            { vat_rate: '13', discount: '11' },
        ],
        [
            'negative_discount',
            [{ quantity: '1', rate: '10', vatable: false }],
            { vat_rate: '13', discount: '-1' },
        ],
        [
            'tds_exceeds_base',
            [{ quantity: '1', rate: '10', vatable: false }],
            { vat_rate: '13', tds_amount: '11' },
        ],
        [
            'total_mismatch',
            [{ quantity: '1', rate: '10', vatable: false }],
            { vat_rate: '13', expected_total: '9.99' },
        ],
    ];

    for (const [reason, lines, header] of cases) {
        const result = calculateDocument(lines, header);

        assert.equal(result.ok, false, `expected ${reason}, got totals`);
        assert.equal(result.reason, reason);
        assert.equal(typeof result.message === 'string' && result.message.length > 0, true);
    }
});

test('a header discount cannot push a group total negative', () => {
    // A credit-heavy exempt group plus a bill discount would leave a negative exempt total.
    const result = calculateDocument(
        [
            { quantity: '1', rate: '1000', vatable: true },
            { quantity: '-1', rate: '10', vatable: false },
        ],
        { vat_rate: '13', discount: '100' },
    );

    assert.equal(result.ok, false);
    assert.equal(result.reason, 'negative_group_total');
});
