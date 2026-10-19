/**
 * Tests for `resources/js/lib/format.js` (contract C8).
 *
 * Run with: node --test tests/js
 *
 * The point of `todayInKathmandu()` is the 05:45 offset: `new Date().toISOString()` is the
 * UTC day, so between midnight and 05:44 in Kathmandu it names yesterday. That is how a bill
 * entered at 06:00 local ends up dated a day early, and near a fiscal-year boundary it is how
 * it ends up in the wrong year.
 */

import assert from 'node:assert/strict';
import test from 'node:test';

import { adToBs, bsToAd, bsToAdString, daysInBsMonth } from '../../resources/js/lib/nepali-calendar.js';
import { formatBsDate, todayInKathmandu } from '../../resources/js/lib/format.js';

test('todayInKathmandu returns a plain YYYY-MM-DD AD date', () => {
    assert.match(todayInKathmandu(), /^\d{4}-\d{2}-\d{2}$/);
});

test('todayInKathmandu uses Nepal time, not UTC', () => {
    // 2026-09-11 19:30 UTC is 2026-09-12 01:15 in Kathmandu: the UTC day is still the 11th.
    const earlyMorning = new Date(Date.UTC(2026, 8, 11, 19, 30));
    assert.equal(earlyMorning.toISOString().slice(0, 10), '2026-09-11');
    assert.equal(todayInKathmandu(earlyMorning), '2026-09-12');

    // 2026-09-11 18:00 UTC is 2026-09-11 23:45 in Kathmandu: still the 11th both ways.
    assert.equal(todayInKathmandu(new Date(Date.UTC(2026, 8, 11, 18, 0))), '2026-09-11');

    // The exact rollover: 18:15 UTC is midnight in Kathmandu.
    assert.equal(todayInKathmandu(new Date(Date.UTC(2026, 8, 11, 18, 15))), '2026-09-12');

    // Midday UTC is the same day everywhere that matters here.
    assert.equal(todayInKathmandu(new Date(Date.UTC(2026, 8, 12, 12, 0))), '2026-09-12');
});

test('todayInKathmandu crosses a fiscal-year boundary on Nepal time', () => {
    // Shrawan 1, 2083 BS is 2026-07-17 AD. At 2026-07-16 19:00 UTC it is already Shrawan 1.
    assert.equal(todayInKathmandu(new Date(Date.UTC(2026, 6, 16, 19, 0))), '2026-07-17');
    assert.equal(formatBsDate(todayInKathmandu(new Date(Date.UTC(2026, 6, 16, 19, 0)))), '2083-04-01');
});

test('formatBsDate converts an AD date to the BS date the bill prints', () => {
    assert.equal(formatBsDate('1943-04-14'), '2000-01-01'); // the calendar epoch anchor
    assert.equal(formatBsDate('2026-09-12'), '2083-05-27');
    assert.equal(formatBsDate('2026-04-14'), '2083-01-01');
});

test('formatBsDate round-trips against bsToAdString', () => {
    const samples = [
        [2080, 1, 1],
        [2081, 9, 15],
        [2082, 12, 30],
        [2083, 4, 1],
    ];

    for (const [year, month, day] of samples) {
        const ad = bsToAdString(year, month, day);
        const expected = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

        assert.equal(formatBsDate(ad), expected);
    }
});

test('formatBsDate accepts the shapes a Laravel payload actually carries', () => {
    assert.equal(formatBsDate('2026-09-12T10:30:00.000000Z'), '2083-05-27');
    assert.equal(formatBsDate('2026-09-12 10:30:00'), '2083-05-27');
    assert.equal(formatBsDate(new Date(2026, 8, 12)), '2083-05-27');
});

test('formatBsDate stays blank rather than showing a wrong date', () => {
    assert.equal(formatBsDate(null), '');
    assert.equal(formatBsDate(undefined), '');
    assert.equal(formatBsDate(''), '');
    assert.equal(formatBsDate('not a date'), '');
    assert.equal(formatBsDate(12345), '');
    assert.equal(formatBsDate(new Date('nonsense')), '');
    assert.equal(formatBsDate('1800-01-01'), ''); // before the supported BS range
    assert.equal(formatBsDate('2500-01-01'), ''); // after it
});

// Regression: the BS 2083 row was once wrong, shifting dates from Ashoj onwards by a day.
const BS_2083_BOUNDARIES = [
    ['2026-10-17', 2083, 6, 31],
    ['2026-10-18', 2083, 7, 1],
    ['2026-11-16', 2083, 7, 30],
    ['2026-11-17', 2083, 8, 1],
    ['2027-01-14', 2083, 9, 30],
    ['2027-01-15', 2083, 10, 1],
    ['2027-04-13', 2083, 12, 30],
    ['2027-04-14', 2084, 1, 1],
];

test('adToBs and bsToAd agree on the BS 2083 boundary dates', () => {
    for (const [ad, year, month, day] of BS_2083_BOUNDARIES) {
        assert.deepEqual(adToBs(ad), { year, month, day }, `adToBs(${ad})`);
        assert.equal(bsToAdString(year, month, day), ad, `bsToAdString(${year}-${month}-${day})`);
    }
});

test('bsToAd rejects day 32 of Ashoj 2083 (a 31 day month)', () => {
    assert.throws(() => bsToAd(2083, 6, 32), RangeError);
});

test('every day of BS 2083 round-trips and is consecutive', () => {
    const monthDays = [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30];
    let previous = null;

    monthDays.forEach((days, index) => {
        const month = index + 1;
        assert.equal(daysInBsMonth(2083, month), days);

        for (let day = 1; day <= days; day++) {
            const ad = bsToAd(2083, month, day);
            assert.deepEqual(adToBs(ad), { year: 2083, month, day });

            const utc = Date.UTC(ad.year, ad.month - 1, ad.day);
            if (previous !== null) {
                assert.equal(utc - previous, 86400000);
            }
            previous = utc;
        }
    });
});
