<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PosTopBar from '@/components/pos/PosTopBar.vue';
import PosProductPanel from '@/components/pos/PosProductPanel.vue';
import PosCartPanel from '@/components/pos/PosCartPanel.vue';
import PosPaymentPanel from '@/components/pos/PosPaymentPanel.vue';
import PosCustomerModal from '@/components/pos/PosCustomerModal.vue';
import PosSplitModal from '@/components/pos/PosSplitModal.vue';
import PosMergeModal from '@/components/pos/PosMergeModal.vue';
import PosReceiptModal from '@/components/pos/PosReceiptModal.vue';
import PosShortcutsModal from '@/components/pos/PosShortcutsModal.vue';
import { useToast } from '@/composables/useToast';
import { useOpenFiscalYear } from '@/composables/useOpenFiscalYear';
import { useConfirm } from '@/composables/useConfirm';
import { usePosCarts } from '@/composables/usePosCarts';
import { usePosCheckout } from '@/composables/usePosCheckout';
import { usePosCustomerBridge } from '@/composables/usePosCustomerBridge';
import { usePosShortcuts } from '@/composables/usePosShortcuts';
import { usePosSplit } from '@/composables/usePosSplit';
import { usePosTotals } from '@/composables/usePosTotals';
import { formatQuantity, rateExcludingVat } from '@/lib/money';
import { addQuantity, compareQuantity, stepQuantity } from '@/lib/quantity';

/**
 * POS / walk-in quick-sale screen. Purely a different UI over the existing
 * `POST /sales` contract (App\Http\Controllers\Tenant\Sales\SaleController::
 * store()) - see resources/js/pages/Tenant/Sales/Create.vue for the same
 * payload shape this page mirrors. No new backend endpoint exists for this
 * page; both the sale itself and "+ New customer" below submit through the
 * app's existing /sales and /customers routes, which always redirect back to
 * their own index pages on success. To keep the cashier on /pos afterward
 * (and to show a receipt / auto-select a newly created customer), this page
 * stashes a small snapshot in sessionStorage right before each of those
 * submits and bounces back to /pos once the redirect lands - a frontend-only
 * bridge across an unavoidable server redirect, not a new backend feature
 * (see usePosCheckout and usePosCustomerBridge).
 *
 * The layout, cart-line editing, payment panel, and cart-tab workflow are a
 * close, IA-level replica of the legacy day_khata POS
 * (resources/views/outStock/new-pos.blade.php + pos-modules/*, and
 * docs/pos_user_manual.md / docs/pos_workflow.md), rebuilt with this app's own
 * design tokens and reka-ui-backed components rather than legacy's Bootstrap
 * markup, but matching legacy's always-visible terminal interaction model
 * (top bar with dashboard exit/FY/help/date/invoice-type badge; a 60/40
 * product-grid/cart split; two separate search + barcode boxes; a fixed
 * 2x2 payment grid; a Hold/Save/Save & Print action row) instead of the
 * progressive-disclosure layout an earlier pass shipped (hidden numpad,
 * required Date buried under an options toggle, one blocking modal per
 * action) - see the multi-tenant repo's POS UX rebuild plan for the full
 * comparison. Three spots are a deliberate, confirmed departure from legacy
 * rather than a gap: "+ New customer" stays a modal (legacy's new-tab flow
 * has no way back to the cart), cart drafts are still persisted to
 * localStorage (legacy's "Hold" is a no-op toast - drafts vanish on refresh),
 * and the invoice-type badge is read-only (legacy's live ABIT/TAX/PAN switch
 * needs backend work this screen-level rebuild doesn't attempt).
 */
defineOptions({ layout: AppLayout });

const { hasOpenFiscalYear } = useOpenFiscalYear();

const props = defineProps({
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    tdsAccounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    // The tenant's protected walk-in customer (audit section 3 "Sales") -
    // every fresh cart defaults to it below, since a counter sale usually
    // has nobody to name; still freely changeable per cart.
    walkInCustomerId: { type: Number, default: null },
    // Legacy's top bar shows "FY: <label>" (new-pos.blade.php) - display only.
    currentFiscalYear: { type: String, default: '' },
    invoiceSettings: {
        type: Object,
        default: () => ({
            default_vat_rate: '13.00',
            default_store_id: null,
            active_invoice_type: 'full',
        }),
    },
});

