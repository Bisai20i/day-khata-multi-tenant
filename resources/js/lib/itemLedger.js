/**
 * The "Ledger" row action that opens one item's stock ledger
 * (`items/{item}/ledger`). Shared by the Items list and every stock report
 * with a per-item row, so the link reads and looks the same everywhere, the
 * way the Accounts list's own "Ledger" action does.
 *
 * A report passes its applied context (`from`/`to`, `store_id`, branch
 * params) as `query`, so the ledger opens on the same slice of stock the row
 * was computed from and its balance matches the row.
 */

import { h } from 'vue';
import { Link } from '@inertiajs/vue3';

function isSkipped(value) {
    return value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0);
}

/** Query string (with leading `?`) for a plain object; empty/null values are skipped, arrays become `key[]`. */
function queryString(query) {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query ?? {})) {
        if (isSkipped(value)) {
            continue;
        }

        if (Array.isArray(value)) {
            value.filter((item) => !isSkipped(item)).forEach((item) => params.append(`${key}[]`, String(item)));
        } else {
            params.set(key, String(value));
        }
    }

    const text = params.toString();

    return text === '' ? '' : `?${text}`;
}

/**
 * @param {number|string} itemId
 * @param {object} [query] report context appended as a query string
 */
export function itemLedgerUrl(itemId, query = {}) {
    return `/items/${itemId}/ledger${queryString(query)}`;
}

const LINK_CLASS = 'text-xs font-semibold text-primary hover:underline';

/** `linkClass` lets a row whose other actions are bordered buttons match them. */
export function itemLedgerLink(itemId, linkClass = LINK_CLASS, query = {}) {
    return h(Link, { href: itemLedgerUrl(itemId, query), class: linkClass }, { default: () => 'Ledger' });
}

/**
 * A trailing DataTable column holding the link, for reports that have no actions column yet.
 *
 * @param {(row: object) => number|string|null} itemIdOf
 * @param {object|((row: object) => object)} [query] the report context, or a function of the row
 */
export function itemLedgerColumn(itemIdOf, query = {}) {
    return {
        id: 'ledger',
        header: '',
        numeric: false,
        cell: ({ row }) => {
            const itemId = itemIdOf(row.original);
            const context = typeof query === 'function' ? query(row.original) : query;

            return itemId ? itemLedgerLink(itemId, LINK_CLASS, context) : null;
        },
    };
}
