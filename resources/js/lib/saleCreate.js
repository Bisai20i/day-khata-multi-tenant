import { parseMoney, parseQuantity } from '@/lib/money';

/**
 * Pure helpers for Sales/Create.vue. Nothing here touches component state.
 */

export function emptyLine() {
    // item_unit_id null means "the item's own base unit" - never '', which
    // reka-ui's <SelectItem> refuses as an option value. Submitted as null.
    //
    // `bonus_quantity` is the free-of-charge quantity handed over with the
    // line (audit section 3 "Sales"): it moves stock but is never priced, so
    // it is submitted to the server and deliberately kept out of the preview.
    // `mrp` is the opposite - a browser-only entry aid that fills `rate` and
    // is never submitted (see applyLineMrp() and submit()).
    return { item_id: null, item_unit_id: null, quantity: '', bonus_quantity: '', mrp: '', rate: '', discount: '', discount_type: 'flat' };
}

/**
 * A quantity box as the server wants it: the typed string untouched, or '0'
 * when the box is empty. Never a number - the string goes straight into
 * Quantity::of() server-side, which refuses anything a float could have
 * distorted (C1).
 */
export function enteredQuantity(value) {
    return value === '' || value === null || value === undefined ? '0' : String(value);
}

/**
 * The sale form as `POST /sales` wants it. Shared with the estimate preview
 * (useSaleEstimate), so the bill previewed is built from the same payload as
 * the bill posted. Carries no total: the save adds its own `expected_total`,
 * and the estimate is computed entirely server-side.
 */
export function toSaleCreatePayload(data) {
    return {
        ...data,
        discount: data.discount === '' ? '0' : data.discount,
        cash_amount: data.payment_mode === 'partial' ? (data.cash_amount === '' ? '0' : data.cash_amount) : undefined,
        bank_amount: data.payment_mode === 'partial' ? (data.bank_amount === '' ? '0' : data.bank_amount) : undefined,
        tds_amount: data.tds_amount === '' ? '0' : data.tds_amount,
        commission_amount: data.agent_id ? (data.commission_amount === '' ? '0' : data.commission_amount) : undefined,
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            item_unit_id: line.item_unit_id || null,
            quantity: line.quantity,
            // Free units: sent as an explicit '0' when the box is empty, and
            // never as `undefined`. `mrp` is NOT sent - it only ever existed
            // to fill `rate` (see applyLineMrp()).
            bonus_quantity: enteredQuantity(line.bonus_quantity),
            rate: line.rate,
            discount: line.discount === '' ? '0' : line.discount,
            discount_type: line.discount_type,
        })),
    };
}

// Options for a line's unit dropdown: the item's own base unit first
// (value null - always present, even for an item with zero alt units), then
// every active ItemUnit row. Only rendered at all when the item has at
// least one alt unit (see the templates) - an item with none shows nothing
// here, unchanged from before this feature existed.
export function unitOptionsFor(item) {
    if (!item) return [];

    return [{ value: null, label: item.unit }, ...(item.units ?? []).map((u) => ({ value: u.id, label: u.name }))];
}

// -1/0/1 on two quantity strings, exact (scaled BigInt, never Number()) -
// only used to compare current_stock against min_stock for the badge, never
// to compute anything billed. A canonical decimal STRING can't be compared
// with `<`/`>` directly ("6.0000" sorts after "10.0000" lexicographically),
// so this scales both sides to an integer first - mirrors Pos.vue's own
// toScaledQuantity()/compareQuantity(), which money.js doesn't expose yet.
export function compareQuantity(a, b) {
    const left = parseQuantity(a === '' || a === null || a === undefined ? '0' : a);
    const right = parseQuantity(b === '' || b === null || b === undefined ? '0' : b);
    if (!left.ok || !right.ok) return null;

    const toScaled = (value) => {
        const negative = value.startsWith('-');
        const scaled = BigInt((negative ? value.slice(1) : value).replace('.', ''));

        return negative ? -scaled : scaled;
    };

    const leftScaled = toScaled(left.value);
    const rightScaled = toScaled(right.value);

    return leftScaled === rightScaled ? 0 : leftScaled < rightScaled ? -1 : 1;
}

/** 'out' | 'low' | null - drives the stock badge next to Qty and per-line in the bill. */
export function stockStatus(item) {
    if (!item?.is_stockable || item.current_stock == null) return null;
    if (compareQuantity(item.current_stock, '0') <= 0) return 'out';
    if (item.min_stock != null && compareQuantity(item.current_stock, item.min_stock) <= 0) return 'low';

    return null;
}

/**
 * Switching a discount between % and Rs on any object carrying `discount` and
 * `discount_type` (staging line, committed line or the bill header).
 *
 * Percentage to flat is exact: the calculator already knows the rupee amount
 * that percentage came to (`amount`). The other direction is not - recovering
 * a percentage from an amount needs a division that the money module
 * deliberately does not offer - so the field is cleared and the cashier types
 * the percentage they mean, instead of a silently wrong number being carried
 * across.
 */
export function toggleDiscountTypeOn(target, amount = null) {
    if (target.discount_type === 'percentage') {
        target.discount = amount && amount !== '0.00' ? amount : '';
        target.discount_type = 'flat';

        return;
    }

    target.discount = '';
    target.discount_type = 'percentage';
}

/** A user-typed amount as a canonical 2dp string, or null when it is not a valid amount. */
export function enteredAmount(value) {
    const parsed = parseMoney(value === '' || value === null || value === undefined ? '0' : value);

    return parsed.ok ? parsed.value : null;
}
