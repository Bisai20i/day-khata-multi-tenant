import { computed } from 'vue';
import { addMoney, calculateDocument, moneyEquals } from '@/lib/money';
import { enteredAmount, toggleDiscountTypeOn } from '@/lib/saleCreate';

/**
 * The single totals preview for Sales/Create.vue, mirroring the server step
 * for step (calculateDocument), plus the submit-gating state derived from it.
 */
export function useSaleCreatePreview(form, { itemsById, isPanInvoice, effectiveVatRate }) {
    const preview = computed(() =>
        calculateDocument(
            form.lines.map((line) => {
                const item = itemsById.value[line.item_id];
                const unit = item?.units?.find((u) => u.id === line.item_unit_id);

                return {
                    quantity: line.quantity,
                    rate: line.rate,
                    discount: line.discount,
                    discount_type: line.discount_type,
                    vatable: item?.is_vatable ?? false,
                    conversion_factor: unit?.conversion_factor ?? 1,
                };
            }),
            {
                vat_rate: effectiveVatRate.value,
                discount: form.discount,
                discount_type: form.discount_type,
                tds_amount: form.tds_amount,
                force_non_taxable: isPanInvoice.value,
            },
        ),
    );

    const totals = computed(() => (preview.value.ok ? preview.value.totals : null));

    // A half-typed line is not an error worth shouting about: the calculator says
    // "the quantity is required" for every empty row, which would put a red box on
    // screen before the cashier has typed anything.
    const hasEnteredLines = computed(() =>
        form.lines.some((line) => line.item_id && line.quantity !== '' && line.rate !== ''),
    );
    const previewError = computed(() => {
        if (preview.value.ok) return null;

        // Items with no sale price are added with a blank rate, which the
        // calculator rejects for the whole bill - say so instead of silently
        // hiding the summary.
        const unpriced = form.lines.filter((line) => line.item_id && (line.rate === '' || line.rate === null));
        if (unpriced.length > 0) {
            const names = unpriced.map((line) => itemsById.value[line.item_id]?.name ?? 'an item');

            return `Enter a rate for ${names.join(', ')} to see the total.`;
        }

        return hasEnteredLines.value ? preview.value.message : null;
    });

    function toggleHeaderDiscountType() {
        toggleDiscountTypeOn(form, totals.value?.header_discount);
    }

    const showBankField = computed(() => form.payment_mode === 'bank' || form.payment_mode === 'partial');
    const showPartialFields = computed(() => form.payment_mode === 'partial');

    // Exact, no tolerance: the old `abs(sum - due) < 0.01` check let a one-paisa
    // mismatch through to the server, which then left it on the customer's ledger
    // forever (audit P0-4).
    const partialBalanced = computed(() => {
        if (!showPartialFields.value) return true;
        if (!totals.value) return false;

        const cash = enteredAmount(form.cash_amount);
        const bank = enteredAmount(form.bank_amount);
        if (cash === null || bank === null) return false;

        return moneyEquals(addMoney(cash, bank), totals.value.settlement_due);
    });

    const canSubmit = computed(
        () =>
            !form.processing &&
            !!form.customer_id &&
            !!form.date &&
            form.lines.length > 0 &&
            !!totals.value &&
            (!showPartialFields.value || partialBalanced.value),
    );

    return {
        totals,
        previewError,
        toggleHeaderDiscountType,
        showBankField,
        showPartialFields,
        partialBalanced,
        canSubmit,
    };
}
