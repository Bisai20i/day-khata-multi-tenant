<script setup>
import { computed, onMounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Delete, Minus, Plus, ScanBarcode, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Modal from '@/components/ui/Modal.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';

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
 */
const props = defineProps({
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const PENDING_RECEIPT_KEY = 'pos-last-receipt';
const PENDING_CUSTOMER_KEY = 'pos-pending-customer';

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: c.name })));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
const accountOptions = computed(() =>
    props.accounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} — ${a.name}` : a.name })),
);
const itemsById = computed(() => Object.fromEntries(props.items.map((i) => [i.id, i])));

const invoiceTypeOptions = [
    { value: 'full', label: 'Full tax invoice' },
    { value: 'abbreviated', label: 'Abbreviated tax invoice' },
];

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'partial', label: 'Partial (cash + bank)' },
    { value: 'credit', label: 'Credit' },
];

function today() {
    return new Date().toISOString().slice(0, 10);
}

const form = useForm({
    customer_id: null,
    store_id: null,
    invoice_type: 'full',
    date: today(),
    payment_mode: 'cash',
    bank_account_id: null,
    discount: '',
    vat_rate: '13',
    cash_amount: '',
    bank_amount: '',
    tds_account_id: null,
    tds_amount: '',
    narration: '',
    lines: [],
});

const showAdvanced = ref(false);

// --- Item search / "barcode scan" input --------------------------------
// There is no barcode column on Item (confirmed - out of scope to add one
// here). A barcode scanner just types digits into whatever text input is
// focused and then sends Enter, indistinguishable from a cashier typing -
// so this is a plain text field that live-filters the tile grid by name,
// and on Enter adds the best match to the cart (mirroring a real scan).
const scanQuery = ref('');

const filteredItems = computed(() => {
    const q = scanQuery.value.trim().toLowerCase();
    if (!q) return props.items;

    return props.items.filter((item) => item.name.toLowerCase().includes(q));
});

function quantityInCart(itemId) {
    const line = form.lines.find((l) => l.item_id === itemId);
    return line ? Number(line.quantity) || 0 : 0;
}

function onScanKeydown(event) {
    if (event.key !== 'Enter') return;
    event.preventDefault();

    const q = scanQuery.value.trim().toLowerCase();
    if (!q) return;

    const exact = props.items.find((item) => item.name.toLowerCase() === q);
    const match = exact ?? filteredItems.value[0];

    if (match) addToCart(match);
    scanQuery.value = '';
}

// --- Cart (form.lines) ---------------------------------------------------
function addToCart(item) {
    const index = form.lines.findIndex((l) => l.item_id === item.id);

    if (index !== -1) {
        form.lines[index].quantity = String((Number(form.lines[index].quantity) || 0) + 1);
        activeTarget.value = { type: 'quantity', index };
        return;
    }

    form.lines.push({ item_id: item.id, quantity: '1', rate: '', discount: '' });
    activeTarget.value = { type: 'rate', index: form.lines.length - 1 };
}

function removeLine(index) {
    form.lines.splice(index, 1);
    activeTarget.value = null;
}

function incrementQty(index) {
    form.lines[index].quantity = String((Number(form.lines[index].quantity) || 0) + 1);
}

function decrementQty(index) {
    const next = (Number(form.lines[index].quantity) || 0) - 1;
    if (next <= 0) {
        removeLine(index);
        return;
    }
    form.lines[index].quantity = String(next);
}

function focusTarget(type, index) {
    activeTarget.value = { type, index };
}

const lineTotals = computed(() =>
    form.lines.map((line) => {
        const item = itemsById.value[line.item_id];
        const qty = Number(line.quantity) || 0;
        const rate = Number(line.rate) || 0;
        const discount = Number(line.discount) || 0;

        return { vatable: item?.is_vatable ?? false, total: qty * rate - discount };
    }),
);

const vatableSubtotal = computed(() => lineTotals.value.filter((l) => l.vatable).reduce((s, l) => s + l.total, 0));
const nonVatableSubtotal = computed(() => lineTotals.value.filter((l) => !l.vatable).reduce((s, l) => s + l.total, 0));
const taxableAmount = computed(() => vatableSubtotal.value - (Number(form.discount) || 0));
const nontaxableAmount = computed(() => nonVatableSubtotal.value);
const vatAmount = computed(() => Math.round(taxableAmount.value * ((Number(form.vat_rate) || 0) / 100) * 100) / 100);
const total = computed(() => taxableAmount.value + nontaxableAmount.value + vatAmount.value);
const settlementDue = computed(() => total.value - (Number(form.tds_amount) || 0));

const showBankField = computed(() => form.payment_mode === 'bank' || form.payment_mode === 'partial');
const showPartialFields = computed(() => form.payment_mode === 'partial');
const partialBalanced = computed(() => {
    if (!showPartialFields.value) return true;
    const sum = (Number(form.cash_amount) || 0) + (Number(form.bank_amount) || 0);
    return Math.abs(sum - settlementDue.value) < 0.01;
});

// --- Cash tendered / change (UI-only, not part of the /sales payload -
// Sale::post() settles "cash" mode for the full settlement due, it has no
// concept of tendered/change) --------------------------------------------
const cashTendered = ref('');
const changeDue = computed(() => Math.max(0, (Number(cashTendered.value) || 0) - settlementDue.value));

// --- On-screen numpad ------------------------------------------------------
// Targets whichever field was last focused/tapped: a cart line's quantity or
// rate, or the cash-tendered box. Typing directly into those Input fields
// still works too - the numpad just writes into the same reactive value.
const activeTarget = ref(null);

const activeTargetLabel = computed(() => {
    const t = activeTarget.value;
    if (!t) return 'Tap a quantity, rate, or cash field';
    if (t.type === 'cash') return 'Cash tendered';
    const line = form.lines[t.index];
    const name = line ? (itemsById.value[line.item_id]?.name ?? 'Item') : 'Item';
    return `${name} — ${t.type === 'quantity' ? 'Quantity' : 'Rate'}`;
});

function currentTargetValue() {
    const t = activeTarget.value;
    if (!t) return '';
    if (t.type === 'cash') return cashTendered.value;
    return form.lines[t.index]?.[t.type] ?? '';
}

function setTargetValue(value) {
    const t = activeTarget.value;
    if (!t) return;
    if (t.type === 'cash') {
        cashTendered.value = value;
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

// --- Customer picker + quick "+ New customer" -----------------------------
// Reuses the existing POST /customers endpoint exactly as
// Tenant/Parties/Customers/Index.vue does (same fields, same route) - it
// always redirects to the customers index on success, so this bounces back
// to /pos and best-effort auto-selects the new customer by matching
// name/mobile against the freshly reloaded customers list (there is no id
// returned to us directly, since the endpoint replies with a redirect, not
// JSON).
const customerModalOpen = ref(false);
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

function buildReceiptSnapshot() {
    const customer = props.customers.find((c) => c.id === form.customer_id);

    return {
        customerName: customer?.name ?? 'Walk-in',
        date: form.date,
        paymentMode: form.payment_mode,
        lines: form.lines.map((line) => {
            const item = itemsById.value[line.item_id];
            const qty = Number(line.quantity) || 0;
            const rate = Number(line.rate) || 0;
            const discount = Number(line.discount) || 0;

            return { name: item?.name ?? 'Item', unit: item?.unit ?? '', quantity: qty, rate, total: qty * rate - discount };
        }),
        taxableAmount: taxableAmount.value,
        nontaxableAmount: nontaxableAmount.value,
        vatAmount: vatAmount.value,
        total: total.value,
        cashTendered: Number(cashTendered.value) || 0,
        change: changeDue.value,
    };
}

function resetForNextSale() {
    form.reset();
    form.clearErrors();
    form.date = today();
    form.lines = [];
    cashTendered.value = '';
    activeTarget.value = null;
    scanQuery.value = '';
}

function completeSale() {
    const snapshot = buildReceiptSnapshot();

    form.transform((data) => ({
        ...data,
        discount: Number(data.discount) || 0,
        vat_rate: Number(data.vat_rate) || 0,
        cash_amount: data.payment_mode === 'partial' ? Number(data.cash_amount) || 0 : undefined,
        bank_amount: data.payment_mode === 'partial' ? Number(data.bank_amount) || 0 : undefined,
        tds_amount: Number(data.tds_amount) || 0,
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            quantity: Number(line.quantity) || 0,
            rate: Number(line.rate) || 0,
            discount: Number(line.discount) || 0,
        })),
    })).post('/sales', {
        preserveScroll: true,
        onSuccess: () => {
            sessionStorage.setItem(PENDING_RECEIPT_KEY, JSON.stringify(snapshot));
            resetForNextSale();
            router.visit('/pos', { onSuccess: applyPendingReceipt });
        },
    });
}

function applyPendingReceipt() {
    const raw = sessionStorage.getItem(PENDING_RECEIPT_KEY);
    if (!raw) return;
    sessionStorage.removeItem(PENDING_RECEIPT_KEY);

    try {
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

const canSubmit = computed(
    () =>
        !form.processing &&
        form.customer_id &&
        form.date &&
        form.lines.length > 0 &&
        !(showPartialFields.value && !partialBalanced.value),
);

onMounted(() => {
    applyPendingCustomer();
    applyPendingReceipt();
});
</script>

<template>
    <AppLayout title="POS" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Point of sale</h2>
            <p class="text-sm text-text-muted">{{ form.lines.length }} item{{ form.lines.length === 1 ? '' : 's' }} in cart</p>
        </div>

        <div class="grid grid-cols-[1fr_400px] gap-4">
            <!-- Item search + tile grid -->
            <div class="flex flex-col gap-3">
                <Card variant="panel">
                    <Input
                        v-model="scanQuery"
                        type="text"
                        placeholder="Scan or type an item name…"
                        :icon="ScanBarcode"
                        @keydown="onScanKeydown"
                    />
                </Card>

                <div class="grid grid-cols-3 gap-3 sm:grid-cols-4">
                    <Card
                        v-for="item in filteredItems"
                        :key="item.id"
                        variant="product"
                        class="cursor-pointer select-none"
                        @click="addToCart(item)"
                    >
                        <p class="text-sm font-bold text-text-strong">{{ item.name }}</p>
                        <p class="mt-1 text-xs text-text-muted">{{ item.unit }}</p>
                        <p v-if="quantityInCart(item.id) > 0" class="mt-2 text-xs font-bold text-primary">
                            {{ quantityInCart(item.id) }} in cart
                        </p>
                    </Card>
                    <p v-if="filteredItems.length === 0" class="col-span-full text-sm text-text-faint">No items match.</p>
                </div>
            </div>

            <!-- Cart / checkout panel -->
            <div class="flex flex-col gap-3">
                <Card variant="panel" title="Customer">
                    <div class="flex gap-2">
                        <Combobox
                            :model-value="form.customer_id"
                            :options="customerOptions"
                            placeholder="Select customer"
                            class="flex-1"
                            @update:model-value="(v) => (form.customer_id = v)"
                        />
                        <Button variant="secondary" tone="purple" type="button" @click="openCustomerModal">
                            <Plus class="h-3.5 w-3.5" /> New
                        </Button>
                    </div>
                    <p v-if="form.errors.customer_id" class="mt-1 text-sm text-danger">{{ form.errors.customer_id }}</p>
                </Card>

                <Card variant="panel" title="Cart" class="max-h-[280px] overflow-y-auto">
                    <p v-if="form.lines.length === 0" class="text-sm text-text-faint">Tap an item to add it.</p>
                    <div v-for="(line, index) in form.lines" :key="index" class="mb-2 flex items-center gap-2 border-b border-border pb-2 last:border-0">
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-text-strong">{{ itemsById[line.item_id]?.name ?? 'Item' }}</p>
                            <p v-if="form.errors[`lines.${index}.item_id`]" class="text-xs text-danger">
                                {{ form.errors[`lines.${index}.item_id`] }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-6 w-6 items-center justify-center bg-bg-subtle text-text-muted hover:text-primary"
                            @click="decrementQty(index)"
                        >
                            <Minus class="h-3 w-3" />
                        </button>
                        <Input
                            v-model="line.quantity"
                            type="number"
                            min="0"
                            step="0.0001"
                            class="w-16 text-center"
                            @focusin="focusTarget('quantity', index)"
                        />
                        <button
                            type="button"
                            class="flex h-6 w-6 items-center justify-center bg-bg-subtle text-text-muted hover:text-primary"
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
                            class="w-20 text-center"
                            @focusin="focusTarget('rate', index)"
                        />
                        <button
                            type="button"
                            class="flex h-6 w-6 items-center justify-center text-text-muted hover:text-danger"
                            aria-label="Remove line"
                            @click="removeLine(index)"
                        >
                            <X class="h-3.5 w-3.5" />
                        </button>
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

                <Card variant="panel">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-text-base">Payment mode</label>
                            <Select v-model="form.payment_mode" :options="paymentModeOptions" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-text-base">Date</label>
                            <NepaliDateInput v-model="form.date" required />
                        </div>
                    </div>

                    <div v-if="showBankField" class="mt-3">
                        <label class="mb-1 block text-sm font-semibold text-text-base">Bank account</label>
                        <Combobox
                            :model-value="form.bank_account_id"
                            :options="accountOptions"
                            placeholder="Select bank account"
                            @update:model-value="(v) => (form.bank_account_id = v)"
                        />
                        <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                    </div>

                    <div v-if="showPartialFields" class="mt-3 grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-text-base">Cash amount</label>
                            <Input v-model="form.cash_amount" type="number" min="0" step="0.01" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-text-base">Bank amount</label>
                            <Input v-model="form.bank_amount" type="number" min="0" step="0.01" />
                        </div>
                    </div>
                    <p v-if="showPartialFields && !partialBalanced" class="mt-1 text-xs font-semibold text-danger">
                        Cash + bank must add up to {{ settlementDue.toFixed(2) }}.
                    </p>

                    <div v-if="form.payment_mode === 'cash'" class="mt-3">
                        <label class="mb-1 block text-sm font-semibold text-text-base">Cash tendered</label>
                        <Input v-model="cashTendered" type="number" min="0" step="0.01" placeholder="0.00" @focusin="activeTarget = { type: 'cash' }" />
                        <p class="mt-1 text-xs text-text-muted">Change due: {{ changeDue.toFixed(2) }}</p>
                    </div>

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
                                <label class="mb-1 block text-sm font-semibold text-text-base">Store</label>
                                <Combobox
                                    :model-value="form.store_id"
                                    :options="storeOptions"
                                    placeholder="Default store"
                                    @update:model-value="(v) => (form.store_id = v)"
                                />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-sm font-semibold text-text-base">Header discount</label>
                                <Input v-model="form.discount" type="number" min="0" step="0.01" placeholder="0.00" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-semibold text-text-base">VAT rate (%)</label>
                                <Input v-model="form.vat_rate" type="number" min="0" step="0.01" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-sm font-semibold text-text-base">TDS account</label>
                                <Combobox
                                    :model-value="form.tds_account_id"
                                    :options="accountOptions"
                                    placeholder="Optional"
                                    @update:model-value="(v) => (form.tds_account_id = v)"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-semibold text-text-base">TDS amount</label>
                                <Input v-model="form.tds_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                            <Input v-model="form.narration" type="text" placeholder="Optional" />
                        </div>
                    </div>
                </Card>

                <Card variant="panel">
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <p class="text-text-muted">Taxable</p>
                        <p class="text-right font-semibold text-text-strong">{{ taxableAmount.toFixed(2) }}</p>
                        <p class="text-text-muted">Non-taxable</p>
                        <p class="text-right font-semibold text-text-strong">{{ nontaxableAmount.toFixed(2) }}</p>
                        <p class="text-text-muted">VAT</p>
                        <p class="text-right font-semibold text-text-strong">{{ vatAmount.toFixed(2) }}</p>
                        <p class="text-base font-bold text-text-strong">Total</p>
                        <p class="text-right text-base font-bold text-primary">{{ total.toFixed(2) }}</p>
                    </div>
                    <p v-if="form.errors.lines" class="mt-2 text-sm text-danger">{{ form.errors.lines }}</p>
                    <Button variant="primary" tone="purple" type="button" class="mt-3 w-full justify-center" :disabled="!canSubmit" @click="completeSale">
                        Complete sale
                    </Button>
                </Card>
            </div>
        </div>

        <!-- Quick "+ New customer" -->
        <Modal :open="customerModalOpen" title="New customer" size="compact" @update:open="(v) => (v ? null : closeCustomerModal())">
            <form class="flex flex-col gap-4" @submit.prevent="submitCustomer">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input v-model="customerForm.name" type="text" required />
                    <p v-if="customerForm.errors.name" class="mt-1 text-sm text-danger">{{ customerForm.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Mobile No</label>
                    <Input v-model="customerForm.mobile_no" type="text" />
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

        <!-- Receipt confirmation (frontend-only - no backend receipt endpoint) -->
        <Modal :open="receiptOpen" title="Sale complete" size="compact" @update:open="(v) => (v ? null : closeReceipt())">
            <div v-if="receipt" class="flex flex-col gap-3 text-sm">
                <div class="flex justify-between text-text-muted">
                    <span>{{ receipt.customerName }}</span>
                    <span>{{ receipt.date }}</span>
                </div>
                <div class="border-t border-border pt-2">
                    <div v-for="(line, i) in receipt.lines" :key="i" class="flex justify-between py-0.5">
                        <span>{{ line.name }} × {{ line.quantity }}</span>
                        <span class="font-semibold">{{ line.total.toFixed(2) }}</span>
                    </div>
                </div>
                <div class="border-t border-border pt-2">
                    <div class="flex justify-between text-base font-bold text-text-strong">
                        <span>Total</span>
                        <span>{{ receipt.total.toFixed(2) }}</span>
                    </div>
                    <div v-if="receipt.paymentMode === 'cash' && receipt.cashTendered > 0" class="mt-1 flex justify-between text-text-muted">
                        <span>Cash tendered</span>
                        <span>{{ receipt.cashTendered.toFixed(2) }}</span>
                    </div>
                    <div v-if="receipt.paymentMode === 'cash' && receipt.cashTendered > 0" class="flex justify-between text-text-muted">
                        <span>Change</span>
                        <span>{{ receipt.change.toFixed(2) }}</span>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button variant="primary" tone="purple" type="button" @click="closeReceipt">New sale</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
