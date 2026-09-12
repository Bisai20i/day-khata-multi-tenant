/**
 * Exact money and quantity arithmetic for the browser.
 *
 * Client-side mirror of the server calculator (`App\Support\Money\Money`,
 * `App\Support\Money\Quantity` and `App\Support\Billing\DocumentCalculator`). Contract C8.
 *
 * Why this file exists: JavaScript numbers are binary floats, so `1.5 * 33.33` is
 * 49.995000000000005 and `Math.round(x * 100) / 100` rounds ties toward +Infinity while PHP
 * (and MySQL, and brick/math) round ties away from zero. A bill preview built on floats
 * therefore disagrees with the stored and printed bill by a paisa (audit P0-8). Every value
 * here is a scaled `BigInt` instead:
 *
 *   - money    = paisa,      scale 2  (Rs 56.50 -> 5650n)
 *   - quantity = 1/10000ths, scale 4  (1.5 -> 15000n) - also used for rates and factors
 *   - percent  = 1/100ths,   scale 2  (13% -> 1300n)
 *
 * `Number()`, `parseFloat`, `toFixed` and `Math.round` are never used on a money, quantity
 * or rate anywhere in this module: every parse is a string scan and every rounding is an
 * integer division. Rounding is always HalfUp, meaning ties away from zero, which is what
 * `RoundingMode::HalfUp` does in brick/math and what MySQL does on a DECIMAL insert.
 *
 * Style: `parseMoney`, `parseQuantity` and `calculateDocument` never throw - they return a
 * result object so a Vue page can show the reason inline. The small arithmetic helpers
 * (`addMoney`, `allocateMoney`, `formatMoney`, ...) throw `MoneyError` on bad input, because
 * by then the value has already been validated once.
 */

const MONEY_SCALE = 2;
const QUANTITY_SCALE = 4;
const PERCENT_SCALE = 2;

/** 100 percent, at PERCENT_SCALE. */
const ONE_HUNDRED_PERCENT = 10000n;

/** Divisor that turns a (scale 2 x scale 2) percentage product back into paisa. */
const PERCENT_DIVISOR = 10000n;

/** Only a plain decimal: optional sign, digits, optional fraction. No commas, no exponent. */
const DECIMAL_PATTERN = /^[+-]?\d+(?:\.\d+)?$/;

const TEN_POWERS = [1n, 10n, 100n, 1000n, 10000n, 100000n, 1000000n, 10000000n, 100000000n];

/**
 * Raised by the arithmetic and formatting helpers. `reason` is one of the shared codes the
 * server uses too (see contract C3), so a caller can branch on it without matching messages.
 */
export class MoneyError extends Error {
    /**
     * @param {string} reason
     * @param {string} message
     */
    constructor(reason, message) {
        super(message);
        this.name = 'MoneyError';
        this.reason = reason;
    }
}

/** @returns {bigint} 10 to the power of `exponent`. */
function tenPow(exponent) {
    if (exponent < TEN_POWERS.length) {
        return TEN_POWERS[exponent];
    }

    let result = 1n;
    for (let i = 0; i < exponent; i++) {
        result *= 10n;
    }

    return result;
}

/**
 * Parses any accepted input into a scaled `BigInt` without ever going through `Number()`.
 *
 * JS numbers are accepted only through `String(value)`, which is the shortest round-trip
 * form (the same thing PHP's `var_export()` gives), and only when that form has no
 * exponent: `1e21` and `1e-7` are out of range for every column we store anyway.
 *
 * @param {string|number|bigint} input
 * @param {number} scale
 * @returns {{ok: true, value: bigint}|{ok: false, reason: string}}
 */
