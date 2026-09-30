/**
 * Tests for the central module checkbox dependency logic. Run with:
 * node --test tests/js
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import { dependentsOf, requirementLabels, resolveModules, toggleModule } from '../../resources/js/lib/moduleSelection.js';

/** Same shape and order as config/permissions.php, plus a two-step chain (kiosk -> pos -> sales). */
const catalog = [
    { key: 'core', label: 'Core', always_on: true, requires: [] },
    { key: 'sales', label: 'Sales', always_on: false, requires: [] },
    { key: 'pos', label: 'Point of sale', always_on: false, requires: ['sales'] },
    { key: 'kiosk', label: 'Kiosk', always_on: false, requires: ['pos'] },
    { key: 'agents', label: 'Sales agents', always_on: false, requires: ['sales'] },
    { key: 'purchases', label: 'Purchases', always_on: false, requires: [] },
];

test('resolve adds always_on modules and transitive requirements in catalog order', () => {
    assert.deepEqual(resolveModules(catalog, ['kiosk']), ['core', 'sales', 'pos', 'kiosk']);
    assert.deepEqual(resolveModules(catalog, []), ['core']);
    assert.deepEqual(resolveModules(catalog, ['purchases', 'bogus']), ['core', 'purchases']);
    assert.deepEqual(resolveModules(catalog, null), ['core']);
});

test('resolve is cycle safe', () => {
    const cyclic = [
        { key: 'a', label: 'A', always_on: false, requires: ['b'] },
        { key: 'b', label: 'B', always_on: false, requires: ['a'] },
    ];
    assert.deepEqual(resolveModules(cyclic, ['a']), ['a', 'b']);
});

test('dependents are transitive and exclude the module itself', () => {
    assert.deepEqual(dependentsOf(catalog, 'sales'), ['pos', 'kiosk', 'agents']);
    assert.deepEqual(dependentsOf(catalog, 'pos'), ['kiosk']);
    assert.deepEqual(dependentsOf(catalog, 'purchases'), []);
});

test('ticking a module ticks its requirements', () => {
    const result = toggleModule(catalog, ['core'], 'kiosk', true);
    assert.deepEqual(result.selected, ['core', 'sales', 'pos', 'kiosk']);
    assert.deepEqual(result.removed, []);
});

test('unticking a required module unticks and reports its selected dependents', () => {
    const result = toggleModule(catalog, ['core', 'sales', 'pos', 'agents', 'purchases'], 'sales', false);
    assert.deepEqual(result.selected, ['core', 'purchases']);
    assert.deepEqual(result.removed, ['pos', 'agents']);
});

test('unticking a leaf module removes only that module', () => {
    const result = toggleModule(catalog, ['core', 'sales', 'pos'], 'pos', false);
    assert.deepEqual(result.selected, ['core', 'sales']);
    assert.deepEqual(result.removed, []);
});

test('always_on modules cannot be unticked and unknown keys change nothing', () => {
    assert.deepEqual(toggleModule(catalog, ['sales'], 'core', false).selected, ['core', 'sales']);
    assert.deepEqual(toggleModule(catalog, ['sales'], 'bogus', true).selected, ['core', 'sales']);
});

test('the input selection is not mutated', () => {
    const selected = ['core', 'sales', 'pos'];
    toggleModule(catalog, selected, 'sales', false);
    assert.deepEqual(selected, ['core', 'sales', 'pos']);
});

test('requirement labels come from the catalog', () => {
    assert.deepEqual(requirementLabels(catalog, 'pos'), ['Sales']);
    assert.deepEqual(requirementLabels(catalog, 'purchases'), []);
});
