import { parseQuantity } from './money.js';

/**
 * Exact quantity arithmetic (4 decimals).
 *
 * resources/js/lib/money.js parses and formats quantities but exposes no
 * add/compare helpers, and the POS screen steps, sums and compares quantities
 * constantly. Everything here works on the canonical 4dp string as a scaled
 * BigInt, exactly the way the shared module works internally - never through
 * Number() or parseFloat, so +1 on 0.125 can no longer turn into 1.13 (audit P1
 * "POS applies round2 to quantities").
 */
const QUANTITY_SCALE = 4;

export function toScaledQuantity(value) {
    const parsed = parseQuantity(value === '' || value === null || value === undefined ? '0' : value);
    if (!parsed.ok) return null;

    const negative = parsed.value.startsWith('-');
    const scaled = BigInt((negative ? parsed.value.slice(1) : parsed.value).replace('.', ''));

    return negative ? -scaled : scaled;
}

function fromScaledQuantity(scaled) {
    const negative = scaled < 0n;
    const digits = (negative ? -scaled : scaled).toString().padStart(QUANTITY_SCALE + 1, '0');
    const text = `${digits.slice(0, -QUANTITY_SCALE)}.${digits.slice(-QUANTITY_SCALE)}`;

    return negative ? `-${text}` : text;
}

/** Exact `a + b` on two quantities, or null when either is not a valid quantity. */
export function addQuantity(a, b) {
    const left = toScaledQuantity(a);
    const right = toScaledQuantity(b);

    return left === null || right === null ? null : fromScaledQuantity(left + right);
}

/** Exact `a - b` on two quantities, or null when either is not a valid quantity. */
export function subtractQuantity(a, b) {
    const left = toScaledQuantity(a);
    const right = toScaledQuantity(b);

    return left === null || right === null ? null : fromScaledQuantity(left - right);
}

/** -1, 0 or 1. Exact, no tolerance. Returns null when either side is unparsable. */
export function compareQuantity(a, b) {
    const left = toScaledQuantity(a);
    const right = toScaledQuantity(b);
    if (left === null || right === null) return null;

    return left === right ? 0 : left < right ? -1 : 1;
}

/** Adds a whole number of units to a quantity, keeping its fractional part intact. */
export function stepQuantity(value, delta) {
    return addQuantity(value, String(delta));
}
