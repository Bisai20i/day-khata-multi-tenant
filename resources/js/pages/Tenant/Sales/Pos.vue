<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    CircleHelp,
    CirclePause,
    Delete,
    Maximize2,
    Merge,
    Minimize2,
    Minus,
    Package,
    Plus,
    ScanBarcode,
    SplitSquareHorizontal,
    X,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Modal from '@/components/ui/Modal.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';
import {
    addMoney,
    calculateDocument,
    compareMoney,
    formatMoney,
    formatQuantity,
    formatRate,
    moneyEquals,
    parseMoney,
    parseQuantity,
    subtractMoney,
} from '@/lib/money';
import { todayInKathmandu } from '@/lib/format';

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
 * bridge across an unavoidable server redirect, not a new backend feature.
 *
 * The layout, cart-line editing, payment panel, and cart-tab workflow are a
 * deliberate replica of the legacy day_khata POS
 * (resources/views/outStock/new-pos.blade.php + pos-modules/*, and
 * docs/pos_user_manual.md / docs/pos_workflow.md) rebuilt with this app's own
 * design tokens and reka-ui-backed components rather than legacy's Bootstrap
 * markup: a wide product-tile browser (search + category chips + a VAT-
 * badged, stock-aware tile grid) next to a narrower cart/checkout column
 * (draft tabs, customer, cart lines with an inline qty stepper and a %/Rs
 * discount toggle, a single always-visible cash+bank payment panel with
 * quick-fill buttons and a due/change banner, split/merge between cart tabs,
 * and F-key shortcuts matching legacy's bindings). Cart tabs are additionally
 * persisted to localStorage (legacy's "Hold" and its automatic draft
 * caching) so a refresh or accidental tab close doesn't lose an in-progress
 * sale.
 */
const props = defineProps({
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    tdsAccounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    invoiceSettings: {
        type: Object,
        default: () => ({
            default_vat_rate: '13.00',
            default_store_id: null,
            sale_full_enabled: true,
            sale_abbreviated_enabled: true,
            sale_pan_enabled: true,
        }),
    },
});

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const PENDING_RECEIPT_KEY = 'pos-last-receipt';
const PENDING_CUSTOMER_KEY = 'pos-pending-customer';
const CARTS_STORAGE_KEY = 'day-khata:pos-carts';

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: c.name })));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
const bankAccountOptions = computed(() =>
    props.bankAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} — ${a.name}` : a.name })),
);
const tdsAccountOptions = computed(() =>
    props.tdsAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} — ${a.name}` : a.name })),
);
const itemsById = computed(() => Object.fromEntries(props.items.map((i) => [i.id, i])));

// Only the invoice types the tenant has switched on in Settings - the server
// rejects a disabled type as well, this just keeps it off the cashier's list.
const invoiceTypeOptions = computed(() =>
    [
        { value: 'full', label: 'Full tax invoice', enabled: props.invoiceSettings.sale_full_enabled },
        { value: 'abbreviated', label: 'Abbreviated tax invoice', enabled: props.invoiceSettings.sale_abbreviated_enabled },
        { value: 'pan', label: 'PAN invoice', enabled: props.invoiceSettings.sale_pan_enabled },
    ].filter((option) => option.enabled),
);

// --- Exact quantity arithmetic (4 decimals) --------------------------------
// resources/js/lib/money.js parses and formats quantities but exposes no
// add/compare helpers yet (a cross-file request is open for them), and this
// screen steps, sums and compares quantities constantly. Everything below
// works on the canonical 4dp string as a scaled BigInt, exactly the way the
// shared module works internally - never through Number() or parseFloat, so
// +1 on 0.125 can no longer turn into 1.13 (audit P1 "POS applies round2 to
// quantities").
const QUANTITY_SCALE = 4;

function toScaledQuantity(value) {
    const parsed = parseQuantity(value === '' || value === null || value === undefined ? '0' : value);
    if (!parsed.ok) return null;

    const negative = parsed.value.startsWith('-');
    const scaled = BigInt((negative ? parsed.value.slice(1) : parsed.value).replace('.', ''));

    return negative ? -scaled : scaled;
}

function fromScaledQuantity(scaled) {
    const negative = scaled < 0n;
    const digits = (negative ? -scaled : scaled).toString().padStart(QUANTITY_SCALE + 1, '0');
    const text = `${digits.slice(0, -QUANTITY_SCALE)}.${digits.slice(-QUANTITY_SCALE)}`;

    return negative ? `-${text}` : text;
}

/** Exact `a + b` on two quantities, or null when either is not a valid quantity. */
function addQuantity(a, b) {
    const left = toScaledQuantity(a);
    const right = toScaledQuantity(b);

    return left === null || right === null ? null : fromScaledQuantity(left + right);
}

/** Exact `a - b` on two quantities, or null when either is not a valid quantity. */
function subtractQuantity(a, b) {
    const left = toScaledQuantity(a);
    const right = toScaledQuantity(b);

    return left === null || right === null ? null : fromScaledQuantity(left - right);
}

/** -1, 0 or 1. Exact, no tolerance. Returns null when either side is unparsable. */
function compareQuantity(a, b) {
    const left = toScaledQuantity(a);
    const right = toScaledQuantity(b);
    if (left === null || right === null) return null;

    return left === right ? 0 : left < right ? -1 : 1;
}

/** Adds a whole number of units to a quantity, keeping its fractional part intact. */
function stepQuantity(value, delta) {
    return addQuantity(value, String(delta));
}

