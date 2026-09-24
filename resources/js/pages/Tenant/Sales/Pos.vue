<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronLeft,
    CircleHelp,
    CirclePause,
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
import { useLayoutChrome } from '@/composables/useLayoutChrome';
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
import { useConfirm } from '@/composables/useConfirm';
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
    rateExcludingVat,
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

const page = usePage();
const { toast } = useToast();
const layoutChrome = useLayoutChrome('POS');
const { confirm } = useConfirm();

const PENDING_RECEIPT_KEY = 'pos-last-receipt';
const PENDING_CUSTOMER_KEY = 'pos-pending-customer';
const CARTS_STORAGE_KEY = 'day-khata:pos-carts';

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

const showAdvanced = ref(false);

// --- Item search + separate barcode scan input --------------------------
// Legacy (new-pos.blade.php) keeps these as two distinct always-visible
// boxes rather than one combined field: a search box that live-filters the
// tile grid by name, and a dedicated barcode box a scanner's Enter-terminated
// input goes into, which does an exact-match lookup and adds straight to the
// cart without touching the grid filter at all.
const searchQuery = ref('');
const searchFieldWrapper = ref(null);
const barcodeQuery = ref('');
const barcodeFieldWrapper = ref(null);

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

/**
 * A quantity box as the server wants it: the typed string untouched, or '0'
 * when the box is empty or was never present (a cart restored from an older
 * localStorage payload has no bonus field at all). Never a number - the
 * string goes straight into Quantity::of() server-side (C1).
 */
function enteredQuantity(value) {
    return value === '' || value === null || value === undefined ? '0' : String(value);
}

// --- Totals: one preview, identical to the server's calculator -------------
// Every figure on this screen comes from calculateDocument(), the exact
// mirror of App\Support\Billing\DocumentCalculator (CONTRACTS C3/C8). The
// audit found quick-pay filling 56.49 against a bill the server booked at
// 56.50, leaving the drawer a paisa short on every sale (P0-8).

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
    // The free units stay with the line they were entered on: splitting a
    // paid quantity in two must not hand the customer twice the bonus stock,
    // and splitting bonus units proportionally would need a division nobody
    // asked for. The cashier can retype the bonus on either cart.
    newCart.lines = [{ ...line, quantity: qty, bonus_quantity: '' }];
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

/** Plain-words reason Complete sale is disabled, or '' when it can proceed. */
const submitBlockedReason = computed(() => {
    if (form.processing) return '';
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
    if (form.processing || !form.customer_id || !form.date || form.lines.length === 0) return false;
    if (!totals.value) return false;

    const mode = resolvedPaymentMode.value;
    if ((mode === 'bank' || mode === 'partial') && !form.bank_account_id) return false;
    if (mode === 'partial' && !paymentBalanced.value) return false;

    return true;
});

// --- Keyboard shortcuts ----------------------------------------------------
// Global while this page is mounted. Matches legacy's F-key scheme exactly
// (docs/pos_user_manual.md): F1 help, F2 search, F7 barcode (now genuinely
// separate fields, see searchQuery/barcodeQuery above), F4 customer, F8
// save & print, F9 hold, Esc clear. Legacy's "new cart"/"+" action has no
// dedicated hotkey there either - it stays a mouse-only tab-strip button here
// too.
const shortcutsOpen = ref(false);

const shortcutList = [
    { key: 'F1', description: 'Open this shortcuts help' },
    { key: 'F2', description: 'Focus product search' },
    { key: 'F7', description: 'Focus barcode scan' },
    { key: 'F4', description: 'Focus customer field' },
    { key: 'F8', description: 'Save & print' },
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

    if (event.key === 'F2') {
        event.preventDefault();
        searchFieldWrapper.value?.querySelector('input')?.focus();
        return;
    }

    if (event.key === 'F7') {
        event.preventDefault();
        barcodeFieldWrapper.value?.querySelector('input')?.focus();
        return;
    }

    if (event.key === 'F4') {
        event.preventDefault();
        customerFieldWrapper.value?.querySelector('input')?.focus();
        return;
    }

    if (event.key === 'F8') {
        event.preventDefault();
        if (canSubmit.value) completeSale('print');
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
    window.addEventListener('keydown', onGlobalKeydown);
    document.addEventListener('fullscreenchange', onFullscreenChange);
});

