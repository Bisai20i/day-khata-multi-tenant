/**
 * Tests for the permission filter behind the layout's Quick Create menu,
 * command-palette quick actions and dashboard quick buttons.
 * Run with: node --test tests/js
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import { createPermissionChecker } from '../../resources/js/lib/permissions.js';
import { QUICK_CREATE_ACTIONS, POS_PERMISSIONS, allowedActions, holdsAll } from '../../resources/js/lib/quickActions.js';

const CASHIER_KEYS = [
    'pos.view',
    'sales.view',
    'sales.create',
    'sales.print',
    'items.view',
    'customers.view',
    'customers.create',
    'receipts.view',
    'receipts.create',
];

test('a cashier only gets the create shortcuts their role grants', () => {
    const { can } = createPermissionChecker(CASHIER_KEYS);
    const labels = allowedActions(QUICK_CREATE_ACTIONS, can).map((action) => action.label);

    assert.deepEqual(labels, ['New sale', 'New customer']);
});

test('a user holding every create key gets every shortcut, in order', () => {
    const { can } = createPermissionChecker(['sales.create', 'purchases.create', 'quotations.create', 'customers.create', 'items.create']);

    assert.deepEqual(
        allowedActions(QUICK_CREATE_ACTIONS, can).map((action) => action.href),
        QUICK_CREATE_ACTIONS.map((action) => action.href),
    );
});

test('view keys alone grant no create shortcut', () => {
    const { can } = createPermissionChecker(['sales.view', 'purchases.view', 'quotations.view', 'customers.view', 'items.view']);

    assert.deepEqual(allowedActions(QUICK_CREATE_ACTIONS, can), []);
});

test('no permissions leaves the list empty', () => {
    const { can } = createPermissionChecker([]);

    assert.deepEqual(allowedActions(QUICK_CREATE_ACTIONS, can), []);
    assert.equal(holdsAll(POS_PERMISSIONS, can), false);
});

test('every listed key must be held, and an action without keys fails closed', () => {
    const { can } = createPermissionChecker(['pos.view']);

    assert.equal(holdsAll(['pos.view', 'sales.create'], can), false);
    assert.equal(holdsAll(['pos.view'], can), true);
    assert.equal(holdsAll([], can), false);
    assert.equal(holdsAll(undefined, can), false);
    assert.deepEqual(allowedActions([{ label: 'Keyless', href: '/x' }], () => true), []);
});

test('filtering never mutates the shared list', () => {
    const before = QUICK_CREATE_ACTIONS.map((action) => action.label);
    allowedActions(QUICK_CREATE_ACTIONS, () => false);

    assert.deepEqual(QUICK_CREATE_ACTIONS.map((action) => action.label), before);
    assert.ok(Object.isFrozen(QUICK_CREATE_ACTIONS));
});
