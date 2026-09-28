/**
 * Tests for `resources/js/lib/journalVoucherCreate.js`.
 *
 * Run with: node --test tests/js
 *
 * The balancing suggestion pre-fills the next voucher line, so it has to name
 * the exact paisa difference on the correct side - a suggestion on the wrong
 * side doubles the imbalance instead of closing it.
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import { balancingEntry, emptyVoucherLine, voucherAmountOf } from '../../resources/js/lib/journalVoucherCreate.js';

test('emptyVoucherLine starts with no account and blank amounts', () => {
    assert.deepEqual(emptyVoucherLine(), { account_id: null, debit: '', credit: '', narration: '' });
});

test('voucherAmountOf treats a blank box as zero and keeps typed amounts exact', () => {
    assert.equal(voucherAmountOf(''), '0.00');
    assert.equal(voucherAmountOf(null), '0.00');
    assert.equal(voucherAmountOf('1500.5'), '1500.50');
});

test('voucherAmountOf refuses an amount with more than two decimals instead of rounding it', () => {
    assert.equal(voucherAmountOf('10.005'), null);
    assert.equal(voucherAmountOf('abc'), null);
});

test('balancingEntry suggests a credit when debits are ahead', () => {
    assert.deepEqual(balancingEntry('1000.00', '400.25'), { debit: '', credit: '599.75' });
});

test('balancingEntry suggests a debit when credits are ahead', () => {
    assert.deepEqual(balancingEntry('100.00', '100.01'), { debit: '0.01', credit: '' });
});

test('balancingEntry suggests nothing once the voucher balances', () => {
    assert.deepEqual(balancingEntry('0.00', '0.00'), { debit: '', credit: '' });
    assert.deepEqual(balancingEntry('250.00', '250.00'), { debit: '', credit: '' });
});
