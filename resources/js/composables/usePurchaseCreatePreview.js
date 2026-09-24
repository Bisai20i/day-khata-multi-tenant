import { computed } from 'vue';
import { addMoney, calculateDocument, formatMoney, moneyEquals, parseMoney, percentOf } from '@/lib/money';

/**
 * Live bill preview for Purchases/Create.vue: totals, TDS preview, discount
 * toggles, partial-split validation and the submit gate. Pure derivation from
 * the form - nothing here posts or mutates anything except the toggles.
 */
export function usePurchaseCreatePreview(form, itemsById, isCorrectionSelected) {
    function isVatable(line) {
        return itemsById.value.get(line.item_id)?.is_vatable ?? false;
    }

    // The stored conversion factor for the unit this line is entered in, as the
    // decimal string the money module expects. '1' means the item's base unit.
    function conversionFactorFor(line) {
        if (line.item_unit_id === '' || line.item_unit_id === null) {
            return '1';
        }

        const unit = itemsById.value.get(line.item_id)?.units?.find((u) => u.id === line.item_unit_id);

        return unit?.conversion_factor != null ? String(unit.conversion_factor) : '1';
    }

    // The ONE source of truth for what this bill adds up to, step for step
    // identical to App\Support\Billing\DocumentCalculator on the server. The old
    // preview summed unrounded floats and never rounded the VAT at all, so a single
    // line of 1,001.50 previewed VAT 130.19 while the server booked 130.20
    // (audit P0-8). Nothing here parses money through Number().
    const previewLines = computed(() =>
        form.lines.map((line) => ({
            quantity: line.quantity === '' ? '0' : line.quantity,
            rate: line.rate === '' ? '0' : line.rate,
            discount: line.discount === '' ? '0' : line.discount,
            discount_type: line.discount_type,
            vatable: isVatable(line),
            conversion_factor: conversionFactorFor(line),
        })),
    );

    // The header as the calculator sees it BEFORE any TDS: TDS never changes the
    // bill's taxable, non-taxable, VAT or grand total, only what is left to pay,
    // so the base pass below is what the TDS rate is applied to. Same two-pass
    // shape as Purchase::post() on the server, deliberately.
    function previewHeader(tdsAmount) {
        return {
            vat_rate: form.vat_rate === '' ? '0' : form.vat_rate,
            discount: form.discount === '' ? '0' : form.discount,
            discount_type: form.discount_type,
            force_non_taxable: form.force_non_taxable,
            tds_amount: tdsAmount,
        };
    }

    const basePreview = computed(() => calculateDocument(previewLines.value, previewHeader('0')));

    // (taxable + nontaxable) x rate, one rounding, exactly as Money::percent()
    // does it on the server. A typed amount is used as-is when no rate is given.
    // percentOf throws on a rate the money module refuses (more than 2 decimals):
    // that shows up as a plain 0.00 preview, and the server's decimal:0,2 rule is
    // what reports it as a field error on submit.
    const tdsPreviewAmount = computed(() => {
        if (form.tds_rate === '' || form.tds_rate === null) {
            return form.tds_amount === '' ? '0' : form.tds_amount;
        }

        if (!basePreview.value.ok) {
            return '0';
        }

        try {
            return percentOf(
                addMoney(basePreview.value.totals.taxable_amount, basePreview.value.totals.nontaxable_amount),
                form.tds_rate,
            );
        } catch {
            return '0';
        }
    });

    const preview = computed(() => calculateDocument(previewLines.value, previewHeader(tdsPreviewAmount.value)));

    const totals = computed(() => (preview.value.ok ? preview.value.totals : null));
    // An incomplete bill (no lines filled in yet) is not an error worth shouting
    // about; anything else the calculator refuses is shown to the user as typed.
    const previewError = computed(() =>
        preview.value.ok || preview.value.reason === 'subtotal_not_positive' ? null : preview.value.message,
    );

    // %/Rs toggle. Percentage to flat keeps the exact rupee amount the calculator
    // already worked out, so nothing is recomputed in the browser. Flat to
    // percentage clears the field instead of dividing: a rupee amount has no exact
    // percentage, and inventing one here is how the preview and the bill drift
    // apart. The raw value plus its type is what is submitted either way.
    function toggleLineDiscountType(index) {
        const line = form.lines[index];

        if (line.discount_type === 'percentage') {
            line.discount = totals.value ? totals.value.lines[index].discount_amount : '';
            line.discount_type = 'flat';
        } else {
            line.discount = '';
            line.discount_type = 'percentage';
        }
    }

    function toggleHeaderDiscountType() {
        if (form.discount_type === 'percentage') {
            form.discount = totals.value ? totals.value.header_discount : '';
            form.discount_type = 'flat';
        } else {
            form.discount = '';
            form.discount_type = 'percentage';
        }
    }

    // A partial settlement has to land EXACTLY on the amount due (the grand total
    // less any TDS withheld). The server refuses anything else outright
    // (DocumentCalculator::assertExactSplit), so the form says so up front rather
    // than bouncing the user back from a 422. Exact string comparison, never a
    // float subtraction inside a 0.01 tolerance (audit P0-4).
    const partialSplitError = computed(() => {
        if (form.payment_mode !== 'partial' || !totals.value) {
            return null;
        }

        const cash = parseMoney(form.cash_amount === '' ? '0' : form.cash_amount);
        const bank = parseMoney(form.bank_amount === '' ? '0' : form.bank_amount);

        if (!cash.ok || !bank.ok) {
            return 'Enter the cash and bank amounts as plain rupee figures.';
        }

        const split = addMoney(cash.value, bank.value);

        return moneyEquals(split, totals.value.settlement_due)
            ? null
            : `Cash plus bank is ${formatMoney(split)}, but ${formatMoney(totals.value.settlement_due)} is due.`;
    });

    const canSubmit = computed(
        () => preview.value.ok
            && partialSplitError.value === null
            && (!isCorrectionSelected.value || form.reason.trim().length > 0),
    );

    return {
        preview,
        totals,
        previewError,
        partialSplitError,
        canSubmit,
        toggleLineDiscountType,
        toggleHeaderDiscountType,
    };
}
