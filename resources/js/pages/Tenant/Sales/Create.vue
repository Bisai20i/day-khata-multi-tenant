<script setup>
import { computed, onMounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Modal from '@/components/ui/Modal.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { useToast } from '@/composables/useToast';
import { calculateDocument, formatMoney, formatQuantity, formatRate, moneyEquals, parseMoney, percentOf, addMoney } from '@/lib/money';
import { todayInKathmandu } from '@/lib/format';

/**
 * Every rupee shown on this form comes from calculateDocument(), the exact
 * mirror of the server's App\Support\Billing\DocumentCalculator (CONTRACTS
 * C3/C8). Nothing here adds up a bill on its own: the audit found the preview
 * quoting 56.49 for a bill the server stored as 56.50 (P0-8), because the
 * browser summed unrounded floats and rounded halves the other way.
 *
 * The preview's total is submitted as `expected_total`; if the server arrives
 * at anything else it refuses the save rather than booking a different amount
 * than the one on screen.
 */
const props = defineProps({
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    tdsAccounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    agents: { type: Array, default: () => [] },
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
    // Set by the parent Index page when it bounces back here after the
    // inline "+ New customer" modal redirects away and back (see
    // submitCustomer() below) - restores the in-progress draft that would
    // otherwise be lost when Index re-mounts this component.
    initialDraft: { type: Object, default: null },
});

const emit = defineEmits(['cancel', 'posted']);
const { toast } = useToast();
const page = usePage();

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: c.name })));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
const bankAccountOptions = computed(() =>
    props.bankAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} — ${a.name}` : a.name })),
);
const tdsAccountOptions = computed(() =>
    props.tdsAccounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} — ${a.name}` : a.name })),
);
// searchValue lets a barcode match the item even though it isn't shown in
// the option's label - see Combobox.vue's searchText().
const itemOptions = computed(() =>
    props.items.map((i) => ({
        value: i.id,
        label: `${i.name} (${i.unit})`,
        searchValue: i.barcode ? `${i.name} ${i.barcode}` : i.name,
    })),
);
const itemsById = computed(() => Object.fromEntries(props.items.map((i) => [i.id, i])));
const agentOptions = computed(() => props.agents.map((a) => ({ value: a.id, label: a.name })));
const agentsById = computed(() => Object.fromEntries(props.agents.map((a) => [a.id, a])));

// Only the invoice types the tenant has switched on in Settings. The flags
// were saved and never enforced anywhere until this pass; the server rejects
// a disabled type too, this just stops the cashier picking one.
const invoiceTypeOptions = computed(() =>
    [
        { value: 'full', label: 'Full tax invoice', enabled: props.invoiceSettings.sale_full_enabled },
        { value: 'abbreviated', label: 'Abbreviated tax invoice', enabled: props.invoiceSettings.sale_abbreviated_enabled },
        { value: 'pan', label: 'PAN invoice', enabled: props.invoiceSettings.sale_pan_enabled },
    ].filter((option) => option.enabled),
);

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'partial', label: 'Partial (cash + bank)' },
    { value: 'credit', label: 'Credit' },
];

function emptyLine() {
    // item_unit_id '' means "the item's own base unit" (matches this app's
    // existing '' = None convention for optional Select fields, e.g. Items/
    // Index.vue's item_subcategory_id) - transformed to null on submit.
    return { item_id: null, item_unit_id: '', quantity: '', rate: '', discount: '', discount_type: 'flat' };
}

// Options for a line's unit dropdown: the item's own base unit first
// (value '' - always present, even for an item with zero alt units), then
// every active ItemUnit row. Only rendered at all when the item has at
// least one alt unit (see the template) - an item with none shows nothing
// here, unchanged from before this feature existed.
function unitOptionsFor(item) {
    if (!item) return [];

    return [{ value: '', label: item.unit }, ...(item.units ?? []).map((u) => ({ value: u.id, label: u.name }))];
}

