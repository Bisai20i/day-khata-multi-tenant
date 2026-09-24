import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { todayInKathmandu } from '@/lib/format';

const PENDING_RECEIPT_KEY = 'pos-last-receipt';

/**
 * A quantity box as the server wants it: the typed string untouched, or '0'
 * when the box is empty or was never present (a cart restored from an older
 * localStorage payload has no bonus field at all). Never a number - the
 * string goes straight into Quantity::of() server-side (C1).
 */
function enteredQuantity(value) {
    return value === '' || value === null || value === undefined ? '0' : String(value);
}

/**
 * Sale submission + receipt confirmation. Posts the /sales payload, stashes
 * the created-sale receipt in sessionStorage across the server redirect and
 * re-opens it once the bounce back to /pos lands.
 */
export function usePosCheckout({
    form,
    totals,
    cashReceived,
    resolvedPaymentMode,
    searchQuery,
    syncActiveCartFromForm,
    persistCartsNow,
}) {
    const page = usePage();
    const receiptOpen = ref(false);
    const receipt = ref(null);

    /**
     * Cash actually tendered for the sale being submitted.
     *
     * The bill itself is posted for exactly the amount due, so the change owed is
     * the only part of the receipt that is not a stored fact - it is captured here
     * right before submitting, and every other figure on the receipt comes back
     * from the server.
     */
    let tenderedCash = '0.00';

    function resetForNextSale() {
        form.reset();
        form.clearErrors();
        form.date = todayInKathmandu();
        form.lines = [];
        searchQuery.value = '';
        syncActiveCartFromForm();
        // The posted cart must not survive in localStorage: the audit found the
        // print step throwing before any reset ran, leaving a cart that had
        // already been billed sitting on screen ready to be billed again (P0-6).
        persistCartsNow();
    }

    /**
     * `action` mirrors legacy's distinct Save vs Save & Print buttons (both post
     * the identical /sales payload; only whether the print window opens after a
     * successful save differs).
     */
    function completeSale(action = 'print') {
        if (!totals.value) return;

        const expectedTotal = totals.value.total;
        tenderedCash = cashReceived.value;

        form.transform((data) => ({
            ...data,
            payment_mode: resolvedPaymentMode.value,
            // The server computes the actual Rs discount itself from the raw value
            // plus its type, so the raw entered value and the mapped type are sent
            // as-is - never a client-resolved amount.
            discount: data.discount === '' ? '0' : data.discount,
            discount_type: data.discount_type === 'percent' ? 'percentage' : 'flat',
            cash_amount: data.cash_amount === '' ? '0' : data.cash_amount,
            bank_amount: data.bank_amount === '' ? '0' : data.bank_amount,
            tds_amount: data.tds_amount === '' ? '0' : data.tds_amount,
            // C8: the total the cashier was looking at. The server refuses the
            // save if it arrives at anything else.
            expected_total: expectedTotal,
            lines: data.lines.map((line) => ({
                item_id: line.item_id,
                quantity: line.quantity,
                // Free units: an explicit '0' when the box is empty, never
                // `undefined`. `mrp` is not sent - it only ever filled `rate`.
                bonus_quantity: enteredQuantity(line.bonus_quantity),
                rate: line.rate,
                discount: line.discount === '' ? '0' : line.discount,
                discount_type: line.discountType === 'percent' ? 'percentage' : 'flat',
            })),
        })).post('/sales', {
            preserveScroll: true,
            onSuccess: () => {
                // C11: the server says exactly which sale it created and where its
                // print view is. Nothing is inferred from the redirected-to list
                // any more, which is what printed the wrong bill for a back-dated
                // sale or a second till (audit P0-6).
                const created = page.props.flash?.created;

                if (created?.receipt) {
                    try {
                        sessionStorage.setItem(
                            PENDING_RECEIPT_KEY,
                            JSON.stringify({ ...created.receipt, tendered_cash: tenderedCash }),
                        );
                    } catch {
                        // Storage unavailable - the sale is posted either way, the
                        // cashier just won't see the on-screen receipt.
                    }
                }

                // Clear the cart (and its localStorage copy) BEFORE navigating, so
                // a failure anywhere after this point cannot leave a billed cart
                // behind.
                resetForNextSale();

                if (action === 'print' && created?.print_url) window.open(created.print_url, '_blank');

                router.visit('/pos', { onSuccess: applyPendingReceipt });
            },
        });
    }

    function applyPendingReceipt() {
        let raw;
        try {
            raw = sessionStorage.getItem(PENDING_RECEIPT_KEY);
        } catch {
            return;
        }
        if (!raw) return;

        try {
            sessionStorage.removeItem(PENDING_RECEIPT_KEY);
            receipt.value = JSON.parse(raw);
            receiptOpen.value = true;
        } catch {
            // malformed sessionStorage payload - nothing to recover, ignore.
        }
    }

    function closeReceipt() {
        receiptOpen.value = false;
        receipt.value = null;
    }

    return { receiptOpen, receipt, completeSale, applyPendingReceipt, closeReceipt };
}