onUnmounted(() => {
    window.removeEventListener('keydown', onGlobalKeydown);
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
        <!-- Top bar: always-visible dashboard exit, FY, help, date, invoice type, hints -->
        <div class="pos-bar flex shrink-0 flex-col lg:flex-row">
          <div class="pos-bar-section pos-bar-section--products flex items-center gap-2.5 overflow-x-auto">
            <Link href="/dashboard" aria-label="Back to dashboard" class="pos-bar-btn">
                <ChevronLeft class="h-4 w-4" /> <span class="hidden sm:inline">Dashboard</span>
            </Link>
            <div class="pos-bar-divider" />
            <span
                class="pos-fy-badge"
                :title="currentFiscalYear ? 'Current fiscal year' : 'No fiscal year is set up for this date - ask an admin to create one before selling.'"
            >
                FY: {{ currentFiscalYear || 'Not set' }}
            </span>
            <div class="pos-bar-divider" />
            <button type="button" class="pos-bar-btn" @click="shortcutsOpen = true">
                <CircleHelp class="h-4 w-4" /> <span class="hidden sm:inline">Shortcuts</span> <kbd class="pos-bar-kbd">F1</kbd>
            </button>
            <div class="pos-bar-divider" />
            <div class="pos-date-box">
                <label>DATE</label>
                <NepaliDateInput v-model="form.date" required class="pos-date-input" />
            </div>
            <div class="pos-bar-divider" />
            <span class="pos-bill-badge" :title="isPanInvoice ? 'PAN invoices (no VAT) - set by your admin' : 'Tax invoices (VAT) - set by your admin'">
                {{ isPanInvoice ? 'PAN' : 'TAX' }} invoice
            </span>

            <Tooltip :label="isFullscreen ? 'Exit full screen' : 'Full screen'">
                <button type="button" :aria-label="isFullscreen ? 'Exit full screen' : 'Full screen'" class="pos-bar-btn pos-bar-btn--icon" @click="toggleFullscreen">
                    <Minimize2 v-if="isFullscreen" class="h-4 w-4" />
                    <Maximize2 v-else class="h-4 w-4" />
                </button>
            </Tooltip>
          </div>

          <!-- Draft-cart tabs: sit over the cart column, product tools over the product column -->
          <div class="pos-bar-section pos-bar-section--carts flex items-end gap-1.5 overflow-x-auto">
            <button
                v-for="(cart, index) in carts"
                :key="cart.id"
                type="button"
                class="pos-cart-tab flex shrink-0 cursor-pointer items-center gap-2 px-3.5 text-xs font-bold whitespace-nowrap transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary"
                :class="index === activeCartIndex ? 'pos-cart-tab--active' : 'pos-cart-tab--idle'"
                :aria-pressed="index === activeCartIndex"
                @click="switchToCart(index)"
            >
                <span>{{ cartLabel(cart, index) }}</span>
                <span v-if="cartLineCount(cart, index) > 0" class="text-[10px] font-semibold opacity-70">
                    ({{ cartLineCount(cart, index) }})
                </span>
                <span
                    v-if="carts.length > 1"
                    role="button"
                    tabindex="0"
                    :aria-label="`Cancel held sale ${cartLabel(cart, index)}`"
                    title="Cancel this held sale"
                    class="flex h-6 w-6 cursor-pointer items-center justify-center opacity-60 hover:opacity-100 focus-visible:outline-2 focus-visible:outline-primary"
                    @click.stop="confirmCloseCart(index)"
                    @keydown.enter.stop.prevent="confirmCloseCart(index)"
                >
                    <X class="h-3.5 w-3.5" />
                </span>
            </button>
            <Tooltip label="Merge another cart into this one">
                <button type="button" aria-label="Merge another cart into this one" class="pos-draft-btn pos-draft-btn--merge" @click="openMergeModal">
                    <Merge class="h-4 w-4" />
                </button>
            </Tooltip>
            <button type="button" aria-label="New sale (F9 holds the current one first)" class="pos-draft-btn" @click="addCart">
                <Plus class="h-4 w-4" />
            </button>
          </div>
        </div>

        <div class="pos-body flex min-h-0 flex-1 flex-col gap-3 lg:flex-row lg:gap-0">
            <div class="pos-left flex min-w-0 shrink-0 flex-col gap-3 p-3 lg:h-full lg:min-h-0 lg:shrink lg:overflow-hidden">
                <div class="flex shrink-0 gap-2">
                    <div ref="searchFieldWrapper" class="relative min-w-0 flex-1">
                        <Input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search products… (F2)"
                            aria-label="Search products (F2)"
                            :icon="ScanBarcode"
                            class="pr-9"
                            @keydown="onSearchKeydown"
                        />
                        <kbd class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 font-mono text-[10px] font-normal text-text-faint">F2</kbd>
                    </div>
                    <div ref="barcodeFieldWrapper" class="relative w-[155px] shrink-0">
                        <Input
                            v-model="barcodeQuery"
                            type="text"
                            placeholder="Barcode… (F7)"
                            aria-label="Scan a barcode (F7)"
                            class="pr-9"
                            @keydown.enter.prevent="onBarcodeSubmit"
                            @change="onBarcodeSubmit"
                        />
                        <kbd class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 font-mono text-[10px] font-normal text-text-faint">F7</kbd>
                    </div>
                </div>

                    <div v-if="categories.length" class="flex shrink-0 gap-1.5 overflow-x-auto pb-1">
                        <button
                            type="button"
                            class="shrink-0 cursor-pointer border-[1.5px] px-2.5 py-1 text-xs font-bold whitespace-nowrap transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
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
                            class="shrink-0 cursor-pointer border-[1.5px] px-2.5 py-1 text-xs font-bold whitespace-nowrap transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
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

                    <div class="pos-grid grid content-start gap-2.5 lg:min-h-0 lg:flex-1 lg:overflow-y-auto">
                        <Card
                            v-for="item in visibleItems"
                            :key="item.id"
                            variant="product"
                            class="relative"
                            :class="[
                                isOutOfStock(item) ? 'pointer-events-none opacity-50' : 'cursor-pointer select-none',
                                hasQuantityInCart(item.id) ? 'border-primary hover:border-primary' : '',
                            ]"
                            @click="addToCart(item)"
                        >
                            <Badge :variant="item.is_vatable ? 'tax' : 'free'" class="absolute top-1.5 right-1.5 z-10">
                                {{ item.is_vatable ? 'TAX' : 'VAT-FREE' }}
                            </Badge>
                            <div class="relative mb-2">
                                <img
                                    v-if="item.image_path"
                                    :src="`/storage/${item.image_path}`"
                                    :alt="item.name"
                                    class="h-16 w-full rounded-none border-[1.5px] border-border object-cover"
                                />
                                <div
                                    v-else
                                    class="flex h-16 w-full items-center justify-center border-[1.5px] border-border bg-bg-subtle text-text-faint"
                                >
                                    <Package class="h-6 w-6" />
                                </div>
                                <span
                                    v-if="hasQuantityInCart(item.id)"
                                    class="absolute right-1 bottom-1 flex h-5 min-w-5 items-center justify-center bg-primary px-1 text-[11px] font-bold text-white"
                                    :title="`${formatQuantity(quantityInCart(item.id))} in cart`"
                                >
                                    {{ formatQuantity(quantityInCart(item.id)) }}
                                </span>
                            </div>
                            <p class="text-sm font-bold text-text-strong">{{ item.name }}</p>
                            <p v-if="item.sale_rate != null" class="mt-1 text-sm font-bold text-primary tabular-nums">
                                {{ formatRate(item.sale_rate) }}
                            </p>
                            <p v-else class="mt-1 inline-block bg-warning-bg px-1.5 py-0.5 text-[11px] font-bold text-warning-text">No price set</p>
                            <p v-if="isOutOfStock(item)" class="mt-1 text-[10px] font-bold text-danger uppercase">Out of stock</p>
                            <p v-else-if="item.is_stockable" class="mt-1 text-[10px] text-text-faint">
                                Stock: {{ formatQuantity(item.current_stock) }}
                            </p>
                        </Card>
                        <div v-if="filteredItems.length === 0" class="col-span-full flex flex-col items-center gap-1 py-8 text-center">
                            <Package class="h-8 w-8 text-text-faint" />
                            <p class="text-sm font-semibold text-text-base">
                                {{ searchQuery.trim() ? `No items match “${searchQuery.trim()}”` : 'No items in this category' }}
                            </p>
                            <p class="text-xs text-text-faint">Check the spelling or barcode, or clear the search and category filter.</p>
                        </div>
                    </div>
                    <p v-if="filteredItems.length > ITEM_TILE_CAP" class="shrink-0 text-xs text-text-faint">
                        Showing {{ visibleItems.length }} of {{ filteredItems.length }} items - refine your search to see more.
                    </p>
            </div>

            <!-- RIGHT - cart & checkout -->
            <div class="pos-right flex min-w-0 shrink-0 flex-col gap-3 p-3 lg:h-full lg:min-h-0 lg:shrink lg:overflow-y-auto">
                    <div class="shrink-0">
                        <div class="flex items-center gap-2">
                            <div ref="customerFieldWrapper" class="relative flex-1">
                                <Combobox
                                    :model-value="form.customer_id"
                                    :options="customerOptions"
                                    placeholder="Select customer… (F4)"
                                    @update:model-value="(v) => (form.customer_id = v)"
                                />
                            </div>
                            <Button variant="secondary" tone="purple" type="button" class="h-9 cursor-pointer" aria-label="Add a new customer" @click="openCustomerModal">
                                <Plus class="h-3.5 w-3.5" /> New
                            </Button>
                        </div>
                        <p v-if="form.errors.customer_id" class="mt-1 text-sm text-danger">{{ form.errors.customer_id }}</p>
                    </div>

                    <Card
                        variant="panel"
                        class="shrink !border-b-0 lg:min-h-[240px] lg:flex-1 lg:overflow-y-auto lg:overflow-x-hidden"
                    >
                        <div class="mb-2 flex items-center justify-between">
                            <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Cart</p>
                            <button
                                v-if="form.lines.length > 0"
                                type="button"
                                class="h-7 cursor-pointer px-2 text-xs font-semibold text-text-muted hover:text-danger focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                @click="clearCart"
                            >
                                Clear cart
                            </button>
                        </div>
                        <div v-if="form.lines.length === 0" class="flex flex-col items-center gap-1 py-6 text-center">
                            <ScanBarcode class="h-8 w-8 text-text-faint" />
                            <p class="text-sm font-semibold text-text-base">Scan or search an item to start a sale</p>
                            <p class="text-xs text-text-faint">Tap an item tile, press <kbd class="font-mono">F2</kbd> to search or <kbd class="font-mono">F7</kbd> to scan a barcode.</p>
                        </div>
                        <div v-for="(line, index) in form.lines" :key="index" class="mb-1.5 flex flex-col gap-1.5 border-[1.5px] border-border bg-white p-2 last:mb-0">
                            <div class="flex items-center gap-2">
                                <p class="min-w-0 flex-1 truncate text-sm font-semibold text-text-strong">
                                    {{ itemsById[line.item_id]?.name ?? 'Item' }}
                                </p>
                                <Badge :variant="itemsById[line.item_id]?.is_vatable ? 'tax' : 'free'">
                                    {{ itemsById[line.item_id]?.is_vatable ? 'TAX' : 'VAT-FREE' }}
                                </Badge>
                                <span class="shrink-0 text-sm font-bold tabular-nums" :class="lineTotal(index) === null ? 'text-text-faint' : 'text-text-strong'">
                                    {{ lineTotal(index) === null ? '—' : formatMoney(lineTotal(index)) }}
                                </span>
                                <Tooltip label="More: MRP, free units, split">
                                    <button
                                        type="button"
                                        class="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center text-text-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                                        aria-label="Show more options for this line"
                                        :aria-expanded="!!line.showMore"
                                        @click="toggleLineMore(line)"
                                    >
                                        <ChevronDown class="h-4 w-4 transition-transform" :class="line.showMore ? 'rotate-180' : ''" />
                                    </button>
                                </Tooltip>
                                <Tooltip label="Remove this item">
                                    <button
                                        type="button"
                                        class="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center text-text-muted hover:text-danger focus-visible:outline-2 focus-visible:outline-primary"
                                        aria-label="Remove this item from the cart"
                                        @click="removeLine(index)"
                                    >
                                        <X class="h-4 w-4" />
                                    </button>
                                </Tooltip>
                            </div>
                            <p v-if="form.errors[`lines.${index}.item_id`]" class="text-xs text-danger">
                                {{ form.errors[`lines.${index}.item_id`] }}
                            </p>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <div class="flex w-32 shrink-0 items-stretch border-[1.5px] border-border bg-white focus-within:border-primary">
                                    <button
                                        type="button"
                                        class="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center bg-bg-subtle text-text-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                                        aria-label="Decrease quantity"
                                        title="Decrease quantity"
                                        @click="decrementQty(index)"
                                    >
                                        <Minus class="h-4 w-4" />
                                    </button>
                                    <Input
                                        v-model="line.quantity"
                                        type="number"
                                        min="0"
                                        step="0.0001"
                                        placeholder="Qty"
                                        aria-label="Quantity"
                                        class="!h-8 min-w-0 flex-1 !border-0 !bg-white text-center !shadow-none !outline-none"
                                        @blur="warnIfOverstock(line.item_id)"
                                    />
                                    <button
                                        type="button"
                                        class="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center bg-bg-subtle text-text-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                                        aria-label="Increase quantity"
                                        title="Increase quantity"
                                        @click="incrementQty(index)"
                                    >
                                        <Plus class="h-4 w-4" />
                                    </button>
                                </div>
                                <div class="w-20 shrink-0" :data-rate-index="index">
                                    <Input
                                        v-model="line.rate"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="Rate"
                                        aria-label="Rate"
                                        :class="isRateMissing(line) ? '!h-8 !border-danger text-center' : '!h-8 text-center'"
                                    />
                                </div>
                                <div class="flex w-28 shrink-0 items-stretch border-[1.5px] border-border bg-white focus-within:border-primary">
                                    <Input
                                        v-model="line.discount"
                                        type="number"
                                        min="0"
                                        :max="line.discountType === 'percent' ? 100 : undefined"
                                        :placeholder="line.discountType === 'percent' ? '%' : 'Disc.'"
                                        aria-label="Line discount"
                                        class="!h-8 min-w-0 flex-1 !border-0 !bg-white text-center !shadow-none !outline-none"
                                    />
                                    <button
                                        type="button"
                                        class="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center bg-bg-subtle text-xs font-bold text-text-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                                        title="Click to switch between % and Rs discount"
                                        :aria-label="`Discount type: ${line.discountType === 'percent' ? 'percent' : 'rupees'}. Click to switch`"
                                        @click="toggleLineDiscountType(index)"
                                    >
                                        {{ line.discountType === 'percent' ? '%' : 'Rs' }}
                                    </button>
                                </div>
                            </div>
                            <!-- MRP is VAT-inclusive entry (audit section 3
                                 "Sales"): typing it fills Rate above with
                                 MRP / 1.13 for a vatable item. Browser-only,
                                 never submitted. Bonus units move stock and
                                 are never billed, so the line total above and
                                 the bill total below ignore them. Collapsed by
                                 default - edited far less often than
                                 qty/rate/discount. -->
                            <div v-if="line.showMore" class="flex items-center gap-1.5">
                                <div class="w-20 shrink-0">
                                    <Input
                                        :model-value="line.mrp"
                                        type="number"
                                        min="0"
                                        step="0.0001"
                                        placeholder="MRP"
                                        title="VAT-inclusive price: fills Rate with MRP / (1 + VAT%)"
                                        class="!h-8 text-center"
                                        @update:model-value="(v) => applyLineMrp(line, v)"
                                    />
                                </div>
                                <div class="w-20 shrink-0">
                                    <Input
                                        v-model="line.bonus_quantity"
                                        type="number"
                                        min="0"
                                        step="0.0001"
                                        placeholder="Free"
                                        title="Free units given with this line - moves stock, never billed"
                                        class="!h-8 text-center"
                                        @blur="warnIfOverstock(line.item_id)"
                                    />
                                </div>
                                <button
                                    type="button"
                                    class="ml-auto flex h-8 cursor-pointer items-center gap-1.5 px-2 text-xs font-semibold text-text-muted hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                                    @click="openSplitModal(index)"
                                >
                                    <SplitSquareHorizontal class="h-4 w-4" /> Split to new cart
                                </button>
                                <span v-if="form.errors[`lines.${index}.bonus_quantity`]" class="text-xs text-danger">
                                    {{ form.errors[`lines.${index}.bonus_quantity`] }}
                                </span>
                            </div>
                        </div>
                    </Card>

                    <!-- Payment -->
                    <Card variant="panel" class="-mt-3 shrink-0">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-1.5">
                            <p class="text-xs font-bold tracking-[.8px] text-text-muted uppercase">Payment</p>
                            <div class="flex flex-wrap gap-1.5" role="group" aria-label="Quick payment">
                                <Button variant="secondary" tone="blue" type="button" class="h-6 cursor-pointer !px-2 !text-[11px]" @click="quickPayFullCash">
                                    Full Cash
                                </Button>
                                <Button variant="secondary" tone="purple" type="button" class="h-6 cursor-pointer !px-2 !text-[11px]" @click="quickPayFullBank">
                                    Full Bank
                                </Button>
                                <Button variant="secondary" tone="danger" type="button" class="h-6 cursor-pointer !px-2 !text-[11px]" @click="quickPayReset">
                                    Reset
                                </Button>
                            </div>
                        </div>

                        <!-- Legacy's fixed 2x2 layout: Cash | Bank / Note | Bank account -->
                        <div class="pos-pay-fields grid gap-2.5">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-text-muted">Cash Paid</label>
                                <Input v-model="form.cash_amount" type="number" min="0" step="0.01" placeholder="0.00" class="!h-8" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-text-muted">Bank / QR Paid</label>
                                <Input v-model="form.bank_amount" type="number" min="0" step="0.01" placeholder="0.00" class="!h-8" />
                            </div>
                            <div v-if="showBankAccountField" class="col-span-2">
                                <label class="mb-1 block text-sm font-semibold text-text-base">Bank account</label>
                                <Combobox
                                    :model-value="form.bank_account_id"
                                    :options="bankAccountOptions"
                                    placeholder="Select bank account"
                                    @update:model-value="(v) => (form.bank_account_id = v)"
                                />
                            </div>
                        </div>
                        <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>

                        <p v-if="resolvedPaymentMode === 'partial' && !paymentBalanced" class="mt-1 text-xs font-semibold text-danger">
                            Cash + bank must add up to exactly {{ settlementDue ? formatMoney(settlementDue) : '-' }} for a split payment.
                        </p>

                        <button type="button" class="mt-3 h-9 cursor-pointer text-sm font-semibold text-primary focus-visible:outline-2 focus-visible:outline-primary" @click="showAdvanced = !showAdvanced">
                            {{ showAdvanced ? 'Hide' : 'Show' }} more options
                        </button>

                        <!-- Fields legacy never had at all (store, header discount,
                             TDS, chalani number) stay tucked away here - Date moved
                             to the always-visible top bar since legacy shows it
                             there and it's required to submit. -->
                        <div v-if="showAdvanced" class="mt-3 flex flex-col gap-3 border-t border-border pt-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                                    <Combobox
                                        :model-value="form.store_id"
                                        :options="storeOptions"
                                        placeholder="Default store"
                                        @update:model-value="(v) => (form.store_id = v)"
                                    />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-text-muted">TDS amount</label>
                                    <Input v-model="form.tds_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-text-muted">Header discount</label>
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
                                            class="flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center border-[1.5px] border-border bg-bg-subtle text-xs font-bold text-text-muted hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                                            title="Click to switch between % and Rs discount"
                                            :aria-label="`Discount type: ${form.discount_type === 'percent' ? 'percent' : 'rupees'}. Click to switch`"
                                            @click="toggleHeaderDiscountType"
                                        >
                                            {{ form.discount_type === 'percent' ? '%' : 'Rs' }}
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-text-muted">TDS account</label>
                                    <Combobox
                                        :model-value="form.tds_account_id"
                                        :options="tdsAccountOptions"
                                        placeholder="Optional"
                                        @update:model-value="(v) => (form.tds_account_id = v)"
                                    />
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-text-muted">Chalani number</label>
                                    <Input v-model="form.chalani_number" type="text" placeholder="Optional" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-semibold text-text-muted">Note</label>
                                    <Input v-model="form.narration" type="text" placeholder="Optional" />
                                </div>
                            </div>
                        </div>
                    </Card>

                    <!-- Totals -->
                    <Card v-if="totals || form.errors.lines || form.errors.expected_total" variant="panel" class="shrink-0">
                        <dl v-if="totals" class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 text-[13px]" aria-live="polite">
                            <dt class="text-text-muted">Subtotal</dt>
                            <dd class="text-right font-medium text-text-strong tabular-nums">{{ formatMoney(addMoney(totals.vatable_subtotal, totals.non_vatable_subtotal)) }}</dd>
                            <template v-if="totals.header_discount && totals.header_discount !== '0.00'">
                                <dt class="text-text-muted">Discount</dt>
                                <dd class="text-right font-medium text-text-strong tabular-nums">− {{ formatMoney(totals.header_discount) }}</dd>
                            </template>
                            <dt class="text-text-muted">Taxable amount</dt>
                            <dd class="text-right font-medium text-text-strong tabular-nums">{{ formatMoney(totals.taxable_amount) }}</dd>
                            <dt class="text-text-muted">Non-taxable amount</dt>
                            <dd class="text-right font-medium text-text-strong tabular-nums">{{ formatMoney(totals.nontaxable_amount) }}</dd>
                            <dt class="text-text-muted">VAT ({{ Number(totals.vat_rate) }}%)</dt>
                            <dd class="text-right font-medium text-text-strong tabular-nums">{{ formatMoney(totals.vat_amount) }}</dd>
                            <dt class="pos-grand mt-1 border-t border-border pt-2">Grand total</dt>
                            <dd class="pos-grand-amt mt-1 border-t border-border pt-2 text-right tabular-nums">{{ formatMoney(totals.total) }}</dd>
                        </dl>
                        <p v-else-if="!previewError" class="text-sm text-text-faint">Totals appear once the cart has items.</p>
                        <p v-if="form.errors.lines" class="mt-2 text-sm text-danger">{{ form.errors.lines }}</p>
                        <p v-if="form.errors.expected_total" class="mt-2 text-sm text-danger">{{ form.errors.expected_total }}</p>
                    </Card>

                    <!-- Actions: legacy's Hold / Save / Save & Print row -->
                    <div class="pos-footer sticky bottom-0 z-10 -mx-3 -mb-3 mt-3 flex shrink-0 flex-col gap-2 border-t border-border bg-white px-3 pt-3 pb-2 shadow-[0_-6px_10px_-6px_rgba(0,0,0,0.12)]">
                    <div v-if="hasDue || hasChange" class="flex shrink-0 items-center justify-between px-3 py-1.5 text-sm font-bold" :class="hasDue ? 'bg-warning-bg text-warning-text' : 'bg-success-bg text-success'">
                        <span>{{ hasDue ? 'Balance due' : 'Change to return' }}</span>
                        <span>{{ formatMoney(hasDue ? dueAmount : changeAmount) }}</span>
                    </div>
                    <p v-if="submitBlockedReason" id="pos-submit-reason" class="sr-only" role="status">{{ submitBlockedReason }}</p>
                    <div class="pos-actions grid shrink-0 gap-1.5">
                        <Button variant="secondary" tone="purple" type="button" class="h-11 cursor-pointer justify-center" @click="holdCarts">
                            <CirclePause class="h-4 w-4" /> Hold <kbd class="ml-1 font-mono text-[10px] opacity-70">F9</kbd>
                        </Button>
                        <Button
                            variant="secondary"
                            tone="blue"
                            type="button"
                            class="h-11 cursor-pointer justify-center"
                            :disabled="!canSubmit"
                            :loading="form.processing"
                            @click="completeSale('save')"
                        >
                            Save
                        </Button>
                        <Button
                            variant="primary"
                            tone="purple"
                            type="button"
                            class="h-11 cursor-pointer justify-center"
                            :disabled="!canSubmit"
                            :loading="form.processing"
                            :aria-describedby="submitBlockedReason ? 'pos-submit-reason' : undefined"
                            @click="completeSale('print')"
                        >
                            Save & Print <kbd class="ml-1 font-mono text-[10px] font-normal opacity-80">F8</kbd>
                        </Button>
                    </div>
                    <p v-if="form.processing" class="shrink-0 text-center text-xs text-text-faint" role="status">Completing sale…</p>
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
                    {{ itemsById[form.lines[splitLineIndex]?.item_id]?.name ?? 'Item' }} - current quantity
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

.pos-bar {
    background: var(--color-primary);
    color: white;
}

/* The accent rule is drawn per section (not on the bar) so the active cart tab can sit on top of it and merge into the panel below. */
.pos-bar-section {
    position: relative;
}

.pos-bar-section::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 2px;
    background: var(--color-accent);
}

