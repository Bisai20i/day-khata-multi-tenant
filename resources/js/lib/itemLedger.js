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

export function itemLedgerLink(itemId) {
    return h(
        Link,
        { href: itemLedgerUrl(itemId), class: 'text-xs font-semibold text-primary hover:underline' },
        { default: () => 'Ledger' },
    );
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