function parseScaled(input, scale) {
    let text;

    if (typeof input === 'string') {
        text = input.trim();
    } else if (typeof input === 'number') {
        if (!Number.isFinite(input)) {
            return { ok: false, reason: 'invalid_number' };
        }
        text = String(input);
        if (text.indexOf('e') !== -1 || text.indexOf('E') !== -1) {
            return { ok: false, reason: 'invalid_number' };
        }
    } else if (typeof input === 'bigint') {
        text = input.toString();
    } else {
        return { ok: false, reason: 'invalid_number' };
    }

    if (!DECIMAL_PATTERN.test(text)) {
        return { ok: false, reason: 'invalid_number' };
    }

    const signed = text[0] === '+' || text[0] === '-';
    const negative = text[0] === '-';
    const unsigned = signed ? text.slice(1) : text;
    const dot = unsigned.indexOf('.');
    const integerDigits = dot === -1 ? unsigned : unsigned.slice(0, dot);
    const rawFraction = dot === -1 ? '' : unsigned.slice(dot + 1);

    // Trailing zeros do not count: "1.500" is a legal 2dp money value, "1.505" is not.
    const significantFraction = rawFraction.replace(/0+$/, '');
    if (significantFraction.length > scale) {
        return { ok: false, reason: 'too_many_decimals' };
    }

    const fraction = rawFraction.slice(0, scale).padEnd(scale, '0');
    const magnitude = BigInt(integerDigits + fraction);

    return { ok: true, value: negative ? -magnitude : magnitude };
}

/**
 * Renders a scaled `BigInt` with exactly `scale` decimals. Zero always prints `0.00` /
 * `0.0000`, never `-0.00`.
 *
 * @returns {string}
 */
function toScaledString(value, scale) {
    const negative = value < 0n;
    const magnitude = negative ? -value : value;
    const digits = magnitude.toString().padStart(scale + 1, '0');
    const integerPart = digits.slice(0, digits.length - scale);
    const fraction = digits.slice(digits.length - scale);

    return (negative ? '-' : '') + integerPart + (scale > 0 ? '.' + fraction : '');
}

/**
 * Integer division rounded HalfUp (ties away from zero), the single rounding step every
 * product and percentage in this module goes through.
 *
 * @returns {bigint}
 */
function divideRoundHalfUp(numerator, denominator) {
    if (denominator === 0n) {
        throw new MoneyError('invalid_number', 'Cannot divide by zero.');
    }

    let num = numerator;
    let den = denominator;
    if (den < 0n) {
        num = -num;
        den = -den;
    }

    const negative = num < 0n;
    const magnitude = negative ? -num : num;
    const quotient = magnitude / den;
    const remainder = magnitude % den;
    const rounded = remainder * 2n >= den ? quotient + 1n : quotient;

    return negative ? -rounded : rounded;
}

/** Moves a scaled value between scales, rounding HalfUp when it loses decimals. */
function rescale(value, fromScale, toScale) {
    if (fromScale === toScale) {
        return value;
    }
    if (fromScale < toScale) {
        return value * tenPow(toScale - fromScale);
    }

    return divideRoundHalfUp(value, tenPow(fromScale - toScale));
}

/**
 * Exact product of two scaled values, then ONE HalfUp rounding to `resultScale`.
 * `BigInt` keeps the full intermediate precision, so a 4dp x 4dp product at the
 * `DECIMAL(15,4)` extremes stays exact far beyond 2^53.
 */
function multiplyScaled(left, leftScale, right, rightScale, resultScale) {
    return rescale(left * right, leftScale + rightScale, resultScale);
}

/** Human label for a value we could not parse, without stringifying an object badly. */
function describe(input) {
    if (input === null) {
        return 'null';
    }
    if (input === undefined) {
        return 'empty';
    }
    if (typeof input === 'object') {
        return 'not a number';
    }

    return String(input);
}

/**
 * Parses a field or throws a `MoneyError` carrying a message a user can act on.
 *
 * @returns {bigint}
 */
function parseField(input, scale, label) {
    const parsed = parseScaled(input, scale);
    if (parsed.ok) {
        return parsed.value;
    }

    if (parsed.reason === 'too_many_decimals') {
        throw new MoneyError(
            'too_many_decimals',
            'The ' + label + ' "' + describe(input) + '" has more than ' + scale + ' decimal places.',
        );
    }

    throw new MoneyError('invalid_number', 'The ' + label + ' "' + describe(input) + '" is not a valid number.');
}

