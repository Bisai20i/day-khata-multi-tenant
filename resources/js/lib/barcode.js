/**
 * Barcodes for items that have no manufacturer code of their own, made in
 * the browser so the form needs no server round trip.
 *
 * The result is a valid 13-digit EAN-13 number:
 *
 *   2  CCC  DDDD  TTTT  K
 *
 * - `2` is the GS1 range reserved for in-store use, so a generated code can
 *   never clash with a real product barcode printed by a supplier.
 * - `CCC` is the item's category, so every item of one category shares the
 *   same leading digits.
 * - `DDDD` is the day it was made, counted from 1 January 2025.
 * - `TTTT` is the time of day it was made, in 10-second steps (0000-8639).
 * - `K` is the EAN-13 check digit, which lets a scanner reject a misread.
 *
 * Two codes only collide when they are made for the same category within the
 * same 10 seconds; the server's unique rule still catches that on save.
 */

const EPOCH_DAY = Date.UTC(2025, 0, 1) / 86_400_000;
const SLOTS_PER_DAY = 8640;

// The last code handed out in this tab, so a quick second click (or a fast
// "Add another" run) moves on to the next slot instead of repeating itself.
let lastBody = '';

function pad(value, length) {
    return String(value).padStart(length, '0');
}

/** EAN-13 check digit for the first twelve digits. */
export function ean13CheckDigit(body) {
    const sum = [...body].reduce((total, digit, index) => total + Number(digit) * (index % 2 === 0 ? 1 : 3), 0);

    return (10 - (sum % 10)) % 10;
}

function bodyFor(categoryId, day, slot) {
    return `2${pad(Number(categoryId) % 1000, 3)}${pad(day % 10000, 4)}${pad(slot, 4)}`;
}

export function generateItemBarcode(categoryId, now = new Date()) {
    // Local calendar date and clock, read through Date.UTC so a daylight
    // saving shift cannot move the day count.
    let day = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()) / 86_400_000 - EPOCH_DAY;
    let slot = Math.floor((now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds()) / 10);
    let body = bodyFor(categoryId, day, slot);

    while (body <= lastBody && body.slice(0, 4) === lastBody.slice(0, 4)) {
        slot += 1;
        if (slot === SLOTS_PER_DAY) {
            slot = 0;
            day += 1;
        }
        body = bodyFor(categoryId, day, slot);
    }

    lastBody = body;

    return `${body}${ean13CheckDigit(body)}`;
}
