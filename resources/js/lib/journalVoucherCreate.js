import { compareMoney, isZeroMoney, parseMoney, subtractMoney } from './money.js';

/**
 * Pure helpers for Accounting/JournalVouchers/Create.vue. Nothing here
 * touches component state.
 */

/** @returns {{ account_id: ?number, debit: string, credit: string, narration: string }} */
export function emptyVoucherLine() {
    return { account_id: null, debit: '', credit: '', narration: '' };
}

/**
 * A typed amount as the exact 2dp string money.js parsed, '0.00' for a blank
 * box, or null when it isn't a valid amount (never Number()). A third decimal
 * is not silently rounded - it doesn't count, so the voucher stays visibly
 * unbalanced until fixed, the same answer JournalVoucher::validateLines()
 * gives on the server.
 *
 * @param {string|number|null|undefined} value
 * @returns {?string}
 */
export function voucherAmountOf(value) {
    const parsed = parseMoney(value === '' || value === null || value === undefined ? 0 : value);

    return parsed.ok ? parsed.value : null;
}

/**
 * The amount (and side) that would bring the voucher back into balance, for
 * pre-filling the next line: debits ahead of credits suggests a credit of the
 * difference and vice versa. Both sides blank when already balanced.
 *
 * @param {string} totalDebit
 * @param {string} totalCredit
 * @returns {{ debit: string, credit: string }}
 */
export function balancingEntry(totalDebit, totalCredit) {
    const difference = subtractMoney(totalDebit, totalCredit);

    if (isZeroMoney(difference)) {
        return { debit: '', credit: '' };
    }

    return compareMoney(totalDebit, totalCredit) > 0
        ? { debit: '', credit: difference }
        : { debit: subtractMoney(totalCredit, totalDebit), credit: '' };
}