const { toast } = useToast();
const layoutChrome = useLayoutChrome('POS');
const { confirm } = useConfirm();

// Legacy searches customers by name OR mobile via two separate boxes; this
// single Combobox field covers both by feeding mobile_no into searchValue
// alongside the label, so typing a phone number still finds the customer.
const customerOptions = computed(() =>
    props.customers.map((c) => ({ value: c.id, label: c.name, searchValue: `${c.name} ${c.mobile_no ?? ''}`.trim() })),
);
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
const bankAccountOptions = computed(() =>
    props.bankAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} - ${a.name}` : a.name })),
);
const tdsAccountOptions = computed(() =>
    props.tdsAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} - ${a.name}` : a.name })),
);
const itemsById = computed(() => Object.fromEntries(props.items.map((i) => [i.id, i])));

const searchQuery = ref('');

const {
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
    confirmCloseCart,
    mergeCartInto,
    persistCartsNow,
    restoreCartsFromStorage,
    holdCarts,
} = usePosCarts({ props, searchQuery, confirm, toast, closeMergeModal });

/** Clears every line from the active cart after confirming. */
async function clearCart() {
    if (form.lines.length === 0) return;

    const ok = await confirm({
        title: 'Clear the cart?',
        message: `This removes all ${form.lines.length} item${form.lines.length === 1 ? '' : 's'} from this sale. It cannot be undone.`,
        tone: 'danger',
        confirmLabel: 'Clear cart',
    });
    if (!ok) return;

    form.lines = [];
}

// --- Item search + separate barcode scan input --------------------------
// Legacy (new-pos.blade.php) keeps these as two distinct always-visible
// boxes rather than one combined field: a search box that live-filters the
// tile grid by name, and a dedicated barcode box a scanner's Enter-terminated
// input goes into, which does an exact-match lookup and adds straight to the
// cart without touching the grid filter at all.
const searchFieldWrapper = ref(null);
const barcodeQuery = ref('');
const barcodeFieldWrapper = ref(null);

// PosProductPanel / PosTopBar hand their DOM elements back through events so
// the shortcuts composable and usePosCarts' scroll watcher keep their refs.
function setSearchFieldWrapper(element) {
    searchFieldWrapper.value = element;
}

function setBarcodeFieldWrapper(element) {
    barcodeFieldWrapper.value = element;
}

function setCustomerFieldWrapper(element) {
    customerFieldWrapper.value = element;
}

function setCartTabsScroller(element) {
    cartTabsScroller.value = element;
}

// Category filter row above the item grid (skipped entirely when there are
// no categories to show). Clicking the active chip again clears the filter
// back to "All" - mirrors legacy's category tabs.
const activeCategoryId = ref(null);

function selectCategory(id) {
    activeCategoryId.value = activeCategoryId.value === id ? null : id;
}

const filteredItems = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();

    return props.items.filter((item) => {
        if (activeCategoryId.value !== null && item.item_category_id !== activeCategoryId.value) return false;
        if (!q) return true;

        return item.name.toLowerCase().includes(q);
    });
});

// Defensive cap on rendered tiles, mirroring the legacy POS's 60-item cap,
// in case an item catalog grows large. Cheap to keep even if this tenant's
// catalog never gets close to it.
const ITEM_TILE_CAP = 60;
const visibleItems = computed(() => filteredItems.value.slice(0, ITEM_TILE_CAP));

function isOutOfStock(item) {
    return item.is_stockable && item.current_stock != null && compareQuantity(item.current_stock, '0') <= 0;
}

// Sum of an item's quantity across every open cart tab (not just the active
// one) - mirrors legacy's cross-draft stock check, since a cashier can be
// holding the same item in several parallel carts at once.
//
// Free units count: they leave the shelf exactly like paid ones, which is
// also how Sale::post()'s negative-stock check adds them up server-side
// (audit section 3 "Sales"). Warning on the paid quantity alone would let a
// cart that empties the shelf pass silently and then fail at posting.
function totalQuantityAcrossCarts(itemId) {
    return carts.value.reduce((sum, cart, index) => {
        const line = cartLines(cart, index).find((l) => l.item_id === itemId);
        const withBonus = addQuantity(line?.quantity ?? '0', line?.bonus_quantity ?? '0');

        return addQuantity(sum, withBonus ?? line?.quantity ?? '0') ?? sum;
    }, '0.0000');
}

