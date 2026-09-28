/**
 * Tests for `resources/js/lib/purchaseCreate.js`.
 *
 * Run with: node --test tests/js
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import { emptyPurchaseLine, restorePurchaseDraft } from '../../resources/js/lib/purchaseCreate.js';

test('emptyPurchaseLine is a blank base-unit line with a flat discount', () => {
    assert.deepEqual(emptyPurchaseLine(), {
        item_id: null,
        item_unit_id: '',
        quantity: '',
        bonus_quantity: '',
        rate: '',
        discount: '',
        discount_type: 'flat',
        note: '',
    });
});

test('emptyPurchaseLine hands out a fresh object each time', () => {
    const first = emptyPurchaseLine();
    first.quantity = '5';

    assert.equal(emptyPurchaseLine().quantity, '');
});

test('restorePurchaseDraft drops blank placeholder lines but keeps real ones and the header', () => {
    const draft = {
        supplier_id: 7,
        narration: 'Weekly stock',
        lines: [
            { ...emptyPurchaseLine(), item_id: 3, quantity: '2', rate: '50' },
            emptyPurchaseLine(),
        ],
    };

    const restored = restorePurchaseDraft(draft);

    assert.equal(restored.supplier_id, 7);
    assert.equal(restored.narration, 'Weekly stock');
    assert.equal(restored.lines.length, 1);
    assert.equal(restored.lines[0].item_id, 3);
});

test('restorePurchaseDraft copes with a draft that has no lines', () => {
    assert.deepEqual(restorePurchaseDraft({ supplier_id: 1 }).lines, []);
});