// Selecting an alternate unit auto-fills the rate from that unit's own
// sale_rate override; switching back to the base unit ('') restores the
// item's own sale_rate, so a mis-click no longer leaves a Box rate sitting on
// a Piece line. Still freely editable afterwards - Sale::post() only ever
// uses the entered rate, never the unit's.
function selectLineUnit(line, unitId) {
    line.item_unit_id = unitId;

    if (unitId === '' || unitId === null) {
        const item = itemsById.value[line.item_id];
        if (item?.sale_rate != null) line.rate = String(item.sale_rate);

        return;
    }

    const unit = itemsById.value[line.item_id]?.units?.find((u) => u.id === unitId);
    if (unit?.sale_rate != null) {
        line.rate = String(unit.sale_rate);
    }
}

// Changing the item invalidates whatever unit was selected for the
// previous item (a unit id from one item's alt-units list is meaningless
// for another item), so it's reset back to the base unit, and the new
// item's own sale rate is prefilled.
function selectLineItem(line, itemId) {
    line.item_id = itemId;
    line.item_unit_id = '';

    const item = itemsById.value[itemId];
    line.rate = item?.sale_rate != null ? String(item.sale_rate) : '';
}

function defaultFormData() {
    return {
        customer_id: null,
        store_id: props.invoiceSettings.default_store_id ?? null,
        invoice_type: invoiceTypeOptions.value[0]?.value ?? 'full',
        chalani_number: '',
        // Asia/Kathmandu, not UTC: between midnight and 05:45 local time a
        // toISOString() default dated the bill to the previous day.
        date: todayInKathmandu(),
        payment_mode: 'cash',
        bank_account_id: null,
        discount: '',
        discount_type: 'flat',
        cash_amount: '',
        bank_amount: '',
        tds_account_id: null,
        tds_amount: '',
        agent_id: null,
        commission_amount: '',
        narration: '',
        lines: [emptyLine()],
    };
}

const form = useForm(props.initialDraft ?? defaultFormData());

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

// --- Totals: the single preview, mirroring the server step for step --------

const isPanInvoice = computed(() => form.invoice_type === 'pan');

// A PAN invoice carries no VAT at all; every other type uses the tenant's
// configured rate. The rate is never editable here - the server ignores any
// rate the browser sends and always reads CompanySetting::default_vat_rate.
const effectiveVatRate = computed(() => (isPanInvoice.value ? '0.00' : String(props.invoiceSettings.default_vat_rate ?? '0')));

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
const previewError = computed(() => (preview.value.ok || !hasEnteredLines.value ? null : preview.value.message));

/** A line's own total, or null while the line is still incomplete. */
function lineTotal(index) {
    return totals.value ? totals.value.lines[index].line_total : null;
}

function lineDiscountAmount(index) {
    return totals.value ? totals.value.lines[index].discount_amount : null;
}

/**
 * Switching a discount between % and Rs.
 *
 * Percentage to flat is exact: the calculator already knows the rupee amount
 * that percentage came to. The other direction is not - recovering a
 * percentage from an amount needs a division that the money module
 * deliberately does not offer - so the field is cleared and the cashier types
 * the percentage they mean, instead of a silently wrong number being carried
 * across.
 */
function toggleLineDiscountType(index) {
    const line = form.lines[index];

    if (line.discount_type === 'percentage') {
        const amount = lineDiscountAmount(index);
        line.discount = amount && amount !== '0.00' ? amount : '';
        line.discount_type = 'flat';

        return;
    }

    line.discount = '';
    line.discount_type = 'percentage';
}

function toggleHeaderDiscountType() {
    if (form.discount_type === 'percentage') {
        const amount = totals.value?.header_discount;
        form.discount = amount && amount !== '0.00' ? amount : '';
        form.discount_type = 'flat';

        return;
    }

    form.discount = '';
    form.discount_type = 'percentage';
}

const showBankField = computed(() => form.payment_mode === 'bank' || form.payment_mode === 'partial');
const showPartialFields = computed(() => form.payment_mode === 'partial');

/** A user-typed amount as a canonical 2dp string, or null when it is not a valid amount. */
function enteredAmount(value) {
    const parsed = parseMoney(value === '' || value === null || value === undefined ? '0' : value);

    return parsed.ok ? parsed.value : null;
}

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

