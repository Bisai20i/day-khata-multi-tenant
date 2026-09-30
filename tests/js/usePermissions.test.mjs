/**
 * Tests for the permission checker behind usePermissions() and the tenant nav
 * filter. Run with: node --test tests/js
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import { createPermissionChecker } from '../../resources/js/lib/permissions.js';
import { filterNavByPermission } from '../../resources/js/lib/filterNavByPermission.js';

test('can matches only granted keys', () => {
    const checker = createPermissionChecker(['sales.view', 'items.view']);
    assert.equal(checker.can('sales.view'), true);
    assert.equal(checker.can('sales.create'), false);
});

test('canAny is true when any key is granted and false for an empty array', () => {
    const checker = createPermissionChecker(['sales.view']);
    assert.equal(checker.canAny(['x.y', 'sales.view']), true);
    assert.equal(checker.canAny(['x.y']), false);
    assert.equal(checker.canAny([]), false);
});

test('empty, missing or invalid list grants nothing', () => {
    for (const list of [[], null, undefined, 'sales.view']) {
        const checker = createPermissionChecker(list);
        assert.equal(checker.can('sales.view'), false);
        assert.equal(checker.canAny(['sales.view']), false);
    }
});

test('isOwner is passed through and grants nothing by itself', () => {
    assert.equal(createPermissionChecker([], true).isOwner, true);
    assert.equal(createPermissionChecker([], true).can('sales.view'), false);
    assert.equal(createPermissionChecker([]).isOwner, false);
});

const item = (label, extra = {}) => ({ label, href: `/${label}`, ...extra });
const labels = (items) => items.map((entry) => entry.label);

test('nav filter: single key, any-of, and items without permission', () => {
    const can = createPermissionChecker(['a.view', 'c.view']).can;
    const sections = [
        {
            label: 'S',
            items: [
                item('one', { permission: 'a.view' }),
                item('two', { permission: 'b.view' }),
                item('three', { permissions: ['b.view', 'c.view'] }),
                item('four', { permissions: ['b.view', 'd.view'] }),
                item('open'),
            ],
        },
    ];
    const result = filterNavByPermission(sections, can);
    assert.deepEqual(labels(result[0].items), ['one', 'three', 'open']);
    assert.equal(sections[0].items.length, 5);
});

test('nav filter: empty groups and sections are dropped', () => {
    const can = createPermissionChecker([]).can;
    const sections = [
        { label: 'Hidden', items: [item('x', { permission: 'a.view' })] },
        { label: 'Shown', items: [item('y')] },
    ];
    assert.deepEqual(filterNavByPermission(sections, can).map((s) => s.label), ['Shown']);
});

test('nav filter: nested categories are filtered and pruned', () => {
    const can = createPermissionChecker(['a.view']).can;
    const sections = [
        {
            label: 'Nested',
            categories: [
                { label: 'Keep', items: [item('k1', { permission: 'a.view' }), item('k2', { permission: 'b.view' })] },
                { label: 'Drop', items: [item('d1', { permission: 'b.view' })] },
            ],
        },
        { label: 'AllGone', categories: [{ label: 'C', items: [item('z', { permission: 'b.view' })] }] },
    ];
    const result = filterNavByPermission(sections, can);
    assert.equal(result.length, 1);
    assert.deepEqual(result[0].categories.map((c) => c.label), ['Keep']);
    assert.deepEqual(labels(result[0].categories[0].items), ['k1']);
});
