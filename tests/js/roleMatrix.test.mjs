/**
 * Tests for the role editor's permission matrix selection logic. Run with:
 * node --test tests/js
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import {
    changedKeys,
    isViewKey,
    keysOf,
    resourceOf,
    selectionState,
    setKeys,
    toggleKey,
    viewKeyOf,
} from '../../resources/js/lib/roleMatrix.js';

const available = [
    'sales.view',
    'sales.create',
    'sales.cancel',
    'sales_returns.view',
    'sales_returns.create',
    'sales_return_requests.create',
    'pos.view',
];

test('resource and view key come from the part before the first dot', () => {
    assert.equal(resourceOf('sales.create'), 'sales');
    assert.equal(resourceOf('sales_returns.view'), 'sales_returns');
    assert.equal(viewKeyOf('sales.cancel'), 'sales.view');
    assert.equal(isViewKey('sales.view'), true);
    assert.equal(isViewKey('sales.create'), false);
});

test('ticking a non-view key also ticks its resource view key', () => {
    assert.deepEqual(toggleKey([], 'sales.create', true, available), ['sales.view', 'sales.create']);
});

test('ticking a key whose resource has no view key ticks only that key', () => {
    assert.deepEqual(toggleKey([], 'sales_return_requests.create', true, available), ['sales_return_requests.create']);
});

test('a view key not offered by the matrix is never added', () => {
    assert.deepEqual(toggleKey([], 'sales.create', true, ['sales.create']), ['sales.create']);
});

test('unticking a view key unticks every other key of that resource only', () => {
    const selected = ['sales.view', 'sales.create', 'sales.cancel', 'sales_returns.view', 'pos.view'];
    assert.deepEqual(toggleKey(selected, 'sales.view', false, available), ['sales_returns.view', 'pos.view']);
});

test('unticking a non-view key leaves the view key ticked', () => {
    const selected = ['sales.view', 'sales.create'];
    assert.deepEqual(toggleKey(selected, 'sales.create', false, available), ['sales.view']);
});

test('select-all ticks every key and untick-all clears them, in display order', () => {
    const group = ['sales.cancel', 'sales.view', 'sales.create'];
    assert.deepEqual(setKeys(['pos.view'], group, true, available), ['sales.view', 'sales.create', 'sales.cancel', 'pos.view']);
    assert.deepEqual(setKeys(['sales.view', 'sales.create', 'pos.view'], group, false, available), ['pos.view']);
});

test('keys outside the matrix are ignored and dropped, inputs are not mutated', () => {
    const selected = ['sales.view', 'purchases.view'];
    const result = setKeys(selected, ['purchases.create', 'pos.view'], true, available);
    assert.deepEqual(result, ['sales.view', 'pos.view']);
    assert.deepEqual(selected, ['sales.view', 'purchases.view']);
});

test('selectionState reports all, some and none', () => {
    const keys = ['sales.view', 'sales.create'];
    assert.equal(selectionState(['sales.view', 'sales.create'], keys), 'all');
    assert.equal(selectionState(['sales.view'], keys), 'some');
    assert.equal(selectionState(['pos.view'], keys), 'none');
    assert.equal(selectionState(['pos.view'], []), 'none');
});

test('changedKeys lists keys added or removed since the saved state', () => {
    const changed = changedKeys(['sales.view', 'sales.cancel'], ['sales.view', 'sales.create']);
    assert.deepEqual([...changed].sort(), ['sales.cancel', 'sales.create']);
    assert.equal(changedKeys(['a.view'], ['a.view']).size, 0);
});

test('keysOf flattens a module tree in display order and tolerates junk', () => {
    const modules = [
        { module: 'sales', groups: [{ group: 'Sales', permissions: [{ key: 'sales.view' }, { key: 'sales.create' }] }] },
        { module: 'pos', groups: [{ group: 'POS', permissions: [{ key: 'pos.view' }] }] },
    ];
    assert.deepEqual(keysOf(modules), ['sales.view', 'sales.create', 'pos.view']);
    assert.deepEqual(keysOf(null), []);
});
