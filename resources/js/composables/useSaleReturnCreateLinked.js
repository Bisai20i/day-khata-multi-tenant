import { computed, reactive, watch } from 'vue';
import { addMoney, allocateMoney, formatQuantity, parseQuantity, subtractMoney } from '@/lib/money';

const QUANTITY_DECIMALS = 4;

/**
 * Canonical 4dp quantity string -> scaled integer. Exact: the digits are read
 * as a BigInt, never through a float. money.js has no quantity subtraction or
 * comparison yet (see this pass's cross-file request to add
 * `subtractQuantity`/`compareQuantity`), and these two pieces of arithmetic
 * are what the return preview needs to tell "this finishes the line" from
 * "this is a part of it".
 */
function scaled(quantity) {
    const [whole, fraction = ''] = quantity.split('.');
    const negative = whole.startsWith('-');
    const digits = BigInt((negative ? whole.slice(1) : whole) + fraction.padEnd(QUANTITY_DECIMALS, '0'));

    return negative ? -digits : digits;
}

/** Scaled integer -> canonical 4dp quantity string. */
function unscaled(value) {
    const negative = value < 0n;
    const digits = (negative ? -value : value).toString().padStart(QUANTITY_DECIMALS + 1, '0');
    const text = `${digits.slice(0, -QUANTITY_DECIMALS)}.${digits.slice(-QUANTITY_DECIMALS)}`;

    return negative ? `-${text}` : text;
}

function quantityMinus(a, b) {
    return unscaled(scaled(a) - scaled(b));
}

/**
 * `round(amount x numerator / denominator)` with a single HalfUp rounding,
 * expressed through `allocateMoney`: for a two-way split the leftover paisa
 * goes to the larger fractional remainder, with ties to the first share,
 * which is exactly HalfUp of the first quotient. Same single rounding
 * `Money::multipliedByFraction()` does on the server.
 */
function fractionOf(amount, numerator, denominator) {
    return allocateMoney(amount, [numerator, quantityMinus(denominator, numerator)])[0];
}

/**
 * State and previews of the linked (against-a-bill) sales-return form: the
 * quantities typed per sale line, the credit each would post, and the totals.
 */
export function useSaleReturnCreateLinked(props, form) {
    const quantities = reactive({});
    // Bonus/free units returned alongside the paid quantity, keyed by sale line
    // id: they restock and credit nothing (audit section 3 "Sales").
    const bonusQuantities = reactive({});

    // A new sale means a new set of lines: drop anything typed against the
    // previous one rather than carry it across invoices.
    watch(
        () => props.selectedSale?.id,
        () => {
            for (const key of Object.keys(quantities)) {
                delete quantities[key];
            }

            for (const key of Object.keys(bonusQuantities)) {
                delete bonusQuantities[key];
            }

            if (props.selectedSale?.store_id) {
                form.store_id = props.selectedSale.store_id;
            }
        },
    );

    /**
     * The credit this line would post, by the same rule the server applies
     * (CONTRACTS C6): a fraction of each component, except when the entered
     * quantity finishes the line, where it is the component minus everything
     * already credited for it. Returns `null` for an untouched line and
     * `{ error }` for one that cannot be returned as entered.
     */
    function creditFor(line) {
        const raw = quantities[line.sale_line_id];

        if (raw === undefined || raw === null || String(raw).trim() === '') {
            return null;
        }

        const parsed = parseQuantity(raw);

        if (!parsed.ok) {
            return { error: 'Enter a quantity with at most 4 decimals.' };
        }

        const quantity = parsed.value;

        if (scaled(quantity) <= 0n) {
            return { error: 'Quantity must be greater than zero.' };
        }

        if (scaled(quantity) > scaled(line.remaining)) {
            return { error: `Only ${formatQuantity(line.remaining)} left to return.` };
        }

        const finishesLine = scaled(quantity) === scaled(line.remaining);

        const component = (whole, credited) =>
            finishesLine ? subtractMoney(whole, credited) : fractionOf(whole, quantity, line.quantity);

        return {
            quantity,
            vatable: line.vatable,
            net: component(line.net, line.credited_net),
            vat: component(line.vat, line.credited_vat),
            tds: component(line.tds, line.credited_tds),
        };
    }

    const credits = computed(() => {
        const rows = {};

        for (const line of props.selectedSale?.lines ?? []) {
            rows[line.sale_line_id] = creditFor(line);
        }

        return rows;
    });

    /**
     * Bonus units entered against a line, validated against what is left of that
     * line's own bonus quantity. Returns `null` for an untouched box, `{ error }`
     * for an impossible one, otherwise the canonical quantity string.
     */
    function bonusFor(line) {
        const raw = bonusQuantities[line.sale_line_id];

        if (raw === undefined || raw === null || String(raw).trim() === '') {
            return null;
        }

        const parsed = parseQuantity(raw);

        if (!parsed.ok) {
            return { error: 'Enter a bonus quantity with at most 4 decimals.' };
        }

        if (scaled(parsed.value) < 0n) {
            return { error: 'Bonus quantity cannot be negative.' };
        }

        if (scaled(parsed.value) > scaled(line.bonus_remaining ?? '0')) {
            return { error: `Only ${formatQuantity(line.bonus_remaining ?? '0')} bonus units left to return.` };
        }

        return { quantity: parsed.value };
    }

    const bonuses = computed(() => {
        const rows = {};

        for (const line of props.selectedSale?.lines ?? []) {
            rows[line.sale_line_id] = bonusFor(line);
        }

        return rows;
    });

    const lineErrors = computed(() => [
        ...Object.entries(credits.value)
            .filter(([, credit]) => credit?.error)
            .map(([saleLineId, credit]) => ({ saleLineId, message: credit.error })),
        ...Object.entries(bonuses.value)
            .filter(([, bonus]) => bonus?.error)
            .map(([saleLineId, bonus]) => ({ saleLineId, message: bonus.error })),
    ]);

    const totals = computed(() => {
        let taxable = '0.00';
        let nontaxable = '0.00';
        let vat = '0.00';
        let tds = '0.00';

        for (const credit of Object.values(credits.value)) {
            if (!credit || credit.error) {
                continue;
            }

            if (credit.vatable) {
                taxable = addMoney(taxable, credit.net);
            } else {
                nontaxable = addMoney(nontaxable, credit.net);
            }

            vat = addMoney(vat, credit.vat);
            tds = addMoney(tds, credit.tds);
        }

        const total = addMoney(addMoney(taxable, nontaxable), vat);

        return { taxable, nontaxable, vat, tds, total, credited: subtractMoney(total, tds) };
    });

    const payloadLines = computed(() =>
        Object.entries(credits.value)
            .filter(([, credit]) => credit && !credit.error)
            .map(([saleLineId, credit]) => ({
                sale_line_id: Number(saleLineId),
                quantity: credit.quantity,
                bonus_quantity: bonuses.value[saleLineId]?.error ? '0' : (bonuses.value[saleLineId]?.quantity ?? '0'),
            })),
    );

    return { quantities, bonusQuantities, credits, bonuses, lineErrors, totals, payloadLines };
}