/** Parses an already-validated value for the arithmetic helpers. */
function requireScaled(input, scale, label) {
    return parseField(input, scale, label);
}

/**
 * Largest-remainder split of `totalScaled` across non-negative `weightsScaled`. Floors every
 * share, then hands the leftover smallest units one by one to the largest fractional
 * remainders, ties to the lowest index, so the parts always sum back to the total exactly.
 *
 * @returns {bigint[]}
 */
function allocateScaledAmount(totalScaled, weightsScaled) {
    if (weightsScaled.length === 0) {
        throw new MoneyError('invalid_number', 'An allocation needs at least one weight.');
    }
    if (weightsScaled.some((weight) => weight < 0n)) {
        throw new MoneyError('invalid_number', 'Allocation weights cannot be negative.');
    }

    const weightTotal = weightsScaled.reduce((carry, weight) => carry + weight, 0n);
    if (weightTotal === 0n) {
        throw new MoneyError('invalid_number', 'Allocation weights cannot all be zero.');
    }

    const negative = totalScaled < 0n;
    const magnitude = negative ? -totalScaled : totalScaled;

    const shares = [];
    const remainders = [];
    let assigned = 0n;

    for (const weight of weightsScaled) {
        const numerator = magnitude * weight;
        const share = numerator / weightTotal;
        shares.push(share);
        remainders.push(numerator % weightTotal);
        assigned += share;
    }

    let leftover = magnitude - assigned;
    const order = shares
        .map((_, index) => index)
        .sort((a, b) => {
            if (remainders[a] === remainders[b]) {
                return a - b;
            }

            return remainders[a] > remainders[b] ? -1 : 1;
        });

    for (let i = 0; leftover > 0n; i++) {
        shares[order[i]] += 1n;
        leftover -= 1n;
    }

    return negative ? shares.map((share) => -share) : shares;
}

// ---------------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------------

/**
 * Validates a user-entered amount.
 *
 * @param {string|number} input
 * @returns {{ok: true, value: string}|{ok: false, reason: string}} `value` is the canonical
 *   2dp string, e.g. `"56.50"`.
 */
export function parseMoney(input) {
    const parsed = parseScaled(input, MONEY_SCALE);

    return parsed.ok ? { ok: true, value: toScaledString(parsed.value, MONEY_SCALE) } : parsed;
}

/**
 * Validates a user-entered quantity, rate or conversion factor.
 *
 * @param {string|number} input
 * @returns {{ok: true, value: string}|{ok: false, reason: string}} `value` is the canonical
 *   4dp string, e.g. `"1.5000"`.
 */
export function parseQuantity(input) {
    const parsed = parseScaled(input, QUANTITY_SCALE);

    return parsed.ok ? { ok: true, value: toScaledString(parsed.value, QUANTITY_SCALE) } : parsed;
}

// ---------------------------------------------------------------------------
// Arithmetic helpers: string in, string out
// ---------------------------------------------------------------------------

/** Exact `a + b`. @returns {string} */
export function addMoney(a, b) {
    return toScaledString(
        requireScaled(a, MONEY_SCALE, 'amount') + requireScaled(b, MONEY_SCALE, 'amount'),
        MONEY_SCALE,
    );
}

/** Exact `a - b`. @returns {string} */
export function subtractMoney(a, b) {
    return toScaledString(
        requireScaled(a, MONEY_SCALE, 'amount') - requireScaled(b, MONEY_SCALE, 'amount'),
        MONEY_SCALE,
    );
}

/** Exact sum of an iterable of amounts. @returns {string} */
export function sumMoney(values) {
    let total = 0n;

    for (const value of values ?? []) {
        total += requireScaled(value, MONEY_SCALE, 'amount');
    }

    return toScaledString(total, MONEY_SCALE);
}

