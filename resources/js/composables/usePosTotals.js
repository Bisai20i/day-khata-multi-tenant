import { computed } from 'vue';
import { addMoney, calculateDocument, compareMoney, moneyEquals, parseMoney, subtractMoney } from '@/lib/money';

/**
 * POS totals: one preview, identical to the server's calculator.
 *
 * Every figure on the screen comes from calculateDocument(), the exact
 * mirror of App\Support\Billing\DocumentCalculator (CONTRACTS C3/C8). The
 * audit found quick-pay filling 56.49 against a bill the server booked at
 * 56.50, leaving the drawer a paisa short on every sale (P0-8).
 *
 * Also derives the split cash/bank payment figures (due, change, resolved
 * payment mode) from those totals.
 *
 * @param {object} options
 * @param {{ invoiceSettings: object }} options.props
 * @param {object} options.form The active cart's form.
 * @param {import('vue').ComputedRef<Record<number, object>>} options.itemsById
 */
export function usePosTotals({ props, form, itemsById }) {
    // The invoice type is never a form field - it's fixed per tenant by the
    // platform admin (TenantCompanySettingController), so this just reads the
    // value the server already applies to every sale (see Sale::post()).
    const isPanInvoice = computed(() => props.invoiceSettings.active_invoice_type === 'pan');

    // Never editable and never sent: the server always uses the tenant's
    // configured rate, and a PAN invoice carries no VAT at all.
    const effectiveVatRate = computed(() => (isPanInvoice.value ? '0.00' : String(props.invoiceSettings.default_vat_rate ?? '0')));

    const preview = computed(() =>
        calculateDocument(
            form.lines.map((line) => ({
                quantity: line.quantity,
                rate: line.rate,
                discount: line.discount,
                discount_type: line.discountType === 'percent' ? 'percentage' : 'flat',
                vatable: itemsById.value[line.item_id]?.is_vatable ?? false,
                conversion_factor: 1,
            })),
            {
                vat_rate: effectiveVatRate.value,
                discount: form.discount,
                discount_type: form.discount_type === 'percent' ? 'percentage' : 'flat',
                tds_amount: form.tds_amount,
                force_non_taxable: isPanInvoice.value,
            },
        ),
    );

    const totals = computed(() => (preview.value.ok ? preview.value.totals : null));

    /** A cart line whose rate was never filled in (items with no sale price start blank). */
    function isRateMissing(line) {
        return line.rate === '' || line.rate === null || line.rate === undefined;
    }

    const previewError = computed(() => {
        if (preview.value.ok || form.lines.length === 0) return null;

        const unpriced = form.lines.filter(isRateMissing);
        if (unpriced.length > 0) {
            const names = unpriced.map((line) => itemsById.value[line.item_id]?.name ?? 'an item');

            return `Enter a rate for ${names.join(', ')} to see the total.`;
        }

        return preview.value.message;
    });

    /** A cart line's own total, or null while that line is still incomplete. */
    function lineTotal(index) {
        return totals.value ? totals.value.lines[index].line_total : null;
    }

    /** The amount still to settle after any TDS, or null while the bill does not add up. */
    const settlementDue = computed(() => totals.value?.settlement_due ?? null);

    // --- Split-cash/bank payment panel ---------------------------------------
    // Mirrors legacy's always-visible Cash Paid + Bank Paid fields (no
    // mode-select dropdown to open first): the cashier just types what was
    // received in each, and the actual `payment_mode` the backend needs
    // (cash|bank|partial|credit) is derived from those two numbers rather than
    // picked explicitly. `bank` and `partial` still require a bank account (a
    // real backend rule - see Sale::post()), and `partial` still requires
    // cash+bank to add up exactly to the settlement due (also a backend rule -
    // there is no partial-payment-plus-credit-remainder mode server-side), so
    // this keeps the same submit-blocking guardrails the previous payment-mode
    // dropdown had, just surfaced as an inline due/change banner instead.
    /** A cashier-typed amount as a canonical 2dp string, or null when it is not valid. */
    function enteredAmount(value) {
        const parsed = parseMoney(value === '' || value === null || value === undefined ? '0' : value);

        return parsed.ok ? parsed.value : null;
    }

    const cashReceived = computed(() => enteredAmount(form.cash_amount) ?? '0.00');
    const bankReceived = computed(() => enteredAmount(form.bank_amount) ?? '0.00');
    const totalReceived = computed(() => addMoney(cashReceived.value, bankReceived.value));

    const dueAmount = computed(() => {
        if (!settlementDue.value) return '0.00';
        const remaining = subtractMoney(settlementDue.value, totalReceived.value);

        return compareMoney(remaining, '0.00') > 0 ? remaining : '0.00';
    });

    const changeAmount = computed(() => {
        if (!settlementDue.value) return '0.00';
        const over = subtractMoney(totalReceived.value, settlementDue.value);

        return compareMoney(over, '0.00') > 0 ? over : '0.00';
    });

    const hasDue = computed(() => compareMoney(dueAmount.value, '0.00') > 0);
    const hasChange = computed(() => compareMoney(changeAmount.value, '0.00') > 0);

    /**
     * Which payment mode the two amounts add up to.
     *
     * Cash above the amount due is a normal counter sale: the customer hands over
     * a 1000 note for a 226 bill and gets change. That used to resolve to
     * 'partial', which then refused to balance and left Complete disabled with no
     * way to take the money (audit P1 "POS cannot give change"). The sale is
     * posted as cash for exactly the amount due; the change is a drawer matter,
     * not a ledger one. A bank leg still has to land on the exact amount, since
     * there is no change to give on a transfer.
     */
    const resolvedPaymentMode = computed(() => {
        if (!settlementDue.value) return 'credit';

        const cashIsZero = moneyEquals(cashReceived.value, '0.00');
        const bankIsZero = moneyEquals(bankReceived.value, '0.00');

        if (cashIsZero && bankIsZero) return 'credit';
        if (bankIsZero && compareMoney(cashReceived.value, settlementDue.value) >= 0) return 'cash';
        if (cashIsZero && moneyEquals(bankReceived.value, settlementDue.value)) return 'bank';

        return 'partial';
    });

    const showBankAccountField = computed(() => resolvedPaymentMode.value === 'bank' || resolvedPaymentMode.value === 'partial');

    // Exact, no tolerance (audit P0-4).
    const paymentBalanced = computed(() => {
        if (resolvedPaymentMode.value !== 'partial') return true;
        if (!settlementDue.value) return false;

        return moneyEquals(totalReceived.value, settlementDue.value);
    });

    return {
        isPanInvoice,
        effectiveVatRate,
        preview,
        totals,
        isRateMissing,
        previewError,
        lineTotal,
        settlementDue,
        enteredAmount,
        cashReceived,
        bankReceived,
        totalReceived,
        dueAmount,
        changeAmount,
        hasDue,
        hasChange,
        resolvedPaymentMode,
        showBankAccountField,
        paymentBalanced,
    };
}
