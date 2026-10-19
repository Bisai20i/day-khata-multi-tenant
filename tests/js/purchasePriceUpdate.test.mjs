/**
 * Tests for `resources/js/lib/purchasePriceUpdate.js`.
 *
 * Run with: node --test tests/js
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import {
    baselineRate,
    canonicalRate,
    lineKey,
    pendingFor,
    priceChangePrompt,
    priceCheckOf,
    pricePayload,
    priceRows,
    rescalePlan,
    rescaledRate,
} from '../../resources/js/lib/purchasePriceUpdate.js';

const rice = () => ({
    id: 1,
    name: 'Rice',
    unit: 'pcs',
    purchase_rate: '10.0000',
    units: [
        { id: 5, name: 'Box', conversion_factor: '12.0000', purchase_rate: '120.0000', is_active: true },
        { id: 6, name: 'Carton', conversion_factor: '24.0000', purchase_rate: null, is_active: true },
    ],
});

/** A Rice line; `price_check` as usePurchasePriceConfirm() leaves it. */
const line = (fields = {}) => ({ item_id: 1, item_unit_id: null, rate: '', ...fields });

/** A line that said Yes to the item, unit and rate it has now. */
const confirmedLine = (fields) => {
    const value = line(fields);

    return { ...value, price_check: { confirmed: lineKey(value) } };
};

const ask = (fields, { item = rice(), pending = null } = {}) => priceChangePrompt({ item, line: line(fields), pending });

test('canonicalRate is the exact 4dp form, keeps the sign of a negative, and rejects malformed input', () => {
    assert.equal(canonicalRate('10'), '10.0000');
    assert.equal(canonicalRate('10.124'), '10.1240');
    assert.equal(canonicalRate(12), '12.0000');
    assert.equal(canonicalRate('-0.005'), '-0.0050');
    assert.equal(canonicalRate('-12'), '-12.0000');
    assert.equal(canonicalRate('.5'), null);
    assert.equal(canonicalRate('1e3'), null);
    assert.equal(canonicalRate('1.23456'), null);
    assert.equal(canonicalRate(''), null);
});

test('rescaledRate divides by the chosen factor and multiplies by the target, rounding once', () => {
    assert.equal(rescaledRate('264', 240000n, 10000n), '11.00');
    assert.equal(rescaledRate('264', 240000n, 120000n), '132.00');
    assert.equal(rescaledRate('100', 120000n, 10000n), '8.33');
    assert.equal(rescaledRate('11', 10000n, 240000n), '264.00');
});

test('rescaledRate with a factor of 3, and HalfUp on an exact tie, never through floats', () => {
    assert.equal(rescaledRate('10', 30000n, 10000n), '3.33');
    assert.equal(rescaledRate('3.335', 10000n, 30000n), '10.01');
    // Box 100.02 / 12 = 8.335 exactly: the tie rounds up, as on the server.
    assert.equal(rescaledRate('100.02', 120000n, 10000n), '8.34');
    // Math.round(1.005 * 100) / 100 is 1 - the float bug the server never had.
    assert.equal(rescaledRate('1.005', 10000n, 10000n), '1.01');
});

test('rescaledRate refuses a missing or non-positive factor, a zero result and an overflow', () => {
    assert.equal(rescaledRate('100', 0n, 10000n), null);
    assert.equal(rescaledRate('100', 10000n, 0n), null);
    assert.equal(rescaledRate('100', null, 10000n), null);
    // Carton (1000) at 4 -> base 0.004 -> 0.00.
    assert.equal(rescaledRate('4', 10000000n, 10000n), null);
    // pcs 100000 -> a unit of 1,000,000 would be 100,000,000,000: over DECIMAL(15,4).
    assert.equal(rescaledRate('100000', 10000n, 10000000000n), null);
});

test('rescalePlan is all or nothing: one out-of-range unit leaves every other unit alone', () => {
    const item = rice();
    item.units.push({ id: 7, name: 'Crate', conversion_factor: '1000.0000', purchase_rate: '9999.0000', is_active: true });
    const rows = priceRows(item);
    const crate = rows.find((row) => row.unitId === '7');

    assert.deepEqual(rescalePlan(rows, crate, '4.0000'), { others: [], skipped: true });

    const plan = rescalePlan(rows, crate, '12000.0000');
    assert.equal(plan.skipped, false);
    assert.deepEqual(
        plan.others.map(({ row, rate }) => [row.name, rate]),
        [
            ['pcs', '12.00'],
            ['Box', '144.00'],
            ['Carton', '288.00'],
        ],
    );

    const prompt = ask({ item_unit_id: 7, rate: '4' }, { item });
    assert.equal(prompt.rescaleSkipped, true);
    assert.equal(prompt.onlyThisUnit, false);
    assert.deepEqual(prompt.others, []);
});

