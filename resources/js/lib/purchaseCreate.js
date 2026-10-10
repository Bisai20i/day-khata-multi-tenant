/**
 * Pure helpers for Purchases/Create.vue. Nothing here touches component state.
 */

/**
 * A blank purchase line. item_unit_id null means "the item's own base unit"
 * (see lib/saleCreate.js's emptyLine()). bonus_quantity is free units
 * received alongside the paid ones - stocked but never billed (item 3), so
 * it is not part of the preview at all. note is a free-text per-line remark
 * stored as-is on purchase_lines.note.
 *
 * @returns {{ item_id: ?number, item_unit_id: ?number, quantity: string, bonus_quantity: string, rate: string, discount: string, discount_type: string, note: string }}
 */
export function emptyPurchaseLine() {
    return {
        item_id: null,
        item_unit_id: null,
        quantity: '',
        bonus_quantity: '',
        rate: '',
        discount: '',
        discount_type: 'flat',
        note: '',
    };
}

/**
 * A draft restored from sessionStorage after the "+ New supplier"/"+ New
 * item" bounce. Items only enter the bill through the "Add item" row now,
 * so a blank placeholder line (the old form always opened with one) is
 * dropped instead of showing up as an empty committed row.
 *
 * @param {object} draft
 * @returns {object}
 */
export function restorePurchaseDraft(draft) {
    return {
        ...draft,
        lines: (draft.lines ?? []).filter((line) => line.item_id !== null && line.item_id !== undefined),
    };
}
