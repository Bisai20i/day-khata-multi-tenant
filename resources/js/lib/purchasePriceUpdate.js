import { formatRate } from './money.js';
import { toScaledQuantity } from './quantity.js';

/**
 * Pure helpers behind the "update the item's saved purchase price?" prompt on
 * Purchases/Create.vue (see usePurchasePriceConfirm.js). Nothing here touches
 * component state.
 *
 * The saved purchase price is `items.purchase_rate` for the base unit and
 * `item_units.purchase_rate` for each alternate unit. Every rate is a 4dp
 * decimal handled as a scaled BigInt (never Number()), and every rounding is
 * a single HalfUp step, the same as the server's
 * App\Support\Inventory\ItemPurchasePriceUpdater: 1.005 rounds to 1.01 on
 * both sides, where Math.round(1.005 * 100) / 100 gives 1.
 *
 * Rates are compared at the precision they are stored at - exactly, as
 * canonical 4dp strings ("10.1240") - so the rate the clerk said Yes to is
 * the rate the server writes, never a neighbour that merely rounds the same.
 */

/** Largest value the DECIMAL(15,4) purchase_rate columns hold, at scale 4. */
const MAX_RATE_SCALED = 999999999999999n;

/** 1.0000 - the base unit's conversion factor, at scale 4. */
const BASE_FACTOR = 10000n;

/** A rate/factor as a scale-4 BigInt; null for blank or invalid input. */
function scaled(value) {
    if (value === null || value === undefined || value === '') return null;

    return toScaledQuantity(value);
}

function divideRoundHalfUp(numerator, denominator) {
    const negative = numerator < 0n !== denominator < 0n;
    const num = numerator < 0n ? -numerator : numerator;
    const den = denominator < 0n ? -denominator : denominator;
    const quotient = num / den;
    const rounded = (num % den) * 2n >= den ? quotient + 1n : quotient;

    return negative ? -rounded : rounded;
}

/** A scaled BigInt as a decimal string with `places` decimals ("12.50", "-0.0050"). */
function scaledString(value, places) {
    const negative = value < 0n;
    const digits = (negative ? -value : value).toString().padStart(places + 1, '0');

    return `${negative ? '-' : ''}${digits.slice(0, -places)}.${digits.slice(-places)}`;
}

/** Normalises a line's unit id: '' / undefined / null all mean the base unit. */
export function unitKey(unitId) {
    return unitId === '' || unitId === undefined || unitId === null ? null : String(unitId);
}

/**
 * The canonical 4dp form of a rate ("10.1250", "-0.0050"), or null for blank
 * or invalid input. This is the precision every comparison here is made at.
 *
 * @returns {string|null}
 */
export function canonicalRate(value) {
    const rate = scaled(value);

    return rate === null ? null : scaledString(rate, 4);
}

/** Whether two rates are the same stored value; false when either is blank or invalid. */
function sameRate(a, b) {
    const left = canonicalRate(a);

    return left !== null && left === canonicalRate(b);
}

/**
 * The item's price rows: the base unit first, then each active alternate
 * unit, with its conversion factor (scale-4 BigInt, null if unusable) and
 * saved purchase rate (string or null).
 *
 * @returns {Array<{unitId: string|null, name: string, factor: bigint|null, savedRate: string|null}>}
 */
export function priceRows(item) {
    if (!item) return [];

    return [
        { unitId: null, name: item.unit, factor: BASE_FACTOR, savedRate: item.purchase_rate ?? null },
        ...(item.units ?? [])
            .filter((unit) => unit.is_active !== false)
            .map((unit) => ({
                unitId: String(unit.id),
                name: unit.name,
                factor: scaled(unit.conversion_factor),
                savedRate: unit.purchase_rate ?? null,
            })),
    ];
}

/**
 * `rate / chosenFactor x targetFactor`, multiplied first and rounded once
 * HalfUp to 2 decimals - exactly ItemPurchasePriceUpdater's rescale. Null
 * when either factor is not positive, or the result is not above zero or
 * would not fit the column.
 *
 * @returns {string|null}
 */
export function rescaledRate(rate, chosenFactor, targetFactor) {
    const value = scaled(rate);
    if (value === null || chosenFactor === null || targetFactor === null || chosenFactor <= 0n || targetFactor <= 0n) {
        return null;
    }

    const result = divideRoundHalfUp(value * targetFactor, chosenFactor * 100n);

    return result <= 0n || result * 100n > MAX_RATE_SCALED ? null : scaledString(result, 2);
}

/**
 * What every other unit becomes when `chosen` takes `rate`, mirroring
 * ItemPurchasePriceUpdater::plannedRates(). Units without a usable factor
 * (null, zero or negative) are left out; a chosen unit without one changes
 * only itself, on both sides. All or
 * nothing: when any rescaled value would round to zero or overflow the
 * column, no other unit changes and `skipped` is true.
 *
 * @returns {{others: Array<{row: object, rate: string}>, skipped: boolean}}
 */
export function rescalePlan(rows, chosen, rate) {
    if (!chosen || chosen.factor === null || chosen.factor <= 0n) return { others: [], skipped: false };

    const others = [];

    for (const row of rows) {
        if (row === chosen || row.factor === null || row.factor <= 0n) continue;

        const next = rescaledRate(rate, chosen.factor, row.factor);
        if (next === null) return { others: [], skipped: true };

        others.push({ row, rate: next });
    }

    return { others, skipped: false };
}

/**
 * What a price answer is about: the line's item, unit and exact 4dp rate as
 * one string ("1|5|132.0000"). Null while the line has no item or no valid
 * rate.
 *
 * @returns {string|null}
 */
