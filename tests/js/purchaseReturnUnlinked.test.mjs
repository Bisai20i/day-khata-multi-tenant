/**
 * Tests for `resources/js/lib/purchaseReturnUnlinked.js`.
 *
 * Run with: node --test tests/js
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import {
    isFilledUnlinkedLine,
    unlinkedModeNeedsBank,
    unlinkedQuoteQuery,
    unlinkedRefundModeOptions,
} from '../../resources/js/lib/purchaseReturnUnlinked.js';

const values = (options) => options.map((option) => option.value);

test('credit to supplier is only offered once a supplier is chosen', () => {
    assert.deepEqual(values(unlinkedRefundModeOptions({ canSplit: false, hasSupplier: false })), ['cash', 'bank']);
    assert.deepEqual(values(unlinkedRefundModeOptions({ canSplit: false, hasSupplier: true })), ['cash', 'bank', 'credit']);
    assert.equal(unlinkedRefundModeOptions({ canSplit: false, hasSupplier: true }).at(-1).label, 'Credit to supplier');
});

test('cash + bank is only offered when the exact total is known', () => {
    assert.deepEqual(values(unlinkedRefundModeOptions({ canSplit: true, hasSupplier: true })), ['cash', 'bank', 'partial', 'credit']);
});

test('only bank and split refunds ask for a bank account', () => {
    assert.equal(unlinkedModeNeedsBank('cash'), false);
    assert.equal(unlinkedModeNeedsBank('credit'), false);
    assert.equal(unlinkedModeNeedsBank('bank'), true);
    assert.equal(unlinkedModeNeedsBank('partial'), true);
});

test('a line needs an item and a non-zero quantity to be priced', () => {
    assert.equal(isFilledUnlinkedLine({ item_id: 3, quantity: '2' }), true);
    assert.equal(isFilledUnlinkedLine({ item_id: null, quantity: '2' }), false);
    assert.equal(isFilledUnlinkedLine({ item_id: 3, quantity: '' }), false);
    assert.equal(isFilledUnlinkedLine({ item_id: 3, quantity: '0' }), false);
});

test('the quote query sends quantities and rates as typed and leaves blank rates out', () => {
    const query = new URLSearchParams(
        unlinkedQuoteQuery({ date: '2026-06-01', vat_rate: '13' }, [
            { item_id: 3, item_unit_id: null, quantity: '0.1250', rate: '' },
            { item_id: 4, item_unit_id: 9, quantity: '2', rate: '10.5000' },
        ]),
    );

    assert.equal(query.get('date'), '2026-06-01');
    assert.equal(query.get('vat_rate'), '13');
    assert.equal(query.get('lines[0][quantity]'), '0.1250');
    assert.equal(query.has('lines[0][rate]'), false);
    assert.equal(query.has('lines[0][item_unit_id]'), false);
    assert.equal(query.get('lines[1][item_unit_id]'), '9');
    assert.equal(query.get('lines[1][rate]'), '10.5000');
});