function selectAgent(agentId) {
    form.agent_id = agentId;

    if (!agentId) {
        form.commission_amount = '';
        return;
    }

    const agent = agentsById.value[agentId];
    if (agent?.commission_rate && totals.value) {
        form.commission_amount = percentOf(totals.value.total, String(agent.commission_rate));
    }
}

const canSubmit = computed(
    () =>
        !form.processing &&
        !!form.customer_id &&
        !!form.date &&
        !!totals.value &&
        (!showPartialFields.value || partialBalanced.value),
);

function submit(print = false) {
    if (!totals.value) return;

    const expectedTotal = totals.value.total;

    form.transform((data) => ({
        ...data,
        discount: data.discount === '' ? '0' : data.discount,
        cash_amount: data.payment_mode === 'partial' ? (data.cash_amount === '' ? '0' : data.cash_amount) : undefined,
        bank_amount: data.payment_mode === 'partial' ? (data.bank_amount === '' ? '0' : data.bank_amount) : undefined,
        tds_amount: data.tds_amount === '' ? '0' : data.tds_amount,
        commission_amount: data.agent_id ? (data.commission_amount === '' ? '0' : data.commission_amount) : undefined,
        expected_total: expectedTotal,
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            item_unit_id: line.item_unit_id || null,
            quantity: line.quantity,
            rate: line.rate,
            discount: line.discount === '' ? '0' : line.discount,
            discount_type: line.discount_type,
        })),
    })).post('/sales', {
        preserveScroll: true,
        onSuccess: () => {
            // C11: the server flashes exactly which document it just created,
            // so this opens that bill's print view instead of guessing the
            // newest id out of the list it was redirected to.
            const created = page.props.flash?.created;
            if (print && created?.print_url) {
                window.open(created.print_url, '_blank');
            }
            emit('posted');
        },
    });
}

// --- Enter key: advance, never submit --------------------------------------
// Legacy adds the next line on Enter; here Enter submitted the whole bill from
// the Qty field (audit P1 "Workflow"). Enter now walks the line's fields left
// to right and, past the last one, starts a new line.
const LINE_FIELDS = ['quantity', 'rate', 'discount'];
const linesEl = ref(null);

function focusLineField(index, field) {
    const target = linesEl.value?.querySelector(`[data-line-field="${field}-${index}"] input`);
    target?.focus();
    target?.select?.();
}

function onLineEnter(index, field) {
    const position = LINE_FIELDS.indexOf(field);

    if (position < LINE_FIELDS.length - 1) {
        focusLineField(index, LINE_FIELDS[position + 1]);
        return;
    }

    if (index === form.lines.length - 1) {
        addLine();
    }

    setTimeout(() => focusLineField(index + 1, LINE_FIELDS[0]), 0);
}

// --- Inline "+ New customer" ------------------------------------------
// Ports Pos.vue's openCustomerModal()/submitCustomer() pattern: POST
// /customers always redirects to the customers index, so this bounces back
// to /sales and best-effort auto-selects the new customer by matching
// name/mobile against the freshly reloaded customers list. Unlike Pos.vue
// (a standalone page whose in-progress cart already survives a remount via
// localStorage), this form is a child of Sales/Index.vue that unmounts
// entirely while showCreateForm is false - so the in-progress draft is
// stashed alongside the pending-customer marker and handed back in via the
// initialDraft prop once Index.vue reopens this form (see its onMounted).
const DRAFT_KEY = 'sales-create-draft';
const PENDING_CUSTOMER_KEY = 'sales-create-pending-customer';

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
    const pendingCustomer = { name: customerForm.name, mobile_no: customerForm.mobile_no };

    customerForm.post('/customers', {
        onSuccess: () => {
            try {
                sessionStorage.setItem(DRAFT_KEY, JSON.stringify(form.data()));
                sessionStorage.setItem(PENDING_CUSTOMER_KEY, JSON.stringify(pendingCustomer));
            } catch {
                // Storage unavailable - the modal still worked, the draft just
                // won't survive the bounce back to /sales.
            }
            customerModalOpen.value = false;
            router.visit('/sales');
        },
    });
}