/** @returns {number} -1, 0 or 1. Exact, no tolerance. */
export function compareMoney(a, b) {
    const left = requireScaled(a, MONEY_SCALE, 'amount');
    const right = requireScaled(b, MONEY_SCALE, 'amount');

    if (left === right) {
        return 0;
    }

    return left < right ? -1 : 1;
}

/** @returns {boolean} exact equality, no tolerance. */
export function moneyEquals(a, b) {
    return compareMoney(a, b) === 0;
}

/** @returns {boolean} */
export function isZeroMoney(value) {
    return requireScaled(value, MONEY_SCALE, 'amount') === 0n;
}

/**
 * `round(money x factor)` with a single HalfUp rounding. `factor` is a quantity-scale value
 * (a quantity, a rate or a unit conversion factor).
 *
 * @returns {string}
 */
export function multiplyMoney(money, factor) {
    return toScaledString(
        multiplyScaled(
            requireScaled(money, MONEY_SCALE, 'amount'),
            MONEY_SCALE,
            requireScaled(factor, QUANTITY_SCALE, 'factor'),
            QUANTITY_SCALE,
            MONEY_SCALE,
        ),
        MONEY_SCALE,
    );
}

/**
 * `round(money x pct / 100)` with a single HalfUp rounding. `pct` carries at most 2 decimals.
 *
 * @returns {string}
 */
export function percentOf(money, pct) {
    const amount = requireScaled(money, MONEY_SCALE, 'amount');
    const percent = requireScaled(pct, PERCENT_SCALE, 'percentage');

    return toScaledString(divideRoundHalfUp(amount * percent, PERCENT_DIVISOR), MONEY_SCALE);
}

/**
 * Splits `amount` into parts proportional to `weights`, largest remainder at 0.01, so the
 * parts sum back to `amount` exactly. A negative amount allocates its absolute value and
 * negates every part. All-zero or negative weights throw.
 *
 * @param {string|number} amount
 * @param {Array<string|number>} weights money or quantity values
 * @returns {string[]}
 */