function warnIfOverstock(itemId) {
    const item = itemsById.value[itemId];
    if (!item || !item.is_stockable || item.current_stock == null) return;

    const total = totalQuantityAcrossCarts(itemId);
    if (compareQuantity(total, item.current_stock) > 0) {
        toast({
            message: `Warning: ${item.name} quantity across carts (${formatQuantity(total)}) exceeds available stock (${formatQuantity(item.current_stock)}).`,
            variant: 'danger',
        });
    }
}

function onSearchKeydown(event) {
    if (event.key !== 'Enter') return;
    event.preventDefault();

    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return;

    const nameExact = props.items.find((item) => item.name.toLowerCase() === q);
    const match = nameExact ?? filteredItems.value[0];

    if (match) addToCart(match);
    searchQuery.value = '';
}

/** Dedicated barcode box: exact match only, never falls back to the grid filter. */
function onBarcodeSubmit() {
    const q = barcodeQuery.value.trim().toLowerCase();
    if (!q) return;

    const match = props.items.find((item) => item.barcode && item.barcode.toLowerCase() === q);
    if (match) {
        addToCart(match);
    } else {
        toast({ message: `No item found for barcode "${barcodeQuery.value.trim()}".`, variant: 'danger' });
    }
    barcodeQuery.value = '';
}