test('priceRows lists the base unit first and leaves out inactive units', () => {
    const item = rice();
    item.units.push({ id: 7, name: 'Sack', conversion_factor: '50.0000', purchase_rate: '500.0000', is_active: false });

    assert.deepEqual(
        priceRows(item).map((row) => [row.unitId, row.name, row.savedRate]),
        [
            [null, 'pcs', '10.0000'],
            ['5', 'Box', '120.0000'],
            ['6', 'Carton', null],
        ],
    );
});

test('a changed rate prompts with old -> new and a preview of the other units', () => {
    const prompt = ask({ item_unit_id: 5, rate: '132' });

    assert.equal(prompt.key, '1|5|132.0000');
    assert.equal(prompt.unitName, 'Box');
    assert.equal(prompt.oldLabel, '120.00');
    assert.equal(prompt.newLabel, '132.00');
    assert.equal(prompt.newRate, '132.0000');
    assert.equal(prompt.rescaleSkipped, false);
    assert.deepEqual(prompt.others, [
        { name: 'pcs', oldLabel: '10.00', newLabel: '11.00' },
        { name: 'Carton', oldLabel: 'not set', newLabel: '264.00' },
    ]);
});

test('the prompt compares at the stored 4dp precision, exactly', () => {
    assert.equal(ask({ item_unit_id: '', rate: '10' }), null);
    assert.equal(ask({ rate: '10.0000' }), null);
    // Rounds to the same 2dp, but is a different stored price.
    assert.notEqual(ask({ rate: '10.004' }), null);
});

test('no prompt for a zero, blank or invalid rate, or a line without an item', () => {
    for (const rate of ['0', '', '-5', '1.23456', '.5', '1e3']) {
        assert.equal(ask({ rate }), null, rate);
    }
    assert.equal(ask({ item_id: null, rate: '12' }), null);
});

test('lineKey is the item, unit and canonical rate; a base-unit line\'s \'\' is no unit', () => {
    assert.equal(lineKey(line({ item_unit_id: 5, rate: '132' })), '1|5|132.0000');
    assert.equal(lineKey(line({ item_unit_id: '', rate: '12.5' })), '1||12.5000');
    assert.equal(lineKey(line({ rate: '12.5' })), lineKey(line({ item_unit_id: '', rate: '12.50' })));
    assert.equal(lineKey(line({ rate: '' })), null);
    assert.equal(lineKey(line({ item_id: null, rate: '12' })), null);
});

test('no prompt when the line holds what the form prefilled, even if it differs from today\'s saved price', () => {
    const prefilled = (rate) => ({ rate, price_check: { prefilled: '1||9.5000' } });

    assert.equal(ask(prefilled('9.50')), null);
    assert.notEqual(ask(prefilled('9.75')), null);
    assert.notEqual(ask(prefilled('9.5001')), null);
    // The prefill was for the base unit: the same rate carried to a unit with no saved price asks.
    assert.notEqual(ask({ ...prefilled('9.50'), item_unit_id: 6 }), null);
});

test('every rate a line declined stays declined on that line, and only on it (PUR-19)', () => {
    const declined = { price_check: { declined: ['1||12.0000', '1||13.0000'] } };

    assert.equal(ask({ ...declined, rate: '12' }), null);
    assert.equal(ask({ ...declined, rate: '13.00' }), null);
    assert.notEqual(ask({ ...declined, rate: '14' }), null);
    assert.notEqual(ask({ ...declined, rate: '12.0001' }), null);
    // Declined for pcs, not for Box.
    assert.notEqual(ask({ ...declined, item_unit_id: 5, rate: '12' }), null);
    // Another line at the same rate has its own answers.
    assert.notEqual(ask({ rate: '12' }), null);
});

test('a line that already said Yes at exactly its current rate is not asked again', () => {
    assert.equal(priceChangePrompt({ item: rice(), line: confirmedLine({ rate: '12' }) }), null);
});

test('priceCheckOf defaults a line without answers, and answers are plain data that survive the draft round trip', () => {
    assert.deepEqual(priceCheckOf(line()), { prefilled: null, confirmed: null, declined: [] });
    assert.deepEqual(priceCheckOf(line({ price_check: { declined: ['1||12.0000'] } })), { prefilled: null, confirmed: null, declined: ['1||12.0000'] });

    const lines = [
        confirmedLine({ item_unit_id: 5, rate: '132' }),
        line({ rate: '12', price_check: { prefilled: null, confirmed: null, declined: ['1||12.0000'] } }),
    ];
    const restored = JSON.parse(JSON.stringify(lines));

    assert.deepEqual(pricePayload(restored), pricePayload(lines));
    assert.deepEqual(pricePayload(restored), [{ item_id: 1, item_unit_id: 5, rate: '132.0000' }]);
    // The No survives too: no second prompt after the quick-add bounce.
    assert.equal(priceChangePrompt({ item: rice(), line: restored[1] }), null);
});