export function lineKey(line) {
    const rate = canonicalRate(line?.rate);
    if (rate === null || line.item_id === null || line.item_id === undefined || line.item_id === '') return null;

    return `${line.item_id}|${unitKey(line.item_unit_id) ?? ''}|${rate}`;
}

/**
 * The line's own price-prompt memory, kept on the line itself as
 * `line.price_check` so it goes wherever the line goes - onto the bill, into
 * the quick-add draft and back - and is gone when the line is removed. Each
 * entry is a lineKey():
 * - `prefilled`: what the form itself put in the line (a saved price);
 * - `confirmed`: the clerk's Yes, in force only while the line still matches it;
 * - `declined`: every No given on this line.
 *
 * @returns {{prefilled: string|null, confirmed: string|null, declined: string[]}}
 */
export function priceCheckOf(line) {
    return { prefilled: null, confirmed: null, declined: [], ...line?.price_check };
}

/** Whether the line's own Yes is in force: given for exactly the item, unit and rate it has now. */
function confirmedOnLine(line) {
    const key = lineKey(line);

    return key !== null && priceCheckOf(line).confirmed === key;
}

/** A line's Yes in the `price_updates` shape: the line's own item, unit and canonical rate. */
function answerOf(line) {
    return {
        item_id: line.item_id,
        item_unit_id: unitKey(line.item_unit_id) === null ? null : line.item_unit_id,
        rate: canonicalRate(line.rate),
    };
}

/**
 * The Yes in force for an item, from the last line of that item whose own
 * Yes still matches it (see pricePayload()), leaving out `exceptLine` - the
 * line being asked about. Null when there is none.
 *
 * @returns {{item_id: *, item_unit_id: *, rate: string}|null}
 */
export function pendingFor(lines, itemId, exceptLine = null) {
    const list = lines ?? [];

    for (let index = list.length - 1; index >= 0; index -= 1) {
        const line = list[index];
        if (line !== exceptLine && String(line.item_id) === String(itemId) && confirmedOnLine(line)) return answerOf(line);
    }

    return null;
}

/**
 * The price a unit is currently working against: a Yes in force on this bill
 * for the item (rescaled to this unit) wins over the saved price.
 *
 * @returns {string|null}
 */
export function baselineRate(rows, row, pending) {
    if (!pending) return row.savedRate;

    const pendingUnit = unitKey(pending.item_unit_id);
    if (pendingUnit === row.unitId) return pending.rate;

    const chosen = rows.find((candidate) => candidate.unitId === pendingUnit);
    const rescaled = rescalePlan(rows, chosen, pending.rate).others.find((other) => other.row === row);

    // A unit the pending Yes does not rescale keeps its saved price.
    return rescaled ? rescaled.rate : row.savedRate;
}

function displayRate(value) {
    return value === null || value === undefined ? 'not set' : formatRate(value);
}

/**
 * What to ask about a line, or null when no prompt is due. Asks only for a
 * rate above zero that differs, exactly at 4 decimals, from the unit's
 * baseline, and only when this line has not already settled that exact
 * item, unit and rate: prefilled by the form, or answered Yes or No.
 *
 * @param {{item: object, line: object, pending?: object|null}} input
 *   `pending` is the Yes in force on the bill's other lines (pendingFor()).
 * @returns {null|{key: string, unitId: string|null, unitName: string, newRate: string, oldLabel: string, newLabel: string, others: Array<{name: string, oldLabel: string, newLabel: string}>, onlyThisUnit: boolean, rescaleSkipped: boolean}}
 */
export function priceChangePrompt({ item, line, pending = null }) {
    const key = lineKey(line);
    const value = scaled(line.rate);
    if (key === null || value <= 0n || value > MAX_RATE_SCALED) return null;

    const check = priceCheckOf(line);
    if (check.prefilled === key || check.confirmed === key || check.declined.includes(key)) return null;

    const newRate = canonicalRate(line.rate);
    const rows = priceRows(item);
    const row = rows.find((candidate) => candidate.unitId === unitKey(line.item_unit_id));
    if (!row) return null;

    const oldRate = baselineRate(rows, row, pending);
    if (sameRate(oldRate, newRate)) return null;

    const onlyThisUnit = row.factor === null || row.factor <= 0n;
    const plan = rescalePlan(rows, row, newRate);
    const others = plan.others.map(({ row: other, rate: next }) => ({
        name: other.name,
        oldLabel: displayRate(baselineRate(rows, other, pending)),
        newLabel: formatRate(next),
    }));

    return {
        key,
        unitId: row.unitId,
        unitName: row.name,
        newRate,
        oldLabel: displayRate(oldRate),
        newLabel: formatRate(newRate),
        others,
        onlyThisUnit,
        rescaleSkipped: plan.skipped,
    };
}

/**
 * The `price_updates` sent with the bill, built from the lines' own answers:
 * one entry per item, from the last line of that item whose Yes still
 * matches it. Every unit is rescaled from one chosen price, so an item can
 * take only one; the form keeps a single Yes per item anyway (a new Yes
 * replaces the other lines'), and "last line wins" is also the server's rule
 * for repeated answers (ItemPurchasePriceUpdater::apply()). The rate goes out
 * as the line's canonical 4dp rate, so the server's exact match against
 * purchase_lines.rate holds.
 *
 * @returns {Array<{item_id: number, item_unit_id: number|string|null, rate: string}>}
 */
export function pricePayload(lines) {
    const byItem = new Map();

    for (const line of lines ?? []) {
        if (confirmedOnLine(line)) byItem.set(String(line.item_id), answerOf(line));
    }

    return [...byItem.values()];
}