export function allocateMoney(amount, weights) {
    if (!Array.isArray(weights)) {
        throw new MoneyError('invalid_number', 'Allocation weights must be an array.');
    }

    const total = requireScaled(amount, MONEY_SCALE, 'amount');
    // Weights are read at quantity scale so money weights and quantity weights mix safely.
    const scaledWeights = weights.map((weight) => requireScaled(weight, QUANTITY_SCALE, 'allocation weight'));

    return allocateScaledAmount(total, scaledWeights).map((share) => toScaledString(share, MONEY_SCALE));
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

/** Indian digit grouping: last three digits, then pairs. */
function groupIndian(digits) {
    if (digits.length <= 3) {
        return digits;
    }

    const lastThree = digits.slice(-3);
    let rest = digits.slice(0, -3);
    const groups = [];

    while (rest.length > 2) {
        groups.unshift(rest.slice(-2));
        rest = rest.slice(0, -2);
    }
    if (rest.length > 0) {
        groups.unshift(rest);
    }

    return groups.join(',') + ',' + lastThree;
}

/** Drops trailing zeros down to `minDecimals`, and the dot with them when nothing is left. */
function trimFraction(text, minDecimals) {
    const dot = text.indexOf('.');
    if (dot === -1) {
        return text;
    }

    const integerPart = text.slice(0, dot);
    let fraction = text.slice(dot + 1);

    while (fraction.length > minDecimals && fraction.endsWith('0')) {
        fraction = fraction.slice(0, -1);
    }

    return fraction.length > 0 ? integerPart + '.' + fraction : integerPart;
}

/**
 * Indian grouping for bills, PDFs and exports: `12,34,567.50`, `-1,234.00`.
 *
 * @returns {string}
 */
export function formatMoney(value) {
    const text = toScaledString(requireScaled(value, MONEY_SCALE, 'amount'), MONEY_SCALE);
    const negative = text[0] === '-';
    const unsigned = negative ? text.slice(1) : text;
    const dot = unsigned.indexOf('.');

    return (negative ? '-' : '') + groupIndian(unsigned.slice(0, dot)) + '.' + unsigned.slice(dot + 1);
}

/** Quantity for display, trailing zeros trimmed: `1.5`, `2`. @returns {string} */
export function formatQuantity(value) {
    return trimFraction(toScaledString(requireScaled(value, QUANTITY_SCALE, 'quantity'), QUANTITY_SCALE), 0);
}

/** Rate for display, 2 to 4 decimals: `12.50`, `12.3456`. @returns {string} */
export function formatRate(value) {
    return trimFraction(toScaledString(requireScaled(value, QUANTITY_SCALE, 'rate'), QUANTITY_SCALE), 2);
}

// ---------------------------------------------------------------------------
// Document calculator: step-for-step mirror of App\Support\Billing\DocumentCalculator (C3)
// ---------------------------------------------------------------------------

/** `true` only for the values a checkbox or a JSON payload can legitimately send. */
function toBoolean(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
}

/** `flat` unless the caller explicitly asked for `percentage`. */
function normaliseDiscountType(value, label) {
    if (value === undefined || value === null || value === '') {
        return 'flat';
    }
    if (value === 'flat' || value === 'percentage') {
        return value;
    }

    throw new MoneyError('invalid_number', 'The discount type on ' + label + ' must be "flat" or "percentage".');
}

/** Treats an omitted or blank optional field as `fallback`. */
function withDefault(value, fallback) {
    return value === undefined || value === null || value === '' ? fallback : value;
}

/**
 * Calculates a sale, purchase, quotation or capital document exactly as the server does.
 *
 * @param {Array<object>} lines each `{quantity, rate, discount?, discount_type?, vatable?, conversion_factor?}`
 * @param {object} header `{vat_rate, discount?, discount_type?, tds_amount?, force_non_taxable?, expected_total?}`
 * @returns {{ok: true, totals: object}|{ok: false, reason: string, message: string}}
 */
export function calculateDocument(lines, header) {
    try {
        return { ok: true, totals: computeDocument(lines, header) };
    } catch (error) {
        if (error instanceof MoneyError) {
            return { ok: false, reason: error.reason, message: error.message };
        }

        throw error;
    }
}

function computeDocument(lines, header) {
    if (!Array.isArray(lines) || lines.length === 0) {
        throw new MoneyError('subtotal_not_positive', 'Add at least one line before calculating the bill.');
    }

    const input = header ?? {};
    const forceNonTaxable = toBoolean(input.force_non_taxable);

    // Step 6 zeroes the rate for PAN invoices, but the header still has to carry a legal one.
    const declaredVatRate = parseField(input.vat_rate, PERCENT_SCALE, 'VAT rate');
    if (declaredVatRate < 0n || declaredVatRate > ONE_HUNDRED_PERCENT) {
        throw new MoneyError('percentage_out_of_range', 'The VAT rate must be between 0 and 100.');
    }

    // --- Steps 1 to 3: lines -------------------------------------------------
    const lineTotals = [];
    let vatableSubtotal = 0n;
    let nonVatableSubtotal = 0n;

    lines.forEach((line, index) => {
        const position = index + 1;

        const quantity = parseField(line.quantity, QUANTITY_SCALE, 'quantity on line ' + position);
        const rate = parseField(line.rate, QUANTITY_SCALE, 'rate on line ' + position);
        if (rate < 0n) {
            throw new MoneyError('negative_rate', 'The rate on line ' + position + ' cannot be negative.');
        }

        const factor = parseField(
            withDefault(line.conversion_factor, '1'),
            QUANTITY_SCALE,
            'unit conversion factor on line ' + position,
        );
        if (factor <= 0n) {
            throw new MoneyError(
                'invalid_conversion_factor',
                'The unit conversion factor on line ' + position + ' must be more than zero.',
            );
        }

        const baseQuantity = multiplyScaled(quantity, QUANTITY_SCALE, factor, QUANTITY_SCALE, QUANTITY_SCALE);
        const gross = multiplyScaled(quantity, QUANTITY_SCALE, rate, QUANTITY_SCALE, MONEY_SCALE);

        // A discount always moves the line toward zero, so it carries the sign of the line.
        const negativeLine = gross < 0n;
        const grossMagnitude = negativeLine ? -gross : gross;

        const discountType = normaliseDiscountType(line.discount_type, 'line ' + position);
        const rawDiscount = withDefault(line.discount, '0');

        let discountMagnitude;
        let discountValue;

        if (discountType === 'percentage') {
            const percent = parseField(rawDiscount, PERCENT_SCALE, 'discount percentage on line ' + position);
            // A negative percentage is reported as a negative discount, not as a range error, so
            // that both discount shapes give the same reason and the same wording. This mirrors
            // DocumentCalculator's shared percentage helper ($isDiscount); the two engines must
            // agree on every reason code, because the server's code is the one the 422 carries.
            if (percent < 0n) {
                throw new MoneyError('negative_discount', 'The discount on line ' + position + ' cannot be negative.');
            }
            if (percent > ONE_HUNDRED_PERCENT) {
                throw new MoneyError(
                    'percentage_out_of_range',
                    'The discount percentage on line ' + position + ' must be between 0 and 100.',
                );
            }
            discountMagnitude = divideRoundHalfUp(grossMagnitude * percent, PERCENT_DIVISOR);
            discountValue = toScaledString(percent, PERCENT_SCALE);
        } else {
            const flat = parseField(rawDiscount, MONEY_SCALE, 'discount on line ' + position);
            if (flat < 0n) {
                throw new MoneyError('negative_discount', 'The discount on line ' + position + ' cannot be negative.');
            }
            if (flat > grossMagnitude) {
                throw new MoneyError(
                    'line_discount_exceeds_line',
                    'The discount on line ' +
                        position +
                        ' is more than the line amount of ' +
                        toScaledString(grossMagnitude, MONEY_SCALE) +
                        '.',
                );
            }
            discountMagnitude = flat;
            discountValue = toScaledString(flat, MONEY_SCALE);
        }

        const discountAmount = negativeLine ? -discountMagnitude : discountMagnitude;
        const lineTotal = gross - discountAmount;
        const vatable = forceNonTaxable ? false : toBoolean(line.vatable);

        if (vatable) {
            vatableSubtotal += lineTotal;
        } else {
            nonVatableSubtotal += lineTotal;
        }

        lineTotals.push({
            quantity: toScaledString(quantity, QUANTITY_SCALE),
            rate: toScaledString(rate, QUANTITY_SCALE),
            conversion_factor: toScaledString(factor, QUANTITY_SCALE),
            base_quantity: toScaledString(baseQuantity, QUANTITY_SCALE),
            gross: toScaledString(gross, MONEY_SCALE),
            discount_type: discountType,
            discount_value: discountValue,
            discount_amount: toScaledString(discountAmount, MONEY_SCALE),
            line_total: toScaledString(lineTotal, MONEY_SCALE),
            vatable,
        });
    });

    const subtotal = vatableSubtotal + nonVatableSubtotal;
    if (subtotal <= 0n) {
        throw new MoneyError('subtotal_not_positive', 'The bill subtotal must be more than zero.');
    }

    // --- Step 4: header discount, split proportionally ------------------------
    const headerDiscountType = normaliseDiscountType(input.discount_type, 'the bill');
    const rawHeaderDiscount = withDefault(input.discount, '0');
    let headerDiscount;

    if (headerDiscountType === 'percentage') {
        const percent = parseField(rawHeaderDiscount, PERCENT_SCALE, 'bill discount percentage');
        // Negative percentage reports as negative_discount, matching the line rule above and
        // DocumentCalculator's shared percentage helper.
        if (percent < 0n) {
            throw new MoneyError('negative_discount', 'The bill discount cannot be negative.');
        }
        if (percent > ONE_HUNDRED_PERCENT) {
            throw new MoneyError('percentage_out_of_range', 'The bill discount percentage must be between 0 and 100.');
        }
        headerDiscount = divideRoundHalfUp(subtotal * percent, PERCENT_DIVISOR);
    } else {
        headerDiscount = parseField(rawHeaderDiscount, MONEY_SCALE, 'bill discount');
        if (headerDiscount < 0n) {
            throw new MoneyError('negative_discount', 'The bill discount cannot be negative.');
        }
        if (headerDiscount > subtotal) {
            throw new MoneyError(
                'header_discount_exceeds_subtotal',
                'The bill discount is more than the subtotal of ' + toScaledString(subtotal, MONEY_SCALE) + '.',
            );
        }
    }

    let headerDiscountVatable = 0n;
    let headerDiscountNonVatable = 0n;

    if (headerDiscount > 0n) {
        if (vatableSubtotal < 0n || nonVatableSubtotal < 0n) {
            throw new MoneyError(
                'negative_group_total',
                'A bill discount cannot be split while a taxable or non-taxable subtotal is negative.',
            );
        }
        [headerDiscountVatable, headerDiscountNonVatable] = allocateScaledAmount(headerDiscount, [
            vatableSubtotal,
            nonVatableSubtotal,
        ]);
    }

    // --- Step 5: group totals -------------------------------------------------
    const taxableAmount = vatableSubtotal - headerDiscountVatable;
    const nontaxableAmount = nonVatableSubtotal - headerDiscountNonVatable;

    if (taxableAmount < 0n || nontaxableAmount < 0n) {
        throw new MoneyError('negative_group_total', 'The taxable and non-taxable totals cannot be negative.');
    }

    // --- Steps 6 and 7: VAT and total -----------------------------------------
    const vatRate = forceNonTaxable ? 0n : declaredVatRate;
    const vatAmount = divideRoundHalfUp(taxableAmount * vatRate, PERCENT_DIVISOR);
    const total = taxableAmount + nontaxableAmount + vatAmount;

    if (total <= 0n) {
        throw new MoneyError('total_not_positive', 'The bill total must be more than zero.');
    }

    // --- Step 8: TDS ----------------------------------------------------------
    const tdsBase = taxableAmount + nontaxableAmount;
    const tdsAmount = parseField(withDefault(input.tds_amount, '0'), MONEY_SCALE, 'TDS amount');

    if (tdsAmount < 0n || tdsAmount > tdsBase) {
        throw new MoneyError(
            'tds_exceeds_base',
            'TDS must be between 0.00 and ' + toScaledString(tdsBase, MONEY_SCALE) + '.',
        );
    }

    const settlementDue = total - tdsAmount;

    // --- Step 9: the preview the user saw must still be the bill we are saving --
    const expectedTotal = withDefault(input.expected_total, null);
    if (expectedTotal !== null) {
        const expected = parseField(expectedTotal, MONEY_SCALE, 'expected total');
        if (expected !== total) {
            throw new MoneyError('total_mismatch', 'The bill total changed. Please review it before saving.');
        }
    }

    return {
        lines: lineTotals,
        vatable_subtotal: toScaledString(vatableSubtotal, MONEY_SCALE),
        non_vatable_subtotal: toScaledString(nonVatableSubtotal, MONEY_SCALE),
        header_discount: toScaledString(headerDiscount, MONEY_SCALE),
        header_discount_vatable: toScaledString(headerDiscountVatable, MONEY_SCALE),
        header_discount_non_vatable: toScaledString(headerDiscountNonVatable, MONEY_SCALE),
        taxable_amount: toScaledString(taxableAmount, MONEY_SCALE),
        nontaxable_amount: toScaledString(nontaxableAmount, MONEY_SCALE),
        vat_amount: toScaledString(vatAmount, MONEY_SCALE),
        total: toScaledString(total, MONEY_SCALE),
        tds_amount: toScaledString(tdsAmount, MONEY_SCALE),
        settlement_due: toScaledString(settlementDue, MONEY_SCALE),
        vat_rate: toScaledString(vatRate, PERCENT_SCALE),
    };
}
