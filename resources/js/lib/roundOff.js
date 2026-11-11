import { parseMoney, subtractMoney } from '@/lib/money';

/**
 * The steps a tenant can round bills to (Settings > Round off). Mirrors
 * CompanySetting::ROUND_OFF_PRECISIONS. 0.01 is deliberately absent: every
 * amount already has 2 decimals, so it could only ever suggest 0.00.
 */
export const ROUND_OFF_PRECISIONS = ['10', '1', '0.5', '0.1', '0.05'];

/** A canonical 2dp money string as whole paisa. */
function toPaisa(text) {
    const negative = text[0] === '-';
    const [whole, fraction] = (negative ? text.slice(1) : text).split('.');
    const value = BigInt(whole + fraction);

    return negative ? -value : value;
}

/** Whole paisa back to a canonical 2dp money string. */
function fromPaisa(paisa) {
    const negative = paisa < 0n;
    const digits = (negative ? -paisa : paisa).toString().padStart(3, '0');

    return (negative ? '-' : '') + digits.slice(0, -2) + '.' + digits.slice(-2);
}

/**
 * The signed Round Off that takes `total` (the bill before any Round Off) to
 * its nearest multiple of `precision`, ties rounding up: 1,234.30 at step 1
 * gives -0.30, 1,234.50 gives +0.50, and 1,234.30 at step 0.5 gives +0.20.
 *
 * Exact BigInt arithmetic on paisa, never a float, like the rest of money.js.
 *
 * @param {string|number} total the bill total before Round Off
 * @param {string|number} precision one of ROUND_OFF_PRECISIONS
 * @returns {string|null} a signed 2dp string, or null when there is nothing to
 *   suggest (already on the step, unreadable input, or rounding would take the
 *   bill to zero)
 */
export function suggestRoundOff(total, precision) {
    const parsedTotal = parseMoney(total);
    const parsedStep = parseMoney(precision);

    if (!parsedTotal.ok || !parsedStep.ok) {
        return null;
    }

    const totalPaisa = toPaisa(parsedTotal.value);
    const step = toPaisa(parsedStep.value);

    if (step <= 0n || totalPaisa <= 0n) {
        return null;
    }

    const rounded = ((totalPaisa + step / 2n) / step) * step;

    if (rounded <= 0n || rounded === totalPaisa) {
        return null;
    }

    return fromPaisa(rounded - totalPaisa);
}

/**
 * The bill total with the typed Round Off taken back out, which is what the
 * suggestion has to round. Null while the Round Off field holds something the
 * calculator would reject.
 *
 * @param {string} totalWithRoundOff `totals.total` from calculateDocument
 * @param {string|number|null} roundOff the form's current Round Off field
 * @returns {string|null}
 */
export function totalBeforeRoundOff(totalWithRoundOff, roundOff) {
    const entered = parseMoney(roundOff === '' || roundOff === null || roundOff === undefined ? '0' : roundOff);

    return entered.ok ? subtractMoney(totalWithRoundOff, entered.value) : null;
}
