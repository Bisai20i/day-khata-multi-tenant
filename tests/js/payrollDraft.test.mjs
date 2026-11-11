/**
 * Tests for the pure helpers of `resources/js/composables/usePayrollDraft.js`.
 *
 * Run with: node --test tests/js
 *
 * The draft grid decides what is "unsaved", what the live totals say and which
 * server errors are stale, all from exact-decimal strings (never floats).
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import {
    changedPayrollDraftPaths,
    draftFromRun,
    payrollDraftFieldValues,
    payrollDraftFingerprint,
    payrollDraftTotals,
    validatePayrollDraft,
} from '../../resources/js/composables/usePayrollDraft.js';

function sampleRun(amount = '1500.00', recovery = '0.00') {
    return draftFromRun(
        { period_label: 'Baisakh 2083', period_start: '2026-04-14', period_end: '2026-05-14', date: '2026-05-14', narration: null },
        [
            {
                staff_id: 7,
                staff_code: 'S-1',
                staff_name: 'Sita',
                advance_recovery: recovery,
                note: null,
                items: [{ pay_component_id: 3, component_name: 'Basic', amount, note: null }],
            },
        ],
    );
}

test('fingerprint treats "1500" and "1500.00" as the same draft', () => {
    assert.equal(payrollDraftFingerprint(sampleRun('1500')), payrollDraftFingerprint(sampleRun('1500.00')));
    assert.notEqual(payrollDraftFingerprint(sampleRun('1500.01')), payrollDraftFingerprint(sampleRun('1500.00')));
});

test('totals are exact decimals and unparseable amounts count as zero', () => {
    const state = sampleRun('0.10', '0.00');
    state.lines[0].items.push({ key: 99, pay_component_id: 4, component_name: 'Bonus', amount: '0.20', note: '' });
    state.lines[0].items.push({ key: 100, pay_component_id: 5, component_name: 'Other', amount: 'abc', note: '' });
    state.lines[0].advance_recovery = '0.05';

    assert.deepEqual(payrollDraftTotals(state.lines), { count: 1, gross: '0.30', recovery: '0.05', net: '0.25' });
});

test('advance recovery is capped by gross pay and by the outstanding advance', () => {
    const state = sampleRun('1000.00', '1500.00');
    assert.equal(validatePayrollDraft(state, { advanceBalances: { 7: '2000.00' } })['lines.0.advance_recovery'], 'The advance recovery cannot exceed the gross pay.');

    state.lines[0].advance_recovery = '600.00';
    assert.equal(
        validatePayrollDraft(state, { advanceBalances: { 7: '500.00' } })['lines.0.advance_recovery'],
        'The advance recovery is more than the outstanding advance.',
    );
    assert.equal(validatePayrollDraft(state, { advanceBalances: { 7: '600.00' } })['lines.0.advance_recovery'], undefined);
});

test('a staff member who is no longer active is flagged, only when the active set is supplied', () => {
    const state = sampleRun();

    assert.equal(validatePayrollDraft(state)['lines.0.staff_id'], undefined);
    assert.equal(validatePayrollDraft(state, { activeStaffIds: new Set([7]) })['lines.0.staff_id'], undefined);
    assert.equal(
        validatePayrollDraft(state, { activeStaffIds: new Set([8]) })['lines.0.staff_id'],
        'This staff member is no longer active; remove them from the run.',
    );
});

test('a recovery of zero is allowed with no outstanding advance and a negative one is refused', () => {
    const state = sampleRun('1000.00', '0.00');
    assert.equal(validatePayrollDraft(state, { advanceBalances: [] })['lines.0.advance_recovery'], undefined);

    state.lines[0].advance_recovery = '-1.00';
    assert.equal(validatePayrollDraft(state, { advanceBalances: { 7: '5.00' } })['lines.0.advance_recovery'], 'The advance recovery cannot be negative.');
});

test('changedPayrollDraftPaths names only the edited fields', () => {
    const state = sampleRun();
    const before = payrollDraftFieldValues(state);

    state.lines[0].items[0].amount = '1600.00';
    const after = payrollDraftFieldValues(state);

    assert.deepEqual(changedPayrollDraftPaths(before, after), ['lines.0.items.0.amount']);
    assert.deepEqual(changedPayrollDraftPaths(after, after), []);
});

test('changedPayrollDraftPaths reports added and removed rows', () => {
    const state = sampleRun();
    const before = payrollDraftFieldValues(state);

    state.lines[0].items.push({ key: 50, pay_component_id: 4, component_name: 'Bonus', amount: '10.00', note: '' });
    const paths = changedPayrollDraftPaths(before, payrollDraftFieldValues(state));

    assert.ok(paths.includes('lines.0.items'));
    assert.ok(paths.includes('lines.0.items.1.amount'));
});