.pos-cart-tab {
    position: relative;
    z-index: 1;
}

.pos-cart-tab--active {
    height: 46px;
    padding-bottom: 2px;
    background: var(--color-bg-subtle);
    color: var(--color-primary);
}

.pos-cart-tab--idle {
    height: 32px;
    margin-bottom: 10px;
    background: rgba(255, 255, 255, 0.12);
    border: 1.5px solid rgba(255, 255, 255, 0.25);
    color: white;
}

.pos-cart-tab--idle:hover {
    background: rgba(255, 255, 255, 0.22);
}

.pos-bar-section {
    min-height: 52px;
    min-width: 0;
    padding: 0 14px;
}

.pos-bar-section--carts {
    border-top: 1px solid rgba(255, 255, 255, 0.25);
}

/* Same 60/40 split as the body below, so the tabs sit right over the cart column. */
@media (min-width: 1024px) {
    .pos-bar-section--products {
        width: 60%;
    }

    .pos-bar-section--carts {
        width: 40%;
        border-top: 0;
        border-left: 1px solid rgba(255, 255, 255, 0.25);
    }
}

.pos-bar-divider {
    width: 1px;
    height: 22px;
    background: rgba(255, 255, 255, 0.25);
    flex-shrink: 0;
}

.pos-bar-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    height: 32px;
    padding: 0 10px;
    flex-shrink: 0;
    font-size: 12px;
    font-weight: 700;
    color: white;
    background: rgba(255, 255, 255, 0.12);
    border: 1.5px solid rgba(255, 255, 255, 0.25);
    cursor: pointer;
    white-space: nowrap;
}

