/**
 * Date helpers shared by every tenant screen.
 *
 * Two things this fixes (contract C8):
 *
 * 1. `new Date().toISOString().slice(0, 10)` is the UTC date, not the Nepali one. Nepal is
 *    UTC+05:45, so from 00:00 to 05:44 Kathmandu time that expression returns *yesterday* -
 *    an early-morning sale, receipt or payment would default to the wrong day, and a
 *    back-dated voucher can land in the wrong fiscal year. `todayInKathmandu()` replaces
 *    every one of those defaults.
 * 2. Screens that show a stored AD date to a Nepali user should show the BS date.
 *    `formatBsDate()` is the client-side twin of `App\Support\NepaliCalendar::formatBs()`
 *    and returns the same `YYYY-MM-DD` BS string.
 *
 * The conversion itself lives in `nepali-calendar.js` (imported relatively, not through the
 * `@/` alias, so plain `node:test` files can import this module with no bundler).
 */

import { adToBs, formatAdDateString } from './nepali-calendar.js';

const KATHMANDU_TIME_ZONE = 'Asia/Kathmandu';

/**
 * Cached because constructing an `Intl.DateTimeFormat` is the expensive part, and POS calls
 * this on every new sale.
 *
 * @type {Intl.DateTimeFormat|null}
 */
let kathmanduFormatter = null;

function getKathmanduFormatter() {
    if (kathmanduFormatter === null) {
        kathmanduFormatter = new Intl.DateTimeFormat('en-US', {
            timeZone: KATHMANDU_TIME_ZONE,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        });
    }

    return kathmanduFormatter;
}

/**
 * Pulls the calendar date out of anything a Laravel payload might carry: `"2026-09-12"`,
 * `"2026-09-12 10:30:00"`, `"2026-09-12T10:30:00.000000Z"` or a `Date`.
 *
 * @returns {string|null} `YYYY-MM-DD`, or `null` when there is nothing usable.
 */
function toAdDateString(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (value instanceof Date) {
        if (Number.isNaN(value.getTime())) {
            return null;
        }

        return formatAdDateString({
            year: value.getFullYear(),
            month: value.getMonth() + 1,
            day: value.getDate(),
        });
    }

    if (typeof value !== 'string') {
        return null;
    }

    const match = /^(\d{4}-\d{2}-\d{2})/.exec(value.trim());

    return match ? match[1] : null;
}

/**
 * Today's date in Nepal, as the plain `YYYY-MM-DD` AD string every date field and API
 * payload in this app uses. Never `toISOString()`, which would give the UTC day.
 *
 * @param {Date} [now] injectable for tests
 * @returns {string}
 */
export function todayInKathmandu(now = new Date()) {
    const parts = getKathmanduFormatter().formatToParts(now);
    const found = {};

    for (const part of parts) {
        if (part.type === 'year' || part.type === 'month' || part.type === 'day') {
            found[part.type] = part.value;
        }
    }

    return `${found.year}-${found.month}-${found.day}`;
}

/**
 * Formats a stored AD date as its Bikram Sambat equivalent, `YYYY-MM-DD` (BS) - the same
 * shape `App\Support\NepaliCalendar::formatBs()` prints on PDFs.
 *
 * Returns an empty string for a blank, unparsable or out-of-range date rather than showing a
 * wrong one, matching how `NepaliDateInput` handles the same case.
 *
 * @param {string|Date|null|undefined} isoDate
 * @returns {string}
 */
export function formatBsDate(isoDate) {
    const adDate = toAdDateString(isoDate);
    if (adDate === null) {
        return '';
    }

    try {
        const bs = adToBs(adDate);

        return `${String(bs.year).padStart(4, '0')}-${String(bs.month).padStart(2, '0')}-${String(bs.day).padStart(2, '0')}`;
    } catch {
        return '';
    }
}
