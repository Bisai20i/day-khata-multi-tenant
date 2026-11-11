import { compareMoney, formatMoney } from '@/lib/money.js';

const ZERO = '0.00';

/**
 * A branch column amount (plan 12, section 5.9, BR13): Indian grouping, a
 * dash for zero so a wide by-branch table stays readable. Exact, no floats.
 *
 * @param {string|null|undefined} value decimal string; missing counts as zero
 * @returns {string}
 */
export function formatBranchAmount(value) {
    const amount = value ?? ZERO;

    return compareMoney(amount, ZERO) === 0 ? '-' : formatMoney(amount);
}

/**
 * A signed net (debit minus credit) as "1,200.00 Dr" or "300.00 Cr", a dash
 * for zero. The trial balance's per-branch closing balance uses it.
 *
 * @param {string|null|undefined} net decimal string, negative = credit
 * @returns {string}
 */
export function formatNetDrCr(net) {
    const value = net ?? ZERO;
    const sign = compareMoney(value, ZERO);

    if (sign === 0) {
        return '-';
    }

    const text = formatMoney(value);

    return sign > 0 ? `${text} Dr` : `${text.slice(1)} Cr`;
}
