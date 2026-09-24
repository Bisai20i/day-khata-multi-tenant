import { computed, reactive } from 'vue';
import { calculateDocument } from '@/lib/money';

/**
 * State and totals of the "return without a bill" form: free-typed lines that
 * name an item and a rate, priced the way a fresh sale would be.
 */
export function useSaleReturnCreateUnlinked(props, form) {
    function blankUnlinkedLine() {
        return { item_id: null, item_unit_id: null, quantity: '', bonus_quantity: '', rate: '' };
    }

    const unlinkedLines = reactive([blankUnlinkedLine()]);

    const itemOptions = computed(() => props.items.map((item) => ({ value: item.id, label: item.name })));
    const customerOptions = computed(() => props.customers.map((customer) => ({ value: customer.id, label: customer.name })));

    function itemById(itemId) {
        return props.items.find((item) => item.id === itemId) ?? null;
    }

    function unitOptionsFor(line) {
        const item = itemById(line.item_id);

        return (item?.units ?? []).map((unit) => ({ value: unit.id, label: unit.name }));
    }

    /**
     * The line's unit conversion factor, as a string for the calculator: the
     * chosen unit's own factor, or 1 when the line is entered in the item's base
     * unit. Never parsed through `Number()` (C8).
     */
    function conversionFactorFor(line) {
        const item = itemById(line.item_id);
        const unit = (item?.units ?? []).find((candidate) => candidate.id === line.item_unit_id);

        return unit ? String(unit.conversion_factor) : '1';
    }

    function addUnlinkedLine() {
        unlinkedLines.push(blankUnlinkedLine());
    }

    function removeUnlinkedLine(index) {
        unlinkedLines.splice(index, 1);

        if (unlinkedLines.length === 0) {
            unlinkedLines.push(blankUnlinkedLine());
        }
    }

    // Picking an item resets the unit (the old unit belongs to the old item) and
    // prefills the rate the item is normally sold at, the way Sales/Create.vue's
    // own item picker does.
    function onUnlinkedItemPicked(line, itemId) {
        line.item_id = itemId;
        line.item_unit_id = null;

        const item = itemById(itemId);

        if (item && (line.rate === '' || line.rate === null)) {
            line.rate = String(item.sale_rate ?? '');
        }
    }

    const unlinkedPayloadLines = computed(() =>
        unlinkedLines
            .filter((line) => line.item_id && String(line.quantity).trim() !== '' && String(line.rate).trim() !== '')
            .map((line) => ({
                item_id: line.item_id,
                item_unit_id: line.item_unit_id ?? null,
                quantity: String(line.quantity).trim(),
                bonus_quantity: String(line.bonus_quantity ?? '').trim() === '' ? '0' : String(line.bonus_quantity).trim(),
                rate: String(line.rate).trim(),
                vatable: !!itemById(line.item_id)?.is_vatable,
                conversion_factor: conversionFactorFor(line),
            })),
    );

    /**
     * The unlinked preview, computed by the very same algorithm the server runs
     * (C3/C8): `calculateDocument` with the company VAT rate and no header
     * discount or TDS, which is exactly what SalesReturn::postUnlinked() asks
     * DocumentCalculator for. `{ error }` when the entered numbers cannot make a
     * document at all.
     */
    const unlinkedTotals = computed(() => {
        if (unlinkedPayloadLines.value.length === 0) {
            return null;
        }

        const result = calculateDocument(
            unlinkedPayloadLines.value.map((line) => ({
                quantity: line.quantity,
                rate: line.rate,
                vatable: line.vatable,
                conversion_factor: line.conversion_factor,
            })),
            { vat_rate: props.invoiceSettings.default_vat_rate },
        );

        return result.ok ? result.totals : { error: result.message };
    });

    // A walk-in is the usual counterparty for a return with no bill, so the
    // seeded walk-in customer is the default here (audit section 3 "Sales").
    if (props.mode === 'unlinked' && props.walkInCustomerId) {
        form.customer_id = props.walkInCustomerId;
    }

    return {
        unlinkedLines,
        itemOptions,
        customerOptions,
        unitOptionsFor,
        addUnlinkedLine,
        removeUnlinkedLine,
        onUnlinkedItemPicked,
        unlinkedPayloadLines,
        unlinkedTotals,
    };
}