// --- Multiple parallel draft carts ("tabs") -------------------------------
// A cashier can hold several customers' baskets open at once, switch
// between them, and start new ones, instead of a single active cart -
// mirrors legacy's multi-tab draft carts. Only one cart's data lives on
// `form` at a time (so the rest of this page keeps binding straight to
// `form.*` exactly as before); the inactive carts' data sits in the `carts`
// array and is swapped in/out of `form` on tab switch.
const CART_FORM_FIELDS = [
    'customer_id',
    'store_id',
    'invoice_type',
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

let nextCartId = 1;

function freshCartData() {
    return {
        customer_id: null,
        store_id: null,
        invoice_type: invoiceTypeOptions.value[0]?.value ?? 'full',
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

const form = useForm({
    customer_id: carts.value[0].customer_id,
    store_id: carts.value[0].store_id,
    invoice_type: carts.value[0].invoice_type,
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
    activeTarget.value = null;
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
    scanQuery.value = '';
}

function addCart() {
    syncActiveCartFromForm();
    carts.value.push(makeCart());
    activeCartIndex.value = carts.value.length - 1;
    loadCartIntoForm(carts.value[activeCartIndex.value]);
    scanQuery.value = '';
}

function closeCart(index) {
    if (carts.value.length === 1) {
        carts.value[0] = makeCart();
        activeCartIndex.value = 0;
        loadCartIntoForm(carts.value[0]);
        scanQuery.value = '';
        return;
    }

    const wasActive = index === activeCartIndex.value;
    carts.value.splice(index, 1);

    if (wasActive) {
        activeCartIndex.value = Math.min(index, carts.value.length - 1);
        loadCartIntoForm(carts.value[activeCartIndex.value]);
        scanQuery.value = '';
    } else if (index < activeCartIndex.value) {
        activeCartIndex.value -= 1;
    }
}

const showAdvanced = ref(false);

// --- Item search / "barcode scan" input --------------------------------
// A barcode scanner just types digits into whatever text input is focused
// and then sends Enter, indistinguishable from a cashier typing - so this
// is a plain text field that live-filters the tile grid by name or barcode,
// and on Enter adds the best match to the cart (mirroring a real scan). A
// barcode match takes priority over a name match, both here and in
// onScanKeydown() below, since a scanned code is far more likely to be a
// barcode than to coincidentally match part of an item's name.
const scanQuery = ref('');
const scanFieldWrapper = ref(null);

// Category filter row above the item grid (skipped entirely when there are
// no categories to show). Clicking the active chip again clears the filter
// back to "All" - mirrors legacy's category tabs.
const activeCategoryId = ref(null);

function selectCategory(id) {
    activeCategoryId.value = activeCategoryId.value === id ? null : id;
}

const filteredItems = computed(() => {
    const q = scanQuery.value.trim().toLowerCase();

    return props.items.filter((item) => {
        if (activeCategoryId.value !== null && item.item_category_id !== activeCategoryId.value) return false;
        if (!q) return true;

        const barcodeMatch = item.barcode && item.barcode.toLowerCase().includes(q);
        const nameMatch = item.name.toLowerCase().includes(q);
        return barcodeMatch || nameMatch;
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

function quantityInCart(itemId) {
    const line = form.lines.find((l) => l.item_id === itemId);

    return line ? (toScaledQuantity(line.quantity) === null ? '0.0000' : parseQuantity(line.quantity || '0').value) : '0.0000';
}

function hasQuantityInCart(itemId) {
    return compareQuantity(quantityInCart(itemId), '0') > 0;
}

// Sum of an item's quantity across every open cart tab (not just the active
// one) - mirrors legacy's cross-draft stock check, since a cashier can be
// holding the same item in several parallel carts at once.
function totalQuantityAcrossCarts(itemId) {
    return carts.value.reduce((sum, cart, index) => {
        const line = cartLines(cart, index).find((l) => l.item_id === itemId);

        return addQuantity(sum, line?.quantity ?? '0') ?? sum;
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

function onScanKeydown(event) {
    if (event.key !== 'Enter') return;
    event.preventDefault();

    const q = scanQuery.value.trim().toLowerCase();
    if (!q) return;

    // Barcode match takes priority (exact match, like a real scanner would
    // resolve), falling back to an exact name match and then the top of the
    // already-filtered tile grid.
    const barcodeExact = props.items.find((item) => item.barcode && item.barcode.toLowerCase() === q);
    const nameExact = props.items.find((item) => item.name.toLowerCase() === q);
    const match = barcodeExact ?? nameExact ?? filteredItems.value[0];

    if (match) addToCart(match);
    scanQuery.value = '';
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
        activeTarget.value = { type: 'quantity', index };
        warnIfOverstock(item.id);
        return;
    }

    // Pre-fill from the item's own sale_rate when it has one, so the
    // cashier isn't forced to type a rate for every line by hand - still
    // freely editable, this is just a starting point.
    const rate = item.sale_rate != null ? String(item.sale_rate) : '';
    form.lines.push({ item_id: item.id, quantity: '1', rate, discount: '', discountType: 'fixed' });
    activeTarget.value = { type: 'rate', index: form.lines.length - 1 };
    warnIfOverstock(item.id);
}

function removeLine(index) {
    form.lines.splice(index, 1);
    activeTarget.value = null;
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

function focusTarget(type, index) {
    activeTarget.value = { type, index };
}

// --- Totals: one preview, identical to the server's calculator -------------
// Every figure on this screen comes from calculateDocument(), the exact
// mirror of App\Support\Billing\DocumentCalculator (CONTRACTS C3/C8). The
// audit found quick-pay filling 56.49 against a bill the server booked at
// 56.50, leaving the drawer a paisa short on every sale (P0-8).

const isPanInvoice = computed(() => form.invoice_type === 'pan');

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
const previewError = computed(() => (preview.value.ok || form.lines.length === 0 ? null : preview.value.message));

/** A cart line's own total, or null while that line is still incomplete. */
function lineTotal(index) {
    return totals.value ? totals.value.lines[index].line_total : null;
}

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

/** The amount still to settle after any TDS, or null while the bill does not add up. */
const settlementDue = computed(() => totals.value?.settlement_due ?? null);

// --- Split-cash/bank payment panel -----------------------------------------
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

// --- On-screen numpad ------------------------------------------------------
// Targets whichever field was last focused/tapped: a cart line's quantity or
// rate, or the cash-paid box. Typing directly into those Input fields still
// works too - the numpad just writes into the same reactive value.
const activeTarget = ref(null);

const activeTargetLabel = computed(() => {
    const t = activeTarget.value;
    if (!t) return 'Tap a quantity, rate, or cash-paid field';
    if (t.type === 'cash') return 'Cash paid';
    const line = form.lines[t.index];
    const name = line ? (itemsById.value[line.item_id]?.name ?? 'Item') : 'Item';
    return `${name} — ${t.type === 'quantity' ? 'Quantity' : 'Rate'}`;
});

function currentTargetValue() {
    const t = activeTarget.value;
    if (!t) return '';
    if (t.type === 'cash') return form.cash_amount;
    return form.lines[t.index]?.[t.type] ?? '';
}

function setTargetValue(value) {
    const t = activeTarget.value;
    if (!t) return;
    if (t.type === 'cash') {
        form.cash_amount = value;
        return;
    }
    if (form.lines[t.index]) form.lines[t.index][t.type] = value;
}

function pressDigit(digit) {
    if (!activeTarget.value) return;
    const current = String(currentTargetValue() ?? '');

    if (digit === '.') {
        if (current.includes('.')) return;
        setTargetValue((current === '' ? '0' : current) + '.');
        return;
    }

    setTargetValue(current === '0' ? String(digit) : current + String(digit));
}

function pressBackspace() {
    if (!activeTarget.value) return;
    setTargetValue(String(currentTargetValue() ?? '').slice(0, -1));
}

function pressClear() {
    if (!activeTarget.value) return;
    setTargetValue('');
}

// --- Split a cart line into a new cart tab ---------------------------------
// Mirrors legacy's per-row split icon: peel off part of a line's quantity
// into a brand-new draft cart (same customer/store/invoice type), leaving
// the remainder on the original line.
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
    newCart.invoice_type = form.invoice_type;
    newCart.lines = [{ ...line, quantity: qty }];
    carts.value.push(newCart);

    closeSplitModal();
    toast({ message: `Split ${formatQuantity(qty)} into a new cart.`, variant: 'success' });
}

// --- Merge another cart tab into the active one -----------------------------
const mergeModalOpen = ref(false);

function openMergeModal() {
    mergeModalOpen.value = true;
}

function closeMergeModal() {
    mergeModalOpen.value = false;
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
    toast({ message: 'Carts held — safe if you refresh or close this tab.', variant: 'success' });
}

watch(() => [carts.value, form.lines, form.customer_id], () => schedulePersist(), { deep: true });

// --- Customer picker + quick "+ New customer" -----------------------------
// Reuses the existing POST /customers endpoint exactly as
// Tenant/Parties/Customers/Index.vue does (same fields, same route) - it
// always redirects to the customers index on success, so this bounces back
// to /pos and best-effort auto-selects the new customer by matching
// name/mobile against the freshly reloaded customers list (there is no id
// returned to us directly, since the endpoint replies with a redirect, not
// JSON).
const customerModalOpen = ref(false);
const customerFieldWrapper = ref(null);
const customerForm = useForm({ name: '', mobile_no: '' });

function openCustomerModal() {
    customerForm.reset();
    customerForm.clearErrors();
    customerModalOpen.value = true;
}

function closeCustomerModal() {
    customerModalOpen.value = false;
    customerForm.reset();
    customerForm.clearErrors();
}

function submitCustomer() {
    const pending = { name: customerForm.name, mobile_no: customerForm.mobile_no };

    customerForm.post('/customers', {
        onSuccess: () => {
            sessionStorage.setItem(PENDING_CUSTOMER_KEY, JSON.stringify(pending));
            customerModalOpen.value = false;
            router.visit('/pos', { onSuccess: applyPendingCustomer });
        },
    });
}

function applyPendingCustomer() {
    const raw = sessionStorage.getItem(PENDING_CUSTOMER_KEY);
    if (!raw) return;
    sessionStorage.removeItem(PENDING_CUSTOMER_KEY);

    try {
        const pending = JSON.parse(raw);
        const matches = props.customers.filter(
            (c) => c.name === pending.name && (pending.mobile_no ? c.mobile_no === pending.mobile_no : true),
        );
        const match = matches.sort((a, b) => b.id - a.id)[0];
        if (match) form.customer_id = match.id;
        toast({ message: 'Customer added.', variant: 'success' });
    } catch {
        // malformed sessionStorage payload - nothing to recover, ignore.
    }
}

// --- Sale submission + receipt confirmation ---------------------------
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
    activeTarget.value = null;
    scanQuery.value = '';
    syncActiveCartFromForm();
    // The posted cart must not survive in localStorage: the audit found the
    // print step throwing before any reset ran, leaving a cart that had
    // already been billed sitting on screen ready to be billed again (P0-6).
    persistCartsNow();
}

function completeSale() {
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

            if (created?.print_url) window.open(created.print_url, '_blank');

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

/** Change owed on the completed sale: cash tendered less the amount settled. */
const receiptChange = computed(() => {
    if (!receipt.value) return '0.00';

    const over = subtractMoney(receipt.value.tendered_cash ?? '0.00', receipt.value.cash_settled ?? '0.00');

    return compareMoney(over, '0.00') > 0 ? over : '0.00';
});

function closeReceipt() {
    receiptOpen.value = false;
    receipt.value = null;
}

const canSubmit = computed(() => {
    if (form.processing || !form.customer_id || !form.date || form.lines.length === 0) return false;
    if (!totals.value) return false;

    const mode = resolvedPaymentMode.value;
    if ((mode === 'bank' || mode === 'partial') && !form.bank_account_id) return false;
    if (mode === 'partial' && !paymentBalanced.value) return false;

    return true;
});

// --- Keyboard shortcuts ----------------------------------------------------
// Global while this page is mounted. Bindings replicate the legacy POS's
// F-key scheme (docs/pos_user_manual.md): F1 help, F2/F7 search/scan
// (legacy splits these across a search box and a separate barcode box; this
// page uses one combined field for both, so both keys focus it), F4
// customer, F8 save & print (this page's closest equivalent is completing
// the sale and showing its receipt), F9 hold, Esc clear. Legacy's "new
// cart"/"+" action has no dedicated hotkey there either - it stays a
// mouse-only tab-strip button here too.
const shortcutsOpen = ref(false);

const shortcutList = [
    { key: 'F1', description: 'Open this shortcuts help' },
    { key: 'F2 / F7', description: 'Focus item search / scan field' },
    { key: 'F4', description: 'Focus customer field' },
    { key: 'F8', description: 'Complete sale' },
    { key: 'F9', description: 'Hold carts (saved to this browser)' },
    { key: 'Esc', description: 'Clear focus' },
];

function onGlobalKeydown(event) {
    if (customerModalOpen.value || receiptOpen.value || shortcutsOpen.value || splitModalOpen.value || mergeModalOpen.value) {
        return;
    }

    if (event.key === 'F1') {
        event.preventDefault();
        shortcutsOpen.value = true;
        return;
    }

    if (event.key === 'F2' || event.key === 'F7') {
        event.preventDefault();
        scanFieldWrapper.value?.querySelector('input')?.focus();
        return;
    }

    if (event.key === 'F4') {
        event.preventDefault();
        customerFieldWrapper.value?.querySelector('input')?.focus();
        return;
    }

    if (event.key === 'F8') {
        event.preventDefault();
        if (canSubmit.value) completeSale();
        return;
    }

    if (event.key === 'F9') {
        event.preventDefault();
        holdCarts();
        return;
    }

    if (event.key === 'Escape' && document.activeElement instanceof HTMLElement) {
        document.activeElement.blur();
    }
}

// --- Focus mode / full screen ------------------------------------------------
// Toggles two things together, closest this Inertia-page-inside-AppLayout
// setup can get to the legacy POS's dedicated full-viewport app shell:
//  1. `fullscreen` on AppLayout, which hides Day Khata's own sidebar and top
//     navbar so the POS content fills the whole viewport (the actual point
//     of this toggle).
//  2. The browser Fullscreen API, best-effort - some embedding contexts
//     (iframes without `allow="fullscreen"`, some browsers) reject it, which
//     is fine; the chrome-hiding above still works either way.
// `isFullscreen` drives both, so it's set directly on click rather than only
// from the `fullscreenchange` event - but that event still syncs it back to
// false if the browser exits native full screen on its own (e.g. the user
// presses the native Esc-to-exit prompt), which also exits focus mode.
const isFullscreen = ref(false);

function toggleFullscreen() {
    isFullscreen.value = !isFullscreen.value;

    if (isFullscreen.value) {
        document.documentElement.requestFullscreen?.().catch(() => {
            // Fullscreen API unavailable/denied - chrome-hiding above still
            // applies, so this isn't fatal to the feature.
        });
    } else if (document.fullscreenElement) {
        document.exitFullscreen?.();
    }
}

function onFullscreenChange() {
    if (!document.fullscreenElement) {
        isFullscreen.value = false;
    }
}

onMounted(() => {
    restoreCartsFromStorage();
    applyPendingCustomer();
    applyPendingReceipt();
    window.addEventListener('keydown', onGlobalKeydown);
    document.addEventListener('fullscreenchange', onFullscreenChange);
});

onUnmounted(() => {
    window.removeEventListener('keydown', onGlobalKeydown);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
});
</script>

<template>
    <AppLayout title="POS" :nav-items="navItems" :fullscreen="isFullscreen">
        <div class="flex flex-col gap-3">
            <!-- Cart tabs + toolbar -->
            <div class="flex items-center gap-2 overflow-x-auto pb-1">
                <button
                    v-for="(cart, index) in carts"
                    :key="cart.id"
                    type="button"
                    class="flex shrink-0 items-center gap-2 border-[1.5px] px-3 py-1.5 text-xs font-bold whitespace-nowrap transition-colors duration-150"
                    :class="
                        index === activeCartIndex
                            ? 'border-primary bg-primary-tint text-primary'
                            : 'border-border bg-bg-subtle text-text-muted hover:text-text-base'
                    "
                    @click="switchToCart(index)"
                >
                    <span>{{ cartLabel(cart, index) }}</span>
                    <span v-if="cartLineCount(cart, index) > 0" class="text-[10px] font-semibold opacity-70">
                        ({{ cartLineCount(cart, index) }})
                    </span>
                    <X
                        v-if="carts.length > 1"
                        class="h-3 w-3 text-text-faint hover:text-danger"
                        @click.stop="closeCart(index)"
                    />
                </button>
                <button
                    type="button"
                    class="flex shrink-0 items-center gap-1 border-[1.5px] border-dashed border-border px-3 py-1.5 text-xs font-bold text-text-muted hover:border-primary hover:text-primary"
                    @click="addCart"
                >
                    <Plus class="h-3.5 w-3.5" /> New sale
                </button>

                <div class="ml-auto flex shrink-0 items-center gap-2">
                    <Tooltip label="Merge another cart into this one">
                        <button
                            type="button"
                            class="flex h-7 w-7 items-center justify-center border-[1.5px] border-border bg-bg-subtle text-text-muted hover:border-primary hover:text-primary"
                            @click="openMergeModal"
                        >
                            <Merge class="h-3.5 w-3.5" />
                        </button>
                    </Tooltip>
                    <Tooltip label="Hold carts (F9)">
                        <button
                            type="button"
                            class="flex h-7 w-7 items-center justify-center border-[1.5px] border-border bg-bg-subtle text-text-muted hover:border-primary hover:text-primary"
                            @click="holdCarts"
                        >
                            <CirclePause class="h-3.5 w-3.5" />
                        </button>
                    </Tooltip>
                    <Tooltip :label="isFullscreen ? 'Exit full screen' : 'Full screen'">
                        <button
                            type="button"
                            class="flex h-7 w-7 items-center justify-center border-[1.5px] border-border bg-bg-subtle text-text-muted hover:border-primary hover:text-primary"
                            @click="toggleFullscreen"
                        >
                            <Minimize2 v-if="isFullscreen" class="h-3.5 w-3.5" />
                            <Maximize2 v-else class="h-3.5 w-3.5" />
                        </button>
                    </Tooltip>
                    <Tooltip label="Keyboard shortcuts (F1)">
                        <button
                            type="button"
                            class="flex h-7 w-7 items-center justify-center border-[1.5px] border-border bg-bg-subtle text-text-muted hover:border-primary hover:text-primary"
                            @click="shortcutsOpen = true"
                        >
                            <CircleHelp class="h-3.5 w-3.5" />
                        </button>
                    </Tooltip>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 lg:grid-cols-[1fr_420px]">
                <!-- LEFT — product browser -->
                <div class="flex min-w-0 flex-col gap-3">
                    <Card variant="panel">
                        <div ref="scanFieldWrapper">
                            <Input
                                v-model="scanQuery"
                                type="text"
                                placeholder="Search or scan an item… (F2)"
                                :icon="ScanBarcode"
                                @keydown="onScanKeydown"
                            />
                        </div>
                    </Card>

                    <div v-if="categories.length" class="flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            class="border-[1.5px] px-2.5 py-1 text-xs font-bold transition-colors duration-150"
                            :class="
                                activeCategoryId === null
                                    ? 'border-primary bg-primary-tint text-primary'
                                    : 'border-border bg-bg-subtle text-text-muted hover:text-text-base'
                            "
                            @click="activeCategoryId = null"
                        >
                            All
                        </button>
                        <button
                            v-for="category in categories"
                            :key="category.id"
                            type="button"
                            class="border-[1.5px] px-2.5 py-1 text-xs font-bold transition-colors duration-150"
                            :class="
                                activeCategoryId === category.id
                                    ? 'border-primary bg-primary-tint text-primary'
                                    : 'border-border bg-bg-subtle text-text-muted hover:text-text-base'
                            "
                            @click="selectCategory(category.id)"
                        >
                            {{ category.name }}
                        </button>
                    </div>

                    <div class="grid grid-cols-[repeat(auto-fill,minmax(112px,1fr))] gap-2.5">
                        <Card
                            v-for="item in visibleItems"
                            :key="item.id"
                            variant="product"
                            class="relative"
                            :class="isOutOfStock(item) ? 'pointer-events-none opacity-50' : 'cursor-pointer select-none'"
                            @click="addToCart(item)"
                        >
                            <Badge :variant="item.is_vatable ? 'tax' : 'free'" class="absolute top-1.5 right-1.5">
                                {{ item.is_vatable ? 'TAX' : 'FREE' }}
                            </Badge>
                            <img
                                v-if="item.image_path"
                                :src="`/storage/${item.image_path}`"
                                :alt="item.name"
                                class="mb-2 h-16 w-full rounded-none border-[1.5px] border-border object-cover"
                            />
                            <div
                                v-else
                                class="mb-2 flex h-16 w-full items-center justify-center border-[1.5px] border-border bg-bg-subtle text-text-faint"
                            >
                                <Package class="h-6 w-6" />
                            </div>
                            <p class="text-sm font-bold text-text-strong">{{ item.name }}</p>
                            <p class="mt-1 text-xs text-text-muted">{{ item.unit }}</p>
                            <p v-if="item.sale_rate != null" class="mt-1 text-xs font-semibold text-text-base">
                                {{ formatRate(item.sale_rate) }}
                            </p>
                            <p v-if="item.is_stockable" class="mt-1 text-[10px] text-text-faint">
                                Stock: {{ formatQuantity(item.current_stock) }}
                            </p>
                            <p v-if="hasQuantityInCart(item.id)" class="mt-2 text-xs font-bold text-primary">
                                {{ formatQuantity(quantityInCart(item.id)) }} in cart
                            </p>
                        </Card>
                        <p v-if="filteredItems.length === 0" class="col-span-full text-sm text-text-faint">No items match.</p>
                    </div>
                    <p v-if="filteredItems.length > ITEM_TILE_CAP" class="text-xs text-text-faint">
                        Showing {{ visibleItems.length }} of {{ filteredItems.length }} items — refine your search to see more.
                    </p>
                </div>

                <!-- RIGHT — cart & checkout -->
                <div class="flex min-w-0 flex-col gap-3">
                    <Card variant="panel" title="Customer">
                        <div class="flex gap-2">
                            <div ref="customerFieldWrapper" class="flex-1">
                                <Combobox
                                    :model-value="form.customer_id"
                                    :options="customerOptions"
                                    placeholder="Select customer… (F4)"
                                    @update:model-value="(v) => (form.customer_id = v)"
                                />
                            </div>
                            <Button variant="secondary" tone="purple" type="button" @click="openCustomerModal">
                                <Plus class="h-3.5 w-3.5" /> New
                            </Button>
                        </div>
                        <p v-if="form.errors.customer_id" class="mt-1 text-sm text-danger">{{ form.errors.customer_id }}</p>
                    </Card>

                    <Card variant="panel" title="Cart" class="max-h-[360px] overflow-y-auto">
                        <p v-if="form.lines.length === 0" class="text-sm text-text-faint">Tap an item to add it, or scan a barcode.</p>
                        <div v-for="(line, index) in form.lines" :key="index" class="mb-2 flex flex-col gap-1.5 border-b border-border pb-2 last:mb-0 last:border-0">
                            <div class="flex items-center gap-2">
                                <p class="min-w-0 flex-1 truncate text-sm font-semibold text-text-strong">
                                    {{ itemsById[line.item_id]?.name ?? 'Item' }}
                                </p>
                                <Badge :variant="itemsById[line.item_id]?.is_vatable ? 'tax' : 'free'">
                                    {{ itemsById[line.item_id]?.is_vatable ? 'TAX' : 'FREE' }}
                                </Badge>
                                <Tooltip label="Split into a new cart">
                                    <button
                                        type="button"
                                        class="flex h-6 w-6 items-center justify-center text-text-muted hover:text-primary"
                                        @click="openSplitModal(index)"
                                    >
                                        <SplitSquareHorizontal class="h-3.5 w-3.5" />
                                    </button>
                                </Tooltip>
                                <button
                                    type="button"
                                    class="flex h-6 w-6 items-center justify-center text-text-muted hover:text-danger"
                                    aria-label="Remove line"
                                    @click="removeLine(index)"
                                >
                                    <X class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <p v-if="form.errors[`lines.${index}.item_id`]" class="text-xs text-danger">
                                {{ form.errors[`lines.${index}.item_id`] }}
                            </p>
                            <div class="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    class="flex h-6 w-6 shrink-0 items-center justify-center bg-bg-subtle text-text-muted hover:text-primary"
                                    @click="decrementQty(index)"
                                >
                                    <Minus class="h-3 w-3" />
                                </button>
                                <Input
                                    v-model="line.quantity"
                                    type="number"
                                    min="0"
                                    step="0.0001"
                                    placeholder="Qty"
                                    class="w-14 text-center"
                                    @focusin="focusTarget('quantity', index)"
                                    @blur="warnIfOverstock(line.item_id)"
                                />
                                <button
                                    type="button"
                                    class="flex h-6 w-6 shrink-0 items-center justify-center bg-bg-subtle text-text-muted hover:text-primary"
                                    @click="incrementQty(index)"
                                >
                                    <Plus class="h-3 w-3" />
                                </button>
                                <Input
                                    v-model="line.rate"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    placeholder="Rate"
                                    class="w-16 text-center"
                                    @focusin="focusTarget('rate', index)"
                                />
                                <Input
                                    v-model="line.discount"
                                    type="number"
                                    min="0"
                                    :max="line.discountType === 'percent' ? 100 : undefined"
                                    :placeholder="line.discountType === 'percent' ? '%' : 'Rs'"
                                    class="w-14 text-center"
                                />
                                <button
                                    type="button"
                                    class="flex h-6 w-8 shrink-0 items-center justify-center border-[1.5px] border-border bg-bg-subtle text-[10px] font-bold text-text-muted hover:border-primary hover:text-primary"
                                    title="Click to switch between % and Rs discount"
                                    @click="toggleLineDiscountType(index)"
                                >
                                    {{ line.discountType === 'percent' ? '%' : 'Rs' }}
                                </button>
                                <span class="ml-auto shrink-0 text-xs font-bold text-text-strong">
                                    {{ lineTotal(index) === null ? '—' : formatMoney(lineTotal(index)) }}
                                </span>
                            </div>
                        </div>
                    </Card>

                    <!-- Numpad -->
                    <Card variant="panel">
                        <p class="mb-2 text-xs font-semibold text-text-muted">{{ activeTargetLabel }}</p>
                        <div class="grid grid-cols-3 gap-1.5">
                            <button
                                v-for="digit in ['7', '8', '9', '4', '5', '6', '1', '2', '3', '.', '0']"
                                :key="digit"
                                type="button"
                                class="border-[1.5px] border-border bg-bg-subtle py-2 text-sm font-bold text-text-base hover:bg-primary-tint hover:text-primary"
                                @click="pressDigit(digit)"
                            >
                                {{ digit }}
                            </button>
                            <button
                                type="button"
                                class="flex items-center justify-center border-[1.5px] border-border bg-bg-subtle py-2 text-text-base hover:bg-danger-bg hover:text-danger"
                                aria-label="Backspace"
                                @click="pressBackspace"
                            >
                                <Delete class="h-4 w-4" />
                            </button>
                        </div>
                        <Button variant="secondary" tone="purple" type="button" class="mt-1.5 w-full justify-center" @click="pressClear">
                            Clear
                        </Button>
                    </Card>

                    <!-- Payment -->
                    <Card variant="panel">
                        <div class="mb-2 flex items-center justify-between">
                            <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Payment</p>
                            <div class="flex gap-1.5">
                                <Button variant="secondary" tone="blue" type="button" class="!px-2.5 !py-1 !text-[11px]" @click="quickPayFullCash">
                                    Full Cash
                                </Button>
                                <Button variant="secondary" tone="purple" type="button" class="!px-2.5 !py-1 !text-[11px]" @click="quickPayFullBank">
                                    Full Bank
                                </Button>
                                <Button variant="secondary" tone="danger" type="button" class="!px-2.5 !py-1 !text-[11px]" @click="quickPayReset">
                                    Reset
                                </Button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-sm font-semibold text-text-base">Cash paid</label>
                                <Input
                                    v-model="form.cash_amount"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    placeholder="0.00"
                                    @focusin="activeTarget = { type: 'cash' }"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-semibold text-text-base">Bank / QR paid</label>
                                <Input v-model="form.bank_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                            </div>
                        </div>

                        <div v-if="showBankAccountField" class="mt-3">
                            <label class="mb-1 block text-sm font-semibold text-text-base">Bank account</label>
                            <Combobox
                                :model-value="form.bank_account_id"
                                :options="bankAccountOptions"
                                placeholder="Select bank account"
                                @update:model-value="(v) => (form.bank_account_id = v)"
                            />
                            <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                        </div>

                        <div v-if="hasDue" class="mt-3 flex items-center justify-between bg-warning-bg px-3 py-2 text-xs font-bold text-warning-text">
                            <span>Due</span>
                            <span>{{ formatMoney(dueAmount) }}</span>
                        </div>
                        <div v-else-if="hasChange" class="mt-3 flex items-center justify-between bg-success-bg px-3 py-2 text-xs font-bold text-success">
                            <span>Change to return</span>
                            <span>{{ formatMoney(changeAmount) }}</span>
                        </div>
                        <p v-if="resolvedPaymentMode === 'partial' && !paymentBalanced" class="mt-1 text-xs font-semibold text-danger">
                            Cash + bank must add up to exactly {{ settlementDue ? formatMoney(settlementDue) : '—' }} for a split payment.
                        </p>

                        <button type="button" class="mt-3 text-xs font-semibold text-primary" @click="showAdvanced = !showAdvanced">
                            {{ showAdvanced ? 'Hide' : 'Show' }} more options
                        </button>

                        <div v-if="showAdvanced" class="mt-3 flex flex-col gap-3 border-t border-border pt-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-text-base">Invoice type</label>
                                    <Select v-model="form.invoice_type" :options="invoiceTypeOptions" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-text-base">Date</label>
                                    <NepaliDateInput v-model="form.date" required />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-text-base">Store</label>
                                    <Combobox
                                        :model-value="form.store_id"
                                        :options="storeOptions"
                                        placeholder="Default store"
                                        @update:model-value="(v) => (form.store_id = v)"
                                    />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-text-base">Header discount</label>
                                    <div class="flex gap-1.5">
                                        <Input
                                            v-model="form.discount"
                                            type="number"
                                            min="0"
                                            :max="form.discount_type === 'percent' ? 100 : undefined"
                                            :placeholder="form.discount_type === 'percent' ? '%' : '0.00'"
                                        />
                                        <button
                                            type="button"
                                            class="flex h-9 w-9 shrink-0 items-center justify-center border-[1.5px] border-border bg-bg-subtle text-[10px] font-bold text-text-muted hover:border-primary hover:text-primary"
                                            title="Click to switch between % and Rs discount"
                                            @click="toggleHeaderDiscountType"
                                        >
                                            {{ form.discount_type === 'percent' ? '%' : 'Rs' }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-text-base">VAT rate (%)</label>
                                    <!-- Read-only: the server always uses the
                                         tenant's configured rate, and a PAN
                                         invoice carries no VAT at all. -->
                                    <p class="flex h-9 items-center border-[1.5px] border-border bg-bg-subtle px-3 text-[13px] font-semibold text-text-muted">
                                        {{ formatRate(effectiveVatRate) }}
                                        <span v-if="isPanInvoice" class="ml-2 text-xs font-normal">(no VAT)</span>
                                    </p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-text-base">TDS amount</label>
                                    <Input v-model="form.tds_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-text-base">TDS account</label>
                                    <Combobox
                                        :model-value="form.tds_account_id"
                                        :options="tdsAccountOptions"
                                        placeholder="Optional"
                                        @update:model-value="(v) => (form.tds_account_id = v)"
                                    />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-text-base">Chalani number</label>
                                    <Input v-model="form.chalani_number" type="text" placeholder="Optional" />
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                                <Input v-model="form.narration" type="text" placeholder="Optional" />
                            </div>
                        </div>
                    </Card>

                    <!-- Totals + submit -->
                    <Card variant="panel">
                        <div v-if="totals" class="grid grid-cols-2 gap-2 text-sm">
                            <p class="text-text-muted">Taxable</p>
                            <p class="text-right font-semibold text-text-strong">{{ formatMoney(totals.taxable_amount) }}</p>
                            <p class="text-text-muted">Non-taxable</p>
                            <p class="text-right font-semibold text-text-strong">{{ formatMoney(totals.nontaxable_amount) }}</p>
                            <p class="text-text-muted">VAT</p>
                            <p class="text-right font-semibold text-text-strong">{{ formatMoney(totals.vat_amount) }}</p>
                            <p class="text-base font-bold text-text-strong">Grand total</p>
                            <p class="text-right text-base font-bold text-primary">{{ formatMoney(totals.total) }}</p>
                        </div>
                        <p v-if="previewError" class="text-sm text-danger">{{ previewError }}</p>
                        <p v-if="form.errors.lines" class="mt-2 text-sm text-danger">{{ form.errors.lines }}</p>
                        <p v-if="form.errors.expected_total" class="mt-2 text-sm text-danger">{{ form.errors.expected_total }}</p>
                        <Button variant="primary" tone="purple" type="button" class="mt-3 w-full justify-center" :disabled="!canSubmit" @click="completeSale">
                            Complete sale (F8)
                        </Button>
                    </Card>
                </div>
            </div>
        </div>

        <!-- Quick "+ New customer" -->
        <Modal :open="customerModalOpen" title="New customer" size="compact" @update:open="(v) => (v ? null : closeCustomerModal())">
            <form class="flex flex-col gap-4" @submit.prevent="submitCustomer">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input v-model="customerForm.name" type="text" placeholder="e.g. Ram Sharma" required />
                    <p v-if="customerForm.errors.name" class="mt-1 text-sm text-danger">{{ customerForm.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Mobile No</label>
                    <Input v-model="customerForm.mobile_no" type="text" placeholder="98XXXXXXXX" />
                    <p v-if="customerForm.errors.mobile_no" class="mt-1 text-sm text-danger">{{ customerForm.errors.mobile_no }}</p>
                </div>
            </form>
            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeCustomerModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="customerForm.processing" @click="submitCustomer">
                    Create customer
                </Button>
            </template>
        </Modal>

        <!-- Split a cart line into a new cart -->
        <Modal :open="splitModalOpen" title="Split into a new cart" size="compact" @update:open="(v) => (v ? null : closeSplitModal())">
            <div v-if="splitLineIndex !== null" class="flex flex-col gap-3 text-sm">
                <p class="text-text-muted">
                    {{ itemsById[form.lines[splitLineIndex]?.item_id]?.name ?? 'Item' }} — current quantity
                    {{ form.lines[splitLineIndex]?.quantity }}
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Quantity to move to a new cart</label>
                    <Input v-model="splitQuantity" type="number" min="0" step="0.0001" placeholder="e.g. 1" />
                </div>
            </div>
            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeSplitModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="button" @click="confirmSplit">Split</Button>
            </template>
        </Modal>

        <!-- Merge another cart into the active one -->
        <Modal :open="mergeModalOpen" title="Merge a cart" size="compact" @update:open="(v) => (v ? null : closeMergeModal())">
            <div class="flex flex-col gap-1">
                <p v-if="carts.length === 1" class="py-2 text-center text-sm text-text-faint">No other carts to merge.</p>
                <template v-else>
                    <div
                        v-for="(cart, index) in carts"
                        v-show="index !== activeCartIndex"
                        :key="cart.id"
                        class="flex items-center justify-between gap-3 border-b border-border py-2 last:border-0"
                    >
                        <p class="text-sm font-semibold text-text-strong">
                            {{ cartLabel(cart, index) }}
                            <span class="font-normal text-text-muted">({{ cartLineCount(cart, index) }} item{{ cartLineCount(cart, index) === 1 ? '' : 's' }})</span>
                        </p>
                        <Button variant="secondary" tone="purple" type="button" class="!px-2.5 !py-1 !text-[11px]" @click="mergeCartInto(index)">
                            Merge in
                        </Button>
                    </div>
                </template>
            </div>
            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeMergeModal">Close</Button>
            </template>
        </Modal>

        <!-- Receipt confirmation (frontend-only - no backend receipt endpoint) -->
        <Modal :open="receiptOpen" title="Sale complete" size="compact" @update:open="(v) => (v ? null : closeReceipt())">
            <!-- Every figure below is the stored sale the server returned
                 (C8: posted documents always render stored server values), not
                 a client-side snapshot of what the cart looked like. The only
                 exception is the change, which is cash tendered at the counter
                 and never part of the bill. -->
            <div v-if="receipt" class="flex flex-col gap-3 text-sm">
                <div class="flex justify-between text-text-muted">
                    <span>{{ receipt.customer_name }}</span>
                    <span>{{ receipt.invoice_number }}</span>
                </div>
                <div class="flex justify-between text-text-muted">
                    <span>{{ receipt.date_bs }} (BS)</span>
                    <span>{{ receipt.date }}</span>
                </div>
                <div class="border-t border-border pt-2">
                    <div v-for="(line, i) in receipt.lines" :key="i" class="flex justify-between py-0.5">
                        <span>{{ line.name }} × {{ formatQuantity(line.quantity) }} {{ line.unit }}</span>
                        <span class="font-semibold">{{ formatMoney(line.line_total) }}</span>
                    </div>
                </div>
                <div class="border-t border-border pt-2">
                    <div class="flex justify-between text-base font-bold text-text-strong">
                        <span>Total</span>
                        <span>{{ formatMoney(receipt.total) }}</span>
                    </div>
                    <div class="mt-1 flex justify-between text-text-muted">
                        <span>Cash settled</span>
                        <span>{{ formatMoney(receipt.cash_settled) }}</span>
                    </div>
                    <div class="flex justify-between text-text-muted">
                        <span>Bank settled</span>
                        <span>{{ formatMoney(receipt.bank_settled) }}</span>
                    </div>
                    <div class="flex justify-between font-semibold text-warning-text">
                        <span>Outstanding</span>
                        <span>{{ formatMoney(receipt.outstanding) }}</span>
                    </div>
                    <div class="flex justify-between font-semibold text-success">
                        <span>Change</span>
                        <span>{{ formatMoney(receiptChange) }}</span>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button variant="primary" tone="purple" type="button" @click="closeReceipt">New sale</Button>
            </template>
        </Modal>

        <!-- Keyboard shortcuts help -->
        <Modal :open="shortcutsOpen" title="Keyboard shortcuts" size="compact" @update:open="(v) => (shortcutsOpen = v)">
            <div class="flex flex-col gap-2 text-sm">
                <div v-for="shortcut in shortcutList" :key="shortcut.key" class="flex items-center justify-between gap-4">
                    <span class="text-text-muted">{{ shortcut.description }}</span>
                    <kbd class="border-[1.5px] border-border bg-bg-subtle px-1.5 py-0.5 text-xs font-bold whitespace-nowrap text-text-strong">
                        {{ shortcut.key }}
                    </kbd>
                </div>
            </div>
            <template #footer>
                <Button variant="primary" tone="purple" type="button" @click="shortcutsOpen = false">Close</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
