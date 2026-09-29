/**
 * Pure helpers for the unlinked purchase return form
 * (components/purchases/PurchaseReturnUnlinkedForm.vue). Nothing here touches
 * component state.
 */

/** A blank line: no item, base unit, no rate (valued at average cost). */
export function blankUnlinkedLine() {
    return { item_id: null, item_unit_id: null, quantity: '', rate: '' };
}

/** A line the server should price: it names an item and a non-zero quantity. */
export function isFilledUnlinkedLine(line) {
    return !!line.item_id && line.quantity !== '' && line.quantity !== null && line.quantity !== '0';
}

/**
 * How the refund can arrive. "Cash + bank" needs the exact total to split
 * against (the server refuses any split that does not land on it), and
 * "Credit to supplier" puts the refund on the supplier's account, so it needs
 * a supplier (StoreUnlinkedPurchaseReturnRequest: required_if credit).
 *
 * @param {{ canSplit: boolean, hasSupplier: boolean }} options
 * @returns {Array<{ value: string, label: string }>}
 */
export function unlinkedRefundModeOptions({ canSplit, hasSupplier }) {
    return [
        { value: 'cash', label: 'Cash' },
        { value: 'bank', label: 'Bank' },
        ...(canSplit ? [{ value: 'partial', label: 'Cash + bank' }] : []),
        ...(hasSupplier ? [{ value: 'credit', label: 'Credit to supplier' }] : []),
    ];
}

/** Only a refund that actually lands in a bank account asks for one. */
export function unlinkedModeNeedsBank(mode) {
    return mode === 'bank' || mode === 'partial';
}

/**
 * Query string for GET /purchase-returns/unlinked/quote. Quantities and rates
 * go out exactly as typed, never through Number().
 *
 * @param {{ date: string, vat_rate: string }} header
 * @param {Array<{ item_id: number, item_unit_id: ?number, quantity: string, rate: string }>} lines filled lines only
 */
export function unlinkedQuoteQuery(header, lines) {
    const params = new URLSearchParams();
    params.set('date', header.date ?? '');

    if (header.vat_rate !== '' && header.vat_rate !== null && header.vat_rate !== undefined) {
        params.set('vat_rate', header.vat_rate);
    }

    lines.forEach((line, index) => {
        params.set(`lines[${index}][item_id]`, String(line.item_id));
        if (line.item_unit_id) {
            params.set(`lines[${index}][item_unit_id]`, String(line.item_unit_id));
        }
        params.set(`lines[${index}][quantity]`, line.quantity);
        if (line.rate !== '' && line.rate !== null) {
            params.set(`lines[${index}][rate]`, line.rate);
        }
    });

    return params.toString();
}