test('a Yes in force on another line becomes the baseline, rescaled to the unit', () => {
    const pending = { item_id: 1, item_unit_id: 6, rate: '264.0000' };

    // Carton 264 -> pcs 11, Box 132: re-entering those rates asks nothing.
    assert.equal(ask({ rate: '11' }, { pending }), null);
    assert.equal(ask({ item_unit_id: 5, rate: '132' }, { pending }), null);
    assert.equal(ask({ rate: '12' }, { pending }).oldLabel, '11.00');
});

test('a unit with a zero or null conversion factor prompts for itself only (PUR-16)', () => {
    for (const factor of ['0.0000', null]) {
        const item = rice();
        item.units[0].conversion_factor = factor;

        const prompt = ask({ item_unit_id: 5, rate: '150' }, { item });
        assert.equal(prompt.onlyThisUnit, true, String(factor));
        assert.deepEqual(prompt.others, []);

        // Nor is a pending Yes on it rescaled onto the other units.
        const rows = priceRows(item);
        assert.equal(baselineRate(rows, rows[0], { item_id: 1, item_unit_id: 5, rate: '150.0000' }), '10.0000');
    }
});

test('pendingFor is the Yes of the last other line of the item that still matches it', () => {
    const lineA = confirmedLine({ rate: '12' });
    const lineB = confirmedLine({ item_unit_id: 5, rate: '150' });

    assert.deepEqual(pendingFor([lineA, lineB], 1), { item_id: 1, item_unit_id: 5, rate: '150.0000' });
    // The line being asked about never counts as its own baseline.
    assert.deepEqual(pendingFor([lineA, lineB], 1, lineB), { item_id: 1, item_unit_id: null, rate: '12.0000' });
    assert.equal(pendingFor([lineA, lineB], 2), null);

    // Re-priced or moved to another unit after its Yes: no longer in force.
    assert.equal(pendingFor([{ ...lineA, rate: '13' }], 1), null);
    assert.equal(pendingFor([{ ...lineB, item_unit_id: 6 }], 1), null);
});

test('a Yes belongs to its line: removing the line drops it, a new line at that rate asks again (PUR-14)', () => {
    const yesLine = confirmedLine({ item_unit_id: 5, rate: '150' });
    assert.deepEqual(pricePayload([yesLine]), [{ item_id: 1, item_unit_id: 5, rate: '150.0000' }]);

    // Line removed, another added at the same rate: nothing in force, so it asks, against the saved price.
    const newLine = line({ item_unit_id: 5, rate: '150' });
    assert.deepEqual(pricePayload([newLine]), []);
    assert.equal(priceChangePrompt({ item: rice(), line: newLine, pending: pendingFor([newLine], 1, newLine) }).oldLabel, '120.00');
});

test('a Yes is matched to its own line at the exact 4dp rate', () => {
    const yesLine = confirmedLine({ rate: '10.124' });
    const edited = { ...yesLine, rate: '10.1249' };

    // Edited from 10.124 to 10.1249 after the Yes: not the rate confirmed.
    assert.deepEqual(pricePayload([edited]), []);
    // Once the composable lapses that Yes, the edit is asked about against the saved price.
    const lapsed = { ...edited, price_check: { confirmed: null } };
    const prompt = priceChangePrompt({ item: rice(), line: lapsed, pending: pendingFor([lapsed], 1, lapsed) });
    assert.equal(prompt.oldLabel, '10.00');
    assert.equal(prompt.newRate, '10.1249');

    // A neighbour line at a different 4dp rate never borrows the Yes.
    assert.deepEqual(pricePayload([line({ rate: '10.1249' }), yesLine]), [{ item_id: 1, item_unit_id: null, rate: '10.1240' }]);
});

test('pricePayload sends one entry per item; when two lines of an item say Yes, the last line wins', () => {
    const lines = [
        confirmedLine({ item_unit_id: 5, rate: '132' }),
        confirmedLine({ item_unit_id: 5, rate: '144' }),
        confirmedLine({ item_id: 2, rate: '50' }),
        // Re-priced after its Yes: left out.
        { ...confirmedLine({ item_id: 3, rate: '70' }), rate: '75' },
    ];

    assert.deepEqual(pricePayload(lines), [
        { item_id: 1, item_unit_id: 5, rate: '144.0000' },
        { item_id: 2, item_unit_id: null, rate: '50.0000' },
    ]);
});

test('pricePayload keeps the server\'s exact match and sends a base-unit line\'s \'\' unit as null', () => {
    assert.deepEqual(pricePayload([confirmedLine({ item_unit_id: '', rate: '10' })]), [{ item_id: 1, item_unit_id: null, rate: '10.0000' }]);
    assert.deepEqual(pricePayload([confirmedLine({ item_unit_id: 5, rate: '10.125' })]), [{ item_id: 1, item_unit_id: 5, rate: '10.1250' }]);
});

test('pricePayload matches the unit too, and sends nothing without a Yes', () => {
    assert.deepEqual(pricePayload([{ ...confirmedLine({ item_unit_id: 5, rate: '120' }), item_unit_id: null }]), []);
    assert.deepEqual(pricePayload([line({ rate: '120' })]), []);
    assert.deepEqual(pricePayload([]), []);
});