// --- Cart (form.lines) ---------------------------------------------------
function addToCart(item) {
    if (isOutOfStock(item)) {
        toast({ message: `${item.name} is out of stock.`, variant: 'danger' });
        return;
    }

    const index = form.lines.findIndex((l) => l.item_id === item.id);

    if (index !== -1) {
        form.lines[index].quantity = stepQuantity(form.lines[index].quantity, 1) ?? form.lines[index].quantity;
        warnIfOverstock(item.id);
        return;
    }

    // Pre-fill from the item's own sale_rate when it has one, so the
    // cashier isn't forced to type a rate for every line by hand - still
    // freely editable, this is just a starting point.
    // `bonus_quantity` is the free-of-charge quantity handed over with the
    // line (audit section 3 "Sales"): it moves stock but is never priced, so
    // the preview below never sees it. `mrp` is a browser-only entry aid that
    // fills `rate` and is never submitted (see applyLineMrp()).
    const rate = item.sale_rate != null ? String(item.sale_rate) : '';
    form.lines.push({ item_id: item.id, quantity: '1', bonus_quantity: '', mrp: '', rate, discount: '', discountType: 'fixed', showMore: false });
    warnIfOverstock(item.id);
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

function incrementQty(index) {
    const line = form.lines[index];
    line.quantity = stepQuantity(line.quantity, 1) ?? line.quantity;
    warnIfOverstock(line.item_id);
}

function decrementQty(index) {
    const line = form.lines[index];
    const next = stepQuantity(line.quantity, -1);

    if (next === null || compareQuantity(next, '0') <= 0) {
        removeLine(index);
        return;
    }

    line.quantity = next;
    warnIfOverstock(line.item_id);
}

/**
 * MRP / VAT-inclusive entry (audit section 3 "Sales").
 *
 * The cashier types the sticker price and the line's rate becomes
 * `MRP / 1.13` for a vatable item at 13%, so a Rs 113 MRP bills as rate 100
 * plus 13 VAT rather than having VAT charged a second time on top of it.
 *
 * The division happens inside the money module (rateExcludingVat: scaled
 * BigInt, one HalfUp rounding to 4dp) - nothing here touches Number(),
 * parseFloat or toFixed. Only the resulting RATE is submitted; the server
 * never receives the MRP and re-derives nothing from it.
 *
 * Takes the typed value as an argument because the template binds
 * :model-value + @update:model-value rather than v-model: a plain @input
 * listener would run before the model had been written back.
 */
function applyLineMrp(line, mrp) {
    line.mrp = mrp;

    if (mrp === '' || mrp === null || mrp === undefined) {
        return;
    }

    // A PAN invoice carries no VAT at all, and an exempt item never did: for
    // both, the MRP is the rate (a division by 1).
    const vatRate = !isPanInvoice.value && itemsById.value[line.item_id]?.is_vatable ? effectiveVatRate.value : '0';
    const result = rateExcludingVat(mrp, vatRate);

    // A half-typed MRP leaves the rate alone - the cashier is still typing.
    if (result.ok) {
        line.rate = result.value;
    }
}

// --- Totals: one preview, identical to the server's calculator -------------
// See usePosTotals (calculateDocument mirrors App\Support\Billing\DocumentCalculator).
const {
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
} = usePosTotals({ props, form, itemsById });

/**
 * Switching a discount between % and Rs.
 *
 * Percentage to flat is exact (the calculator already knows the rupee amount
 * the percentage came to); the other direction would need a division the
 * money module deliberately does not offer, so the field is cleared rather
 * than carrying a silently wrong number across.
 */
function toggleLineDiscountType(index) {
    const line = form.lines[index];

    if (line.discountType === 'percent') {
        const amount = totals.value?.lines[index]?.discount_amount;
        line.discount = amount && amount !== '0.00' ? amount : '';
        line.discountType = 'fixed';

        return;
    }

    line.discount = '';
    line.discountType = 'percent';
}

/** Reveals a cart line's MRP/free-units row - collapsed by default since they're edited rarely. */
function toggleLineMore(line) {
    line.showMore = !line.showMore;
}

function toggleHeaderDiscountType() {
    if (form.discount_type === 'percent') {
        const amount = totals.value?.header_discount;
        form.discount = amount && amount !== '0.00' ? amount : '';
        form.discount_type = 'fixed';

        return;
    }

    form.discount = '';
    form.discount_type = 'percent';
}

// Quick payment buttons, mirroring legacy's "Full Cash" / "Full Bank" /
// "Reset" row above the Cash Paid / Bank Paid fields. They fill the exact
// settlement due, to the paisa.
function quickPayFullCash() {
    if (!settlementDue.value) return;
    form.cash_amount = settlementDue.value;
    form.bank_amount = '0';
}

function quickPayFullBank() {
    if (!settlementDue.value) return;
    form.bank_amount = settlementDue.value;
    form.cash_amount = '0';
}

function quickPayReset() {
    form.cash_amount = '';
    form.bank_amount = '';
}

// --- Split a cart line into a new cart tab ---------------------------------
const { splitModalOpen, splitLineIndex, splitQuantity, openSplitModal, closeSplitModal, confirmSplit } = usePosSplit({
    form,
    carts,
    makeCart,
    toast,
});

// --- Merge another cart tab into the active one -----------------------------
const mergeModalOpen = ref(false);

function openMergeModal() {
    mergeModalOpen.value = true;
}

function closeMergeModal() {
    mergeModalOpen.value = false;
}

// --- Customer picker + quick "+ New customer" -----------------------------
const customerFieldWrapper = ref(null);
const { customerModalOpen, openCustomerModal, onCustomerCreated, applyPendingCustomer } = usePosCustomerBridge({
    props,
    form,
    toast,
});

// --- Sale submission + receipt confirmation ---------------------------
const { receiptOpen, receipt, completeSale, applyPendingReceipt, closeReceipt } = usePosCheckout({
    form,
    totals,
    cashReceived,
    resolvedPaymentMode,
    searchQuery,
    syncActiveCartFromForm,
    persistCartsNow,
});

/** Plain-words reason Complete sale is disabled, or '' when it can proceed. */
const submitBlockedReason = computed(() => {
    if (form.processing) return '';
    if (!hasOpenFiscalYear.value) return 'No open fiscal year. Set up a fiscal year before completing sales.';
    if (form.lines.length === 0) return 'Add at least one item to the cart to complete the sale.';
    if (!form.customer_id) return 'Select a customer to complete the sale.';
    if (!form.date) return 'Choose a sale date in the top bar.';
    if (!totals.value) return previewError.value ?? 'Fix the highlighted line details to see the total.';

    const mode = resolvedPaymentMode.value;
    if ((mode === 'bank' || mode === 'partial') && !form.bank_account_id) return 'Select a bank account for the bank payment.';
    if (mode === 'partial' && !paymentBalanced.value) return 'Cash plus bank must equal the total due exactly.';

    return '';
});

const canSubmit = computed(() => {
    if (!hasOpenFiscalYear.value) return false;
    if (form.processing || !form.customer_id || !form.date || form.lines.length === 0) return false;
    if (!totals.value) return false;

    const mode = resolvedPaymentMode.value;
    if ((mode === 'bank' || mode === 'partial') && !form.bank_account_id) return false;
    if (mode === 'partial' && !paymentBalanced.value) return false;

    return true;
});

// --- Keyboard shortcuts ----------------------------------------------------
// Global while this page is mounted; see usePosShortcuts for the F-key scheme.
const { shortcutsOpen, shortcutList, attachShortcuts, detachShortcuts } = usePosShortcuts({
    blockingModals: [customerModalOpen, receiptOpen, splitModalOpen, mergeModalOpen],
    searchFieldWrapper,
    barcodeFieldWrapper,
    customerFieldWrapper,
    canSubmit,
    completeSale,
    holdCarts,
});

// --- Focus mode / full screen ------------------------------------------------
// `layoutChrome.fullscreen` (hides Day Khata's own sidebar and top navbar so
// the POS content fills the whole viewport, closest this Inertia-page-
// inside-AppLayout setup can get to the legacy POS's dedicated full-viewport
// app shell) is ON by default for the whole time this page is mounted - the
// point is a viewport-locked terminal, not something the cashier opts into
// every visit. The Maximize2/Minimize2 button is a separate, purely optional
// layer on top: the real browser Fullscreen API, best-effort (some embedding
// contexts reject it, which is fine, chrome-hiding above already applies
// either way) - `isFullscreen` only tracks *that*, not the sidebar/navbar.
const isFullscreen = ref(false);

function toggleFullscreen() {
    isFullscreen.value = !isFullscreen.value;

    if (isFullscreen.value) {
        document.documentElement.requestFullscreen?.().catch(() => {
            // Fullscreen API unavailable/denied - not fatal, the sidebar/navbar
            // stay hidden regardless via layoutChrome.fullscreen above.
        });
    } else if (document.fullscreenElement) {
        document.exitFullscreen?.();
    }
}

function onFullscreenChange() {
    if (!document.fullscreenElement) isFullscreen.value = false;
}

onMounted(() => {
    layoutChrome.fullscreen = true;
    // Legacy's #npos is a true height:100vh, zero-padding terminal - AppLayout
    // otherwise always keeps some padding on <main>, even in fullscreen mode.
    layoutChrome.padded = false;
    restoreCartsFromStorage();
    applyPendingCustomer();
    applyPendingReceipt();
    attachShortcuts();
    document.addEventListener('fullscreenchange', onFullscreenChange);
});

onUnmounted(() => {
    detachShortcuts();
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    // AppLayout is now a persistent layout (stays mounted across
    // navigations), so its fullscreen flag must be reset explicitly on the
    // way out - otherwise leaving POS while in focus mode would leave the
    // next page's sidebar/navbar hidden too.
    layoutChrome.fullscreen = false;
    layoutChrome.padded = true;
});
</script>

<template>
    <div class="pos-root flex h-full min-h-0 flex-col overflow-y-auto lg:overflow-hidden">
        <PosTopBar
            v-model:date="form.date"
            :current-fiscal-year="currentFiscalYear"
            :is-pan-invoice="isPanInvoice"
            :is-fullscreen="isFullscreen"
            :carts="carts"
            :active-cart-index="activeCartIndex"
            :cart-label="cartLabel"
            :cart-line-count="cartLineCount"
            @open-shortcuts="shortcutsOpen = true"
            @toggle-fullscreen="toggleFullscreen"
            @switch-cart="switchToCart"
            @close-cart="confirmCloseCart"
            @open-merge="openMergeModal"
            @add-cart="addCart"
            @scroller="setCartTabsScroller"
        />

        <div class="pos-body flex min-h-0 flex-1 flex-col gap-3 lg:flex-row lg:gap-0">
            <PosProductPanel
                v-model:search-query="searchQuery"
                v-model:barcode-query="barcodeQuery"
                :active-category-id="activeCategoryId"
                :categories="categories"
                :filtered-items="filteredItems"
                :visible-items="visibleItems"
                :tile-cap="ITEM_TILE_CAP"
                :lines="form.lines"
                :is-out-of-stock="isOutOfStock"
                @select-category="selectCategory"
                @search-keydown="onSearchKeydown"
                @barcode-submit="onBarcodeSubmit"
                @add-item="addToCart"
                @search-wrapper="setSearchFieldWrapper"
                @barcode-wrapper="setBarcodeFieldWrapper"
            />

            <!-- RIGHT - cart & checkout -->
            <div class="pos-right flex min-w-0 shrink-0 flex-col gap-3 p-3 lg:h-full lg:min-h-0 lg:shrink lg:overflow-y-auto">
                <PosCartPanel
                    :form="form"
                    :customer-options="customerOptions"
                    :items-by-id="itemsById"
                    :line-total="lineTotal"
                    :is-rate-missing="isRateMissing"
                    @new-customer="openCustomerModal"
                    @clear-cart="clearCart"
                    @remove-line="removeLine"
                    @increment-qty="incrementQty"
                    @decrement-qty="decrementQty"
                    @warn-overstock="warnIfOverstock"
                    @toggle-discount-type="toggleLineDiscountType"
                    @toggle-more="toggleLineMore"
                    @apply-mrp="applyLineMrp"
                    @split-line="openSplitModal"
                    @customer-wrapper="setCustomerFieldWrapper"
                />

                <PosPaymentPanel
                    :form="form"
                    :totals="totals"
                    :preview-error="previewError"
                    :settlement-due="settlementDue"
                    :resolved-payment-mode="resolvedPaymentMode"
                    :payment-balanced="paymentBalanced"
                    :show-bank-account-field="showBankAccountField"
                    :has-due="hasDue"
                    :has-change="hasChange"
                    :due-amount="dueAmount"
                    :change-amount="changeAmount"
                    :bank-account-options="bankAccountOptions"
                    :tds-account-options="tdsAccountOptions"
                    :store-options="storeOptions"
                    :submit-blocked-reason="submitBlockedReason"
                    :can-submit="canSubmit"
                    @quick-pay-full-cash="quickPayFullCash"
                    @quick-pay-full-bank="quickPayFullBank"
                    @quick-pay-reset="quickPayReset"
                    @toggle-header-discount-type="toggleHeaderDiscountType"
                    @hold="holdCarts"
                    @complete="completeSale"
                />
            </div>
        </div>

        <PosCustomerModal v-model:open="customerModalOpen" @created="onCustomerCreated" />

        <PosSplitModal
            v-model:quantity="splitQuantity"
            :open="splitModalOpen"
            :line-index="splitLineIndex"
            :lines="form.lines"
            :items-by-id="itemsById"
            @close="closeSplitModal"
            @confirm="confirmSplit"
        />

        <PosMergeModal
            :open="mergeModalOpen"
            :carts="carts"
            :active-cart-index="activeCartIndex"
            :cart-label="cartLabel"
            :cart-line-count="cartLineCount"
            @close="closeMergeModal"
            @merge="mergeCartInto"
        />

        <PosReceiptModal :open="receiptOpen" :receipt="receipt" @close="closeReceipt" />

        <PosShortcutsModal v-model:open="shortcutsOpen" :shortcut-list="shortcutList" />
    </div>
</template>

<style scoped>
/*
 * Legacy's dedicated pos-modules CSS (styles-layout.blade.php,
 * styles-components.blade.php) is fully separate from the rest of its app -
 * mirrored here as scoped, POS-only CSS rather than new shared UI-kit
 * variants, for the same reason: this screen's density/sizing needs
 * (52px top bar, 60/40 split, 34px qty-stepper buttons) don't belong on
 * Button/Input/Card everywhere else in the app. Colors reuse the app's
 * existing --color-* tokens (already close to legacy's palette); all
 * border-radius stays 0 to match the app-wide square design.
 */

.pos-root {
    height: 100%;
}

.pos-body {
    overflow: hidden;
}

.pos-right {
    background: var(--color-bg-subtle);
}

@media (min-width: 1024px) {
    .pos-right {
        width: 40%;
    }
}
</style>
