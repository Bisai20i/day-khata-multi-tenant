import { ref } from 'vue';
import { formatQuantity, parseQuantity } from '@/lib/money';
import { compareQuantity, subtractQuantity } from '@/lib/quantity';

/**
 * Split a cart line into a new cart tab (mirrors legacy's per-row split icon):
 * peel off part of a line's quantity into a brand-new draft cart (same
 * customer/store/invoice type), leaving the remainder on the original line.
 */
export function usePosSplit({ form, carts, makeCart, toast }) {
    const splitModalOpen = ref(false);
    const splitLineIndex = ref(null);
    const splitQuantity = ref('');

    function openSplitModal(index) {
        const line = form.lines[index];
        if (!line || compareQuantity(line.quantity, '0') <= 0) return;
        splitLineIndex.value = index;
        splitQuantity.value = '';
        splitModalOpen.value = true;
    }

    function closeSplitModal() {
        splitModalOpen.value = false;
        splitLineIndex.value = null;
        splitQuantity.value = '';
    }

    function confirmSplit() {
        const index = splitLineIndex.value;
        const line = form.lines[index];
        if (!line) {
            closeSplitModal();
            return;
        }

        const parsed = parseQuantity(splitQuantity.value === '' ? '0' : splitQuantity.value);
        const qty = parsed.ok ? parsed.value : null;

        if (qty === null || compareQuantity(qty, '0') <= 0 || compareQuantity(qty, line.quantity) >= 0) {
            toast({ message: 'Enter a quantity less than the line’s current quantity.', variant: 'danger' });
            return;
        }

        line.quantity = subtractQuantity(line.quantity, qty);

        const newCart = makeCart();
        newCart.customer_id = form.customer_id;
        newCart.store_id = form.store_id;
        // The free units stay with the line they were entered on: splitting a
        // paid quantity in two must not hand the customer twice the bonus stock,
        // and splitting bonus units proportionally would need a division nobody
        // asked for. The cashier can retype the bonus on either cart.
        newCart.lines = [{ ...line, quantity: qty, bonus_quantity: '' }];
        carts.value.push(newCart);

        closeSplitModal();
        toast({ message: `Split ${formatQuantity(qty)} into a new cart.`, variant: 'success' });
    }

    return { splitModalOpen, splitLineIndex, splitQuantity, openSplitModal, closeSplitModal, confirmSplit };
}
