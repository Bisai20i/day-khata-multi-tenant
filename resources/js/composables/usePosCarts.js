import { nextTick, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { todayInKathmandu } from '@/lib/format';
import { addQuantity } from '@/lib/quantity';

const CARTS_STORAGE_KEY = 'day-khata:pos-carts';

// --- Multiple parallel draft carts ("tabs") -------------------------------
// A cashier can hold several customers' baskets open at once, switch
// between them, and start new ones, instead of a single active cart -
// mirrors legacy's multi-tab draft carts. Only one cart's data lives on
// `form` at a time (so the rest of the POS page keeps binding straight to
// `form.*`); the inactive carts' data sits in the `carts` array and is
// swapped in/out of `form` on tab switch.
const CART_FORM_FIELDS = [
    'customer_id',
    'store_id',
    'chalani_number',
    'date',
    'bank_account_id',
    'discount',
    'discount_type',
    'cash_amount',
    'bank_amount',
    'tds_account_id',
    'tds_amount',
    'narration',
    'lines',
];

/**
 * Draft-cart state for the POS screen: the cart tabs, the active cart's
 * `form`, and localStorage persistence of the drafts.
 *
 * @param {{
 *   props: { customers: Array, walkInCustomerId: number|null },
 *   searchQuery: import('vue').Ref<string>,
 *   confirm: Function,
 *   toast: Function,
 *   closeMergeModal: Function,
 * }} options
 */
export function usePosCarts({ props, searchQuery, confirm, toast, closeMergeModal }) {
    let nextCartId = 1;

    function freshCartData() {
        return {
            // Defaults every new cart to the walk-in customer (audit section 3
            // "Sales") - still freely changeable per cart.
            customer_id: props.walkInCustomerId ?? null,
            store_id: null,
            chalani_number: '',
            // Asia/Kathmandu, not UTC: a toISOString() default dated every sale
            // struck between midnight and 05:45 local time to the previous day.
            date: todayInKathmandu(),
            bank_account_id: null,
            discount: '',
            // 'fixed' | 'percent' - matches each cart line's own discountType
            // convention (see addToCart()); mapped to the backend's
            // 'flat'/'percentage' enum only at submit time (completeSale()).
            discount_type: 'fixed',
            cash_amount: '',
            bank_amount: '',
            tds_account_id: null,
            tds_amount: '',
            narration: '',
            lines: [],
        };
    }

    function makeCart() {
        return { id: nextCartId++, ...freshCartData() };
    }

    const carts = ref([makeCart()]);
    const activeCartIndex = ref(0);
    const cartTabsScroller = ref(null);

    // Keep the active tab visible when many carts overflow the tab strip.
    watch([activeCartIndex, () => carts.value.length], async () => {
        await nextTick();
        cartTabsScroller.value?.querySelector('[aria-pressed="true"]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    });

    const form = useForm({
        customer_id: carts.value[0].customer_id,
        store_id: carts.value[0].store_id,
        chalani_number: carts.value[0].chalani_number,
        date: carts.value[0].date,
        bank_account_id: carts.value[0].bank_account_id,
        discount: carts.value[0].discount,
        discount_type: carts.value[0].discount_type,
        cash_amount: carts.value[0].cash_amount,
        bank_amount: carts.value[0].bank_amount,
        tds_account_id: carts.value[0].tds_account_id,
        tds_amount: carts.value[0].tds_amount,
        narration: carts.value[0].narration,
        lines: carts.value[0].lines,
    });

    function syncActiveCartFromForm() {
        const cart = carts.value[activeCartIndex.value];
        if (!cart) return;

        for (const field of CART_FORM_FIELDS) {
            cart[field] = form[field];
        }
    }

    function loadCartIntoForm(cart) {
        for (const field of CART_FORM_FIELDS) {
            form[field] = cart[field];
        }
        form.clearErrors();
    }

    function cartCustomerId(cart, index) {
        return index === activeCartIndex.value ? form.customer_id : cart.customer_id;
    }

    function cartLabel(cart, index) {
        const customer = props.customers.find((c) => c.id === cartCustomerId(cart, index));
        return customer ? customer.name : `Sale ${index + 1}`;
    }

    function cartLines(cart, index) {
        return index === activeCartIndex.value ? form.lines : cart.lines;
    }

    function cartLineCount(cart, index) {
        return cartLines(cart, index).length;
    }

    function switchToCart(index) {
        if (index === activeCartIndex.value) return;
        syncActiveCartFromForm();
        activeCartIndex.value = index;
        loadCartIntoForm(carts.value[index]);
        searchQuery.value = '';
    }

    function addCart() {
        syncActiveCartFromForm();
        carts.value.push(makeCart());
        activeCartIndex.value = carts.value.length - 1;
        loadCartIntoForm(carts.value[activeCartIndex.value]);
        searchQuery.value = '';
    }

    function closeCart(index) {
        if (carts.value.length === 1) {
            carts.value[0] = makeCart();
            activeCartIndex.value = 0;
            loadCartIntoForm(carts.value[0]);
            searchQuery.value = '';
            return;
        }

        const wasActive = index === activeCartIndex.value;
        carts.value.splice(index, 1);

        if (wasActive) {
            activeCartIndex.value = Math.min(index, carts.value.length - 1);
            loadCartIntoForm(carts.value[activeCartIndex.value]);
            searchQuery.value = '';
        } else if (index < activeCartIndex.value) {
            activeCartIndex.value -= 1;
        }
    }

    /** Cancels a held cart from the tab strip, asking first when it holds items. */
    async function confirmCloseCart(index) {
        const count = cartLineCount(carts.value[index], index);

        if (count > 0) {
            const ok = await confirm({
                title: 'Cancel this held sale?',
                message: `“${cartLabel(carts.value[index], index)}” has ${count} item${count === 1 ? '' : 's'}. Cancelling removes them and cannot be undone.`,
                tone: 'danger',
                confirmLabel: 'Cancel sale',
                cancelLabel: 'Keep sale',
            });
            if (!ok) return;
        }

        closeCart(index);
    }

    function mergeCartInto(sourceIndex) {
        if (sourceIndex === activeCartIndex.value) return;
        const source = carts.value[sourceIndex];
        if (!source) return;

        for (const sourceLine of source.lines) {
            const existing = form.lines.find(
                (l) => l.item_id === sourceLine.item_id && l.discountType === sourceLine.discountType,
            );
            if (existing) {
                existing.quantity = addQuantity(existing.quantity, sourceLine.quantity) ?? existing.quantity;
                // Free units add up exactly like paid ones: both carts' bonus
                // stock leaves the shelf on the one merged bill.
                // (addQuantity reads an empty or missing box as 0.)
                existing.bonus_quantity =
                    addQuantity(existing.bonus_quantity, sourceLine.bonus_quantity) ?? existing.bonus_quantity;
            } else {
                form.lines.push({ ...sourceLine });
            }
        }

        carts.value.splice(sourceIndex, 1);
        if (sourceIndex < activeCartIndex.value) {
            activeCartIndex.value -= 1;
        }

        closeMergeModal();
        toast({ message: `Merged “${source.name ?? 'cart'}” into the active cart.`, variant: 'success' });
    }

    // --- Draft-cart persistence (legacy's "Hold" + automatic local caching) ----
    // Legacy caches every draft cart to localStorage as it's edited, so a
    // refresh or crash doesn't lose an in-progress sale, and F9 ("Hold")
    // confirms an explicit save. This mirrors both: a deep watcher keeps
    // localStorage in sync automatically, and holdCarts() just gives the
    // cashier an explicit confirmation on top of that.
    let persistTimer = null;

    function persistCartsNow() {
        syncActiveCartFromForm();
        try {
            localStorage.setItem(
                CARTS_STORAGE_KEY,
                JSON.stringify({ carts: carts.value, activeCartIndex: activeCartIndex.value }),
            );
        } catch {
            // Storage unavailable (private browsing, disabled, quota) - draft
            // persistence is a convenience, not a hard requirement.
        }
    }

    function schedulePersist() {
        clearTimeout(persistTimer);
        persistTimer = setTimeout(persistCartsNow, 400);
    }

    function cartsHaveContent(storedCarts) {
        return storedCarts.some((cart) => (cart.lines?.length ?? 0) > 0 || cart.customer_id);
    }

    function restoreCartsFromStorage() {
        try {
            const raw = localStorage.getItem(CARTS_STORAGE_KEY);
            if (!raw) return false;

            const parsed = JSON.parse(raw);
            if (!Array.isArray(parsed.carts) || parsed.carts.length === 0 || !cartsHaveContent(parsed.carts)) return false;

            nextCartId = Math.max(...parsed.carts.map((c) => c.id ?? 0)) + 1;
            carts.value = parsed.carts;
            activeCartIndex.value = Math.min(Math.max(parsed.activeCartIndex ?? 0, 0), carts.value.length - 1);
            loadCartIntoForm(carts.value[activeCartIndex.value]);
            return true;
        } catch {
            return false;
        }
    }

    function holdCarts() {
        persistCartsNow();
        toast({ message: 'Carts held - safe if you refresh or close this tab.', variant: 'success' });
    }

    watch(() => [carts.value, form.lines, form.customer_id], () => schedulePersist(), { deep: true });

    return {
        form,
        carts,
        activeCartIndex,
        cartTabsScroller,
        makeCart,
        syncActiveCartFromForm,
        cartLabel,
        cartLines,
        cartLineCount,
        switchToCart,
        addCart,
        closeCart,
        confirmCloseCart,
        mergeCartInto,
        persistCartsNow,
        restoreCartsFromStorage,
        holdCarts,
    };
}