.pos-bar-btn:hover {
    background: rgba(255, 255, 255, 0.22);
}

.pos-bar-btn--icon {
    width: 32px;
    padding: 0;
    justify-content: center;
}

.pos-bar-kbd {
    font-family: ui-monospace, monospace;
    font-size: 10px;
    opacity: 0.8;
}

.pos-fy-badge,
.pos-bill-badge {
    display: flex;
    align-items: center;
    gap: 6px;
    height: 32px;
    padding: 0 10px;
    flex-shrink: 0;
    font-size: 11.5px;
    font-weight: 700;
    color: white;
    background: rgba(255, 255, 255, 0.12);
    white-space: nowrap;
}

.pos-date-box {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

.pos-date-box label {
    font-size: 9px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.75);
    letter-spacing: 0.5px;
}

.pos-date-input {
    width: 96px;
}

.pos-draft-btn {
    display: flex;
    margin-bottom: 12px;
    height: 28px;
    width: 28px;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    border: 1.5px dashed var(--color-border);
    background: var(--color-primary-tint);
    color: var(--color-primary);
    cursor: pointer;
}

.pos-draft-btn--merge {
    background: var(--color-success-bg-soft, var(--color-bg-subtle));
    color: var(--color-success);
    border-style: solid;
}

.pos-body {
    overflow: hidden;
}

.pos-left {
    background: var(--color-bg-surface);
    border-right: 1px solid var(--color-border);
}

.pos-right {
    background: var(--color-bg-subtle);
}

@media (min-width: 1024px) {
    .pos-left {
        width: 60%;
    }

    .pos-right {
        width: 40%;
    }
}

.pos-grid {
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    /* Room for the hover shadow, which the scroll container would otherwise crop. */
    padding: 14px 16px 20px;
}

@media (max-width: 600px) {
    .pos-grid {
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    }
}

.pos-pay-fields {
    grid-template-columns: 1fr 1fr;
}

.pos-grand {
    font-size: 13px;
    font-weight: 800;
    color: var(--color-text-strong);
}

.pos-grand-amt {
    font-size: 16px;
    font-weight: 800;
    color: var(--color-primary);
}

.pos-actions {
    grid-template-columns: 1fr 1fr 1.3fr;
}
</style>
