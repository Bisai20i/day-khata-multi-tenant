import { formatQuantity } from '@/lib/money.js';

/**
 * A branch column quantity of a stock report (plan 12, section 5.9, BR15):
 * the project's quantity formatting (exact, no floats), a dash for zero so a
 * wide by-branch table stays readable.
 *
 * @param {string|null|undefined} value decimal string; missing counts as zero
 * @returns {string}
 */
export function formatBranchQuantity(value) {
    const text = formatQuantity(value ?? '0.0000');

    return text === '0' || text === '-0' ? '-' : text;
}

/**
 * The query to merge into a stock report's request: the branch filter's own
 * params, but never `by_branch` while a single store is chosen (one store is
 * one branch already; the server turns the columns off then).
 *
 * @param {{ toQuery: () => object }} branchFilter from useBranchReportFilter()
 * @param {number|null} storeId the store currently picked in the dropdown
 * @returns {object}
 */
export function stockBranchQuery(branchFilter, storeId) {
    const query = branchFilter.toQuery();

    if (storeId !== null && storeId !== undefined) {
        delete query.by_branch;
    }

    return query;
}
