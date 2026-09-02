/**
 * Pure, dependency-free Gregorian (AD) <-> Bikram Sambat (BS) date conversion.
 *
 * Client-side mirror of `App\Support\NepaliCalendar` (app/Support/NepaliCalendar.php) -
 * same day-count table (BS 2000-2090, ported verbatim from the legacy day_khata app's
 * `App\Models\Nepali_Calendar`) and the same epoch anchor (BS 2000-01-01 == AD 1943-04-14),
 * so conversions match exactly between server and client. Keep this table in sync with the
 * PHP version if it's ever changed.
 *
 * AD dates are handled as plain {year, month, day} integers (month 1-12) rather than JS
 * `Date` objects throughout, to sidestep `Date`'s local-timezone/DST parsing footguns for
 * what are really just calendar dates with no time-of-day meaning.
 */

const MIN_BS_YEAR = 2000;
const MAX_BS_YEAR = 2090;

const EPOCH_AD = { year: 1943, month: 4, day: 14 };

/** @type {Record<number, number[]>} days in each of the 12 BS months, keyed by BS year */
const BS_MONTH_DAYS = {
    2000: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
    2001: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2002: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    2003: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2004: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
    2005: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2006: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    2007: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2008: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
    2009: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2010: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    2011: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2012: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
    2013: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2014: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    2015: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2016: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
    2017: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2018: [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    2019: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
    2020: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
    2021: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2022: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
    2023: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
    2024: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
    2025: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2026: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2027: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
    2028: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2029: [31, 31, 32, 31, 32, 30, 30, 29, 30, 29, 30, 30],
    2030: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2031: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
    2032: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2033: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    2034: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2035: [30, 32, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
    2036: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2037: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    2038: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2039: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
    2040: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2041: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    2042: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2043: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
    2044: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2045: [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    2046: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2047: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
    2048: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2049: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
    2050: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
    2051: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
    2052: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2053: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
    2054: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
    2055: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2056: [31, 31, 32, 31, 32, 30, 30, 29, 30, 29, 30, 30],
    2057: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2058: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
    2059: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2060: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    2061: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2062: [30, 32, 31, 32, 31, 31, 29, 30, 29, 30, 29, 31],
    2063: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2064: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    2065: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2066: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
    2067: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2068: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    2069: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2070: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
    2071: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2072: [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
    2073: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
    2074: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
    2075: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2076: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
    2077: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
    2078: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
    2079: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2080: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
    2081: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
    2082: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
    2083: [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30],
    2084: [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30],
    2085: [31, 32, 31, 32, 30, 31, 30, 30, 29, 30, 30, 30],
    2086: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
    2087: [31, 31, 32, 31, 31, 31, 30, 30, 29, 30, 30, 30],
    2088: [30, 31, 32, 32, 30, 31, 30, 30, 29, 30, 30, 30],
    2089: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
    2090: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
};

export const NEPALI_MONTH_NAMES = [
    'Baishakh',
    'Jestha',
    'Ashad',
    'Shrawan',
    'Bhadra',
    'Ashwin',
    'Kartik',
    'Mangsir',
    'Poush',
    'Magh',
    'Falgun',
    'Chaitra',
];

function isLeapAdYear(year) {
    return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
}

const AD_MONTH_DAYS = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
const AD_MONTH_DAYS_LEAP = [31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

/**
 * Days elapsed from the proleptic-Gregorian epoch (year 0000-03-01) to the given AD
 * calendar date. A monotonic day counter, not tied to any particular epoch meaning on
 * its own - only differences between two calls matter.
 */
function daysFromAdEpoch(year, month, day) {
    let days = day;

    for (let y = 1; y <= year - 1; y++) {
        days += isLeapAdYear(y) ? 366 : 365;
    }

    const months = isLeapAdYear(year) ? AD_MONTH_DAYS_LEAP : AD_MONTH_DAYS;
    for (let m = 0; m < month - 1; m++) {
        days += months[m];
    }

    return days;
}

/** Inverse of {@link daysFromAdEpoch}: reconstructs {year, month, day} from a day count. */
function adDateFromDays(totalDays) {
    let year = 1;
    let remaining = totalDays;

    while (true) {
        const yearDays = isLeapAdYear(year) ? 366 : 365;
        if (remaining <= yearDays) {
            break;
        }
        remaining -= yearDays;
        year++;
    }

    const months = isLeapAdYear(year) ? AD_MONTH_DAYS_LEAP : AD_MONTH_DAYS;
    let month = 1;
    for (const daysInMonth of months) {
        if (remaining <= daysInMonth) {
            break;
        }
        remaining -= daysInMonth;
        month++;
    }

    return { year, month, day: remaining };
}

function yearTotalDays(bsYear) {
    const months = BS_MONTH_DAYS[bsYear];
    if (!months) {
        throw new RangeError(`No calendar data for BS year ${bsYear}.`);
    }
    return months.reduce((sum, days) => sum + days, 0);
}

/**
 * Parses a 'YYYY-MM-DD' string into {year, month, day} without going through `Date`
 * (which would interpret it as UTC midnight and can shift a day depending on the
 * viewer's timezone).
 */
function parseAdDateString(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (!match) {
        throw new RangeError(`Expected an AD date string in "YYYY-MM-DD" format, got "${value}".`);
    }
    return { year: Number(match[1]), month: Number(match[2]), day: Number(match[3]) };
}

/**
 * Converts an AD (Gregorian) date to its BS (Bikram Sambat) equivalent.
 *
 * @param {string|Date|{year:number, month:number, day:number}} adDate
 * @returns {{year: number, month: number, day: number}}
 */
export function adToBs(adDate) {
    let year, month, day;

    if (typeof adDate === 'string') {
        ({ year, month, day } = parseAdDateString(adDate));
    } else if (adDate instanceof Date) {
        year = adDate.getFullYear();
        month = adDate.getMonth() + 1;
        day = adDate.getDate();
    } else {
        ({ year, month, day } = adDate);
    }

    const epochDays = daysFromAdEpoch(EPOCH_AD.year, EPOCH_AD.month, EPOCH_AD.day);
    const targetDays = daysFromAdEpoch(year, month, day);
    let remaining = targetDays - epochDays;

    if (remaining < 0) {
        throw new RangeError(`Date ${year}-${month}-${day} is before the supported Nepali calendar range (starts BS ${MIN_BS_YEAR}-01-01).`);
    }

    let bsYear = MIN_BS_YEAR;

    while (true) {
        const total = yearTotalDays(bsYear);
        if (remaining < total) {
            break;
        }
        remaining -= total;
        bsYear++;
        if (bsYear > MAX_BS_YEAR) {
            throw new RangeError(`Date ${year}-${month}-${day} is after the supported Nepali calendar range (ends BS ${MAX_BS_YEAR}).`);
        }
    }

    let bsMonth = 1;
    for (const daysInMonth of BS_MONTH_DAYS[bsYear]) {
        if (remaining < daysInMonth) {
            break;
        }
        remaining -= daysInMonth;
        bsMonth++;
    }

    return { year: bsYear, month: bsMonth, day: remaining + 1 };
}

/**
 * Converts a BS (Bikram Sambat) date to its AD (Gregorian) equivalent.
 *
 * @returns {{year: number, month: number, day: number}}
 */
export function bsToAd(bsYear, bsMonth, bsDay) {
    if (bsYear < MIN_BS_YEAR || bsYear > MAX_BS_YEAR) {
        throw new RangeError(`BS year must be between ${MIN_BS_YEAR} and ${MAX_BS_YEAR}, got ${bsYear}.`);
    }
    if (bsMonth < 1 || bsMonth > 12) {
        throw new RangeError(`BS month must be between 1 and 12, got ${bsMonth}.`);
    }

    const monthDays = BS_MONTH_DAYS[bsYear];
    const daysInMonth = monthDays[bsMonth - 1];

    if (bsDay < 1 || bsDay > daysInMonth) {
        throw new RangeError(`BS ${bsYear}-${bsMonth} only has ${daysInMonth} days, got day ${bsDay}.`);
    }

    let daysSinceEpoch = 0;

    for (let year = MIN_BS_YEAR; year < bsYear; year++) {
        daysSinceEpoch += yearTotalDays(year);
    }
    for (let month = 1; month < bsMonth; month++) {
        daysSinceEpoch += monthDays[month - 1];
    }
    daysSinceEpoch += bsDay - 1;

    const epochDays = daysFromAdEpoch(EPOCH_AD.year, EPOCH_AD.month, EPOCH_AD.day);

    return adDateFromDays(epochDays + daysSinceEpoch);
}

/** Zero-pads a {year, month, day} AD object into a "YYYY-MM-DD" string. */
export function formatAdDateString({ year, month, day }) {
    return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/** Converts a BS date straight to an AD "YYYY-MM-DD" string - what `NepaliDateInput` emits. */
export function bsToAdString(bsYear, bsMonth, bsDay) {
    return formatAdDateString(bsToAd(bsYear, bsMonth, bsDay));
}

/** How many days are in a given BS year/month - used to bound the day picker. */
export function daysInBsMonth(bsYear, bsMonth) {
    if (bsYear < MIN_BS_YEAR || bsYear > MAX_BS_YEAR) {
        throw new RangeError(`BS year must be between ${MIN_BS_YEAR} and ${MAX_BS_YEAR}, got ${bsYear}.`);
    }
    if (bsMonth < 1 || bsMonth > 12) {
        throw new RangeError(`BS month must be between 1 and 12, got ${bsMonth}.`);
    }
    return BS_MONTH_DAYS[bsYear][bsMonth - 1];
}

export { MIN_BS_YEAR, MAX_BS_YEAR };
