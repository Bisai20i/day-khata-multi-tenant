/**
 * The "Ledger" row action that opens one item's stock ledger
 * (`items/{item}/ledger`). Shared by the Items list and every stock report
 * with a per-item row, so the link reads and looks the same everywhere, the
 * way the Accounts list's own "Ledger" action does.
 */

import { h } from 'vue';
import { Link } from '@inertiajs/vue3';

export function itemLedgerUrl(itemId) {
    return `/items/${itemId}/ledger`;
}

const LINK_CLASS = 'text-xs font-semibold text-primary hover:underline';

/** `linkClass` lets a row whose other actions are bordered buttons match them. */
export function itemLedgerLink(itemId, linkClass = LINK_CLASS) {
    return h(Link, { href: itemLedgerUrl(itemId), class: linkClass }, { default: () => 'Ledger' });
}

/** A trailing DataTable column holding the link, for reports that have no actions column yet. */
export function itemLedgerColumn(itemIdOf) {
    return {
        id: 'ledger',
        header: '',
        numeric: false,
        cell: ({ row }) => {
            const itemId = itemIdOf(row.original);
            return itemId ? itemLedgerLink(itemId) : null;
        },
    };
}
