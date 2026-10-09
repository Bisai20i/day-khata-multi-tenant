/**
 * Tests for the pure helpers behind the add/edit form modals.
 * Run with: node --test tests/js
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import { findCreatedByName, formSnapshot, hasAnyError, hasAnyValue } from '../../resources/js/lib/formModal.js';

test('formSnapshot is equal for equal data and differs once a field changes', () => {
    const opened = formSnapshot({ name: 'Tea', is_active: true, image: null });
    assert.equal(formSnapshot({ name: 'Tea', is_active: true, image: null }), opened);
    assert.notEqual(formSnapshot({ name: 'Tea ', is_active: true, image: null }), opened);
    assert.notEqual(formSnapshot({ name: 'Tea', is_active: false, image: null }), opened);
});

test('formSnapshot notices a chosen file', () => {
    const empty = formSnapshot({ name: 'Tea', image: null });
    const withFile = formSnapshot({ name: 'Tea', image: new File(['x'], 'tea.png', { lastModified: 1 }) });
    const otherFile = formSnapshot({ name: 'Tea', image: new File(['xy'], 'tea.png', { lastModified: 1 }) });
    assert.notEqual(withFile, empty);
    assert.notEqual(withFile, otherFile);
});

test('hasAnyError only looks at the listed fields', () => {
    const errors = { hs_code: 'Too long.' };
    assert.equal(hasAnyError(errors, ['hs_code', 'image']), true);
    assert.equal(hasAnyError(errors, ['name']), false);
    assert.equal(hasAnyError({}, ['name']), false);
    assert.equal(hasAnyError(undefined, ['name']), false);
});

test('hasAnyValue treats null, undefined and empty string as empty but keeps 0 and false', () => {
    assert.equal(hasAnyValue({ hs_code: null, description: '' }, ['hs_code', 'description', 'missing']), false);
    assert.equal(hasAnyValue({ hs_code: '8471.30' }, ['hs_code']), true);
    assert.equal(hasAnyValue({ min_stock: 0 }, ['min_stock']), true);
    assert.equal(hasAnyValue({ flag: false }, ['flag']), true);
    assert.equal(hasAnyValue(null, ['hs_code']), false);
});

test('findCreatedByName matches trimmed and case-insensitively, newest first', () => {
    const records = [
        { id: 1, name: 'Beverages' },
        { id: 7, name: 'beverages ' },
        { id: 3, name: 'Grocery' },
    ];
    assert.equal(findCreatedByName(records, ' BEVERAGES').id, 7);
    assert.equal(findCreatedByName(records, 'Grocery').id, 3);
    assert.equal(findCreatedByName(records, 'Dairy'), null);
    assert.equal(findCreatedByName(records, ''), null);
    assert.equal(findCreatedByName(undefined, 'Dairy'), null);
});

test('findCreatedByName respects the scope fields', () => {
    const records = [
        { id: 1, name: 'Soft drinks', item_category_id: 1 },
        { id: 2, name: 'Soft drinks', item_category_id: 2 },
    ];
    assert.equal(findCreatedByName(records, 'Soft drinks', { item_category_id: 1 }).id, 1);
    assert.equal(findCreatedByName(records, 'Soft drinks', { item_category_id: 9 }), null);
});