function applyPendingCustomer() {
    let raw;
    try {
        raw = sessionStorage.getItem(PENDING_CUSTOMER_KEY);
    } catch {
        return;
    }
    if (!raw) return;

    try {
        sessionStorage.removeItem(PENDING_CUSTOMER_KEY);
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

onMounted(() => applyPendingCustomer());
</script>

<template>
    <div>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New sale</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>
        <p v-if="form.errors.expected_total" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.expected_total }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit(false)">
            <div class="grid grid-cols-4 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Customer <span class="text-danger">*</span></label>
                    <div class="flex gap-2">
                        <Combobox
                            :model-value="form.customer_id"
                            :options="customerOptions"
                            placeholder="Select customer"
                            class="flex-1"
                            @update:model-value="(v) => (form.customer_id = v)"
                        />
                        <Button variant="secondary" tone="purple" type="button" class="!px-2.5" @click="openCustomerModal">
                            <Plus class="h-3.5 w-3.5" />
                        </Button>
                    </div>
                    <p v-if="form.errors.customer_id" class="mt-1 text-sm text-danger">{{ form.errors.customer_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Invoice type <span class="text-danger">*</span></label>
                    <Select v-model="form.invoice_type" :options="invoiceTypeOptions" />
                    <p v-if="form.errors.invoice_type" class="mt-1 text-sm text-danger">{{ form.errors.invoice_type }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Store</label>
                    <Combobox
                        :model-value="form.store_id"
                        :options="storeOptions"
                        placeholder="Default store"
                        @update:model-value="(v) => (form.store_id = v)"
                    />
                    <p v-if="form.errors.store_id" class="mt-1 text-sm text-danger">{{ form.errors.store_id }}</p>
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Chalani number</label>
                <Input v-model="form.chalani_number" type="text" placeholder="Optional" class="max-w-[220px]" />
                <p v-if="form.errors.chalani_number" class="mt-1 text-sm text-danger">{{ form.errors.chalani_number }}</p>
            </div>

            <div ref="linesEl">
                <div class="mb-2 grid grid-cols-[1fr_90px_100px_100px_90px_40px_100px_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Unit</span>
                    <span>Quantity</span>
                    <span>Rate</span>
                    <span>Discount</span>
                    <span></span>
                    <span class="text-right">Total</span>
                    <span></span>
                </div>

                <div v-for="(line, index) in form.lines" :key="index" class="mb-2 grid grid-cols-[1fr_90px_100px_100px_90px_40px_100px_28px] items-start gap-2">
                    <div>
                        <Combobox
                            :model-value="line.item_id"
                            :options="itemOptions"
                            placeholder="Select item"
                            @update:model-value="(v) => selectLineItem(line, v)"
                        />
                        <!-- Stock is always quoted in the item's own base unit
                             with that unit named, plus the conversion factor
                             when the line is entered in an alternate unit -
                             the cashier can see both numbers instead of a
                             base-unit figure silently labelled as Boxes. -->
                        <p v-if="itemsById[line.item_id]?.current_stock != null" class="mt-1 text-xs text-text-muted">
                            Stock: {{ formatQuantity(itemsById[line.item_id].current_stock) }} {{ itemsById[line.item_id].unit }}
                            <span v-if="line.item_unit_id">
                                (1 {{ itemsById[line.item_id].units.find((u) => u.id === line.item_unit_id)?.name }} =
                                {{ formatQuantity(itemsById[line.item_id].units.find((u) => u.id === line.item_unit_id)?.conversion_factor ?? 1) }}
                                {{ itemsById[line.item_id].unit }})
                            </span>
                        </p>
                        <p v-if="form.errors[`lines.${index}.item_id`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`lines.${index}.item_id`] }}
                        </p>
                    </div>
                    <div>
                        <Select
                            v-if="itemsById[line.item_id]?.units?.length"
                            :model-value="line.item_unit_id"
                            :options="unitOptionsFor(itemsById[line.item_id])"
                            @update:model-value="(v) => selectLineUnit(line, v)"
                        />
                        <span v-else class="block pt-2 text-xs text-text-muted">{{ itemsById[line.item_id]?.unit ?? '—' }}</span>
                    </div>
                    <!-- No min="0": a negative quantity is a valid in-bill
                         return/adjustment line (see SaleController::store()'s
                         validation comment). -->
                    <div :data-line-field="`quantity-${index}`">
                        <Input
                            v-model="line.quantity"
                            type="number"
                            step="0.0001"
                            placeholder="0"
                            required
                            @keydown.enter.prevent="onLineEnter(index, 'quantity')"
                        />
                        <p v-if="form.errors[`lines.${index}.quantity`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`lines.${index}.quantity`] }}
                        </p>
                    </div>
                    <div :data-line-field="`rate-${index}`">
                        <Input
                            v-model="line.rate"
                            type="number"
                            min="0"
                            step="0.0001"
                            placeholder="0.00"
                            required
                            @keydown.enter.prevent="onLineEnter(index, 'rate')"
                        />
                        <p v-if="form.errors[`lines.${index}.rate`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`lines.${index}.rate`] }}
                        </p>
                    </div>
                    <div :data-line-field="`discount-${index}`">
                        <Input
                            v-model="line.discount"
                            type="number"
                            min="0"
                            step="0.01"
                            :max="line.discount_type === 'percentage' ? 100 : undefined"
                            :placeholder="line.discount_type === 'percentage' ? '%' : 'Rs'"
                            @keydown.enter.prevent="onLineEnter(index, 'discount')"
                        />
                    </div>
                    <button
                        type="button"
                        class="flex h-9 w-full items-center justify-center border-[1.5px] border-border bg-bg-subtle text-[10px] font-bold text-text-muted hover:border-primary hover:text-primary"
                        title="Click to switch between % and Rs discount"
                        @click="toggleLineDiscountType(index)"
                    >
                        {{ line.discount_type === 'percentage' ? '%' : 'Rs' }}
                    </button>
                    <span class="block pt-2 text-right text-[13px] font-semibold text-text-strong">
                        {{ lineTotal(index) === null ? '—' : formatMoney(lineTotal(index)) }}
                    </span>
                    <button
                        v-if="form.lines.length > 1"
                        type="button"
                        class="mt-2 flex h-7 w-7 items-center justify-center text-text-muted transition-colors duration-150 hover:text-danger"
                        aria-label="Remove line"
                        @click="removeLine(index)"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <Button variant="secondary" tone="purple" type="button" class="mt-1" @click="addLine">
                    <Plus class="h-3.5 w-3.5" /> Add line
                </Button>
            </div>

            <div class="grid grid-cols-3 gap-4 border-t-[1.5px] border-border pt-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Header discount</label>
                    <div class="flex gap-2">
                        <Input
                            v-model="form.discount"
                            type="number"
                            min="0"
                            step="0.01"
                            :max="form.discount_type === 'percentage' ? 100 : undefined"
                            :placeholder="form.discount_type === 'percentage' ? '%' : '0.00'"
                        />
                        <button
                            type="button"
                            class="flex h-9 w-10 shrink-0 items-center justify-center border-[1.5px] border-border bg-bg-subtle text-[10px] font-bold text-text-muted hover:border-primary hover:text-primary"
                            title="Click to switch between % and Rs discount"
                            @click="toggleHeaderDiscountType"
                        >
                            {{ form.discount_type === 'percentage' ? '%' : 'Rs' }}
                        </button>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">VAT rate (%)</label>
                    <!-- Read-only: the server always uses the tenant's
                         configured rate and ignores anything sent here. -->
                    <p class="flex h-9 items-center border-[1.5px] border-border bg-bg-subtle px-3 text-[13px] font-semibold text-text-muted">
                        {{ formatRate(effectiveVatRate) }}
                        <span v-if="isPanInvoice" class="ml-2 text-xs font-normal">(PAN invoice: no VAT)</span>
                    </p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Payment mode <span class="text-danger">*</span></label>
                    <Select v-model="form.payment_mode" :options="paymentModeOptions" />
                </div>
            </div>

            <div v-if="showBankField" class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank account <span class="text-danger">*</span></label>
                    <Combobox
                        :model-value="form.bank_account_id"
                        :options="bankAccountOptions"
                        placeholder="Select bank account"
                        @update:model-value="(v) => (form.bank_account_id = v)"
                    />
                    <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                </div>
            </div>

            <div v-if="showPartialFields" class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Cash amount <span class="text-danger">*</span></label>
                    <Input v-model="form.cash_amount" type="number" min="0" step="0.01" placeholder="0.00" required />
                    <p v-if="form.errors.cash_amount" class="mt-1 text-sm text-danger">{{ form.errors.cash_amount }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank amount <span class="text-danger">*</span></label>
                    <Input v-model="form.bank_amount" type="number" min="0" step="0.01" placeholder="0.00" required />
                    <p v-if="form.errors.bank_amount" class="mt-1 text-sm text-danger">{{ form.errors.bank_amount }}</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 border-t-[1.5px] border-border pt-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">TDS account (optional)</label>
                    <Combobox
                        :model-value="form.tds_account_id"
                        :options="tdsAccountOptions"
                        placeholder="Select TDS account"
                        @update:model-value="(v) => (form.tds_account_id = v)"
                    />
                    <p v-if="form.errors.tds_account_id" class="mt-1 text-sm text-danger">{{ form.errors.tds_account_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">TDS amount</label>
                    <Input v-model="form.tds_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                    <p v-if="form.errors.tds_amount" class="mt-1 text-sm text-danger">{{ form.errors.tds_amount }}</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 border-t-[1.5px] border-border pt-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Sales agent (optional)</label>
                    <Combobox
                        :model-value="form.agent_id"
                        :options="agentOptions"
                        placeholder="Select agent"
                        @update:model-value="selectAgent"
                    />
                    <p v-if="form.errors.agent_id" class="mt-1 text-sm text-danger">{{ form.errors.agent_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Commission amount</label>
                    <Input v-model="form.commission_amount" type="number" min="0" step="0.01" placeholder="0.00" :disabled="!form.agent_id" />
                    <p v-if="form.errors.commission_amount" class="mt-1 text-sm text-danger">{{ form.errors.commission_amount }}</p>
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                <Input v-model="form.narration" type="text" placeholder="Optional" />
            </div>

            <p v-if="previewError" class="border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
                {{ previewError }}
            </p>

            <!-- Subtotal, Discount, Taxable, Non-taxable, VAT, Total: the same
                 order and the same six figures the printed bill shows, so the
                 cashier can reconcile the screen against the paper line by
                 line instead of having to trust that Taxable + VAT reaches the
                 Total on a mixed bill. -->
            <div v-if="totals" class="grid grid-cols-6 gap-2 border-t-[1.5px] border-border pt-3 text-sm">
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Subtotal</p>
                    <p class="font-bold text-text-strong">{{ formatMoney(addMoney(totals.vatable_subtotal, totals.non_vatable_subtotal)) }}</p>
                </div>
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Discount</p>
                    <p class="font-bold text-text-strong">{{ formatMoney(totals.header_discount) }}</p>
                </div>
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Taxable</p>
                    <p class="font-bold text-text-strong">{{ formatMoney(totals.taxable_amount) }}</p>
                </div>
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Non-taxable</p>
                    <p class="font-bold text-text-strong">{{ formatMoney(totals.nontaxable_amount) }}</p>
                </div>
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">VAT</p>
                    <p class="font-bold text-text-strong">{{ formatMoney(totals.vat_amount) }}</p>
                </div>
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Total</p>
                    <p class="font-bold text-text-strong">{{ formatMoney(totals.total) }}</p>
                </div>
            </div>

            <p v-if="totals && showPartialFields && !partialBalanced" class="text-xs font-semibold text-danger">
                Cash + bank amounts must add up to exactly {{ formatMoney(totals.settlement_due) }}.
            </p>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="secondary" tone="purple" type="button" :disabled="!canSubmit" @click="submit(true)">
                    Save &amp; Print
                </Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="!canSubmit">Create Sale</Button>
            </div>
        </form>
    </Card>

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
    </div>
</template>
