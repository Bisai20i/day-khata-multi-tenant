/**
 * Tests for `resources/js/lib/quantity.js` (exact 4dp quantity arithmetic used by the POS).
 *
 * Run with: node --test tests/js
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import {
    addQuantity,
    compareQuantity,
    stepQuantity,
    subtractQuantity,
    toScaledQuantity,
} from '../../resources/js/lib/quantity.js';

test('addQuantity is exact on fractional quantities', () => {
    assert.equal(addQuantity('0.125', '1'), '1.1250');
    assert.equal(addQuantity('0.1', '0.2'), '0.3000');
});

test('subtractQuantity can go negative', () => {
    assert.equal(subtractQuantity('1', '2.5'), '-1.5000');
});

test('stepQuantity keeps the fractional part intact', () => {
    assert.equal(stepQuantity('0.125', 1), '1.1250');
    assert.equal(stepQuantity('2', -1), '1.0000');
});

test('compareQuantity returns -1, 0, 1 and null for unparsable input', () => {
    assert.equal(compareQuantity('1', '2'), -1);
    assert.equal(compareQuantity('2.0', '2.0000'), 0);
    assert.equal(compareQuantity('3', '2'), 1);
    assert.equal(compareQuantity('abc', '2'), null);
});

test('blank input counts as zero and invalid input yields null', () => {
    assert.equal(toScaledQuantity(''), 0n);
    assert.equal(toScaledQuantity(null), 0n);
    assert.equal(toScaledQuantity('x'), null);
    assert.equal(addQuantity('x', '1'), null);
});
