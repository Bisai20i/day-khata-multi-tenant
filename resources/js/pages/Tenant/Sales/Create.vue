<script setup>
import { computed, onMounted, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Modal from '@/components/ui/Modal.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps({
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    agents: { type: Array, default: () => [] },
    // Set by the parent Index page when it bounces back here after the
    // inline "+ New customer" modal redirects away and back (see
    // submitCustomer() below) - restores the in-progress draft that would
    // otherwise be lost when Index re-mounts this component.
    initialDraft: { type: Object, default: null },
});

const emit = defineEmits(['cancel', 'posted']);
const { toast } = useToast();

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: c.name })));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
const accountOptions = computed(() =>
    props.accounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} — ${a.name}` : a.name })),
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

const invoiceTypeOptions = [
    { value: 'full', label: 'Full tax invoice' },
    { value: 'abbreviated', label: 'Abbreviated tax invoice' },
    { value: 'pan', label: 'PAN invoice' },
];

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'partial', label: 'Partial (cash + bank)' },
    { value: 'credit', label: 'Credit' },
];

function emptyLine() {
    return { item_id: null, quantity: '', rate: '', discount: '', discount_type: 'flat' };
}

function defaultFormData() {
    return {
        customer_id: null,
        store_id: null,
        invoice_type: 'full',
        chalani_number: '',
        date: '',
        payment_mode: 'cash',
        bank_account_id: null,
        discount: '',
        discount_type: 'flat',
        vat_rate: '13',
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

// A line's discount can be entered either as a flat Rs amount ("flat") or a
// percentage of that line's own qty*rate base ("percentage") - mirrors
// Pos.vue's %/Rs discount toggle per cart row (toggleLineDiscountType()/
// lineDiscountAmount() there). Only "percentage" is clamped to 0-100. The
// raw value + its type are what's submitted (see submit()'s transform) -
// the server (Sale::post()) computes the actual Rs amount itself now,
// rather than trusting a client-resolved number.
function lineDiscountAmount(line) {
    const qty = Number(line.quantity) || 0;
    const rate = Number(line.rate) || 0;
    const raw = Number(line.discount) || 0;

    if (line.discount_type === 'percentage') {
        return Math.round(qty * rate * (Math.min(100, Math.max(0, raw)) / 100) * 100) / 100;
    }

    return Math.max(0, raw);
}

function toggleLineDiscountType(index) {
    const line = form.lines[index];
    const qty = Number(line.quantity) || 0;
    const rate = Number(line.rate) || 0;
    const base = qty * rate;
    const currentAmount = lineDiscountAmount(line);

    if (line.discount_type === 'percentage') {
        line.discount = currentAmount ? String(currentAmount) : '';
        line.discount_type = 'flat';
    } else {
        const pct = base > 0 ? Math.round((currentAmount / base) * 10000) / 100 : 0;
        line.discount = pct ? String(pct) : '';
        line.discount_type = 'percentage';
    }
}

function toggleHeaderDiscountType() {
    const currentAmount = headerDiscountAmount.value;

    if (form.discount_type === 'percentage') {
        form.discount = currentAmount ? String(currentAmount) : '';
        form.discount_type = 'flat';
    } else {
        const pct = vatableSubtotal.value > 0 ? Math.round((currentAmount / vatableSubtotal.value) * 10000) / 100 : 0;
        form.discount = pct ? String(pct) : '';
        form.discount_type = 'percentage';
    }
}

const lineTotals = computed(() =>
    form.lines.map((line) => {
        const item = itemsById.value[line.item_id];
        const qty = Number(line.quantity) || 0;
        const rate = Number(line.rate) || 0;
        const discountAmount = lineDiscountAmount(line);

        return { vatable: item?.is_vatable ?? false, total: qty * rate - discountAmount };
    }),
);

const vatableSubtotal = computed(() => lineTotals.value.filter((l) => l.vatable).reduce((s, l) => s + l.total, 0));
const nonVatableSubtotal = computed(() => lineTotals.value.filter((l) => !l.vatable).reduce((s, l) => s + l.total, 0));
const headerDiscountAmount = computed(() => {
    const raw = Number(form.discount) || 0;

    if (form.discount_type === 'percentage') {
        return Math.round(vatableSubtotal.value * (Math.min(100, Math.max(0, raw)) / 100) * 100) / 100;
    }

    return Math.max(0, raw);
});
const taxableAmount = computed(() => vatableSubtotal.value - headerDiscountAmount.value);
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

function selectAgent(agentId) {
    form.agent_id = agentId;

    if (!agentId) {
        form.commission_amount = '';
        return;
    }

    const agent = agentsById.value[agentId];
    if (agent?.commission_rate) {
        form.commission_amount = ((Number(agent.commission_rate) * total.value) / 100).toFixed(2);
    }
}

function submit(print = false) {
    form.transform((data) => ({
        ...data,
        discount: Number(data.discount) || 0,
        vat_rate: Number(data.vat_rate) || 0,
        cash_amount: data.payment_mode === 'partial' ? Number(data.cash_amount) || 0 : undefined,
        bank_amount: data.payment_mode === 'partial' ? Number(data.bank_amount) || 0 : undefined,
        tds_amount: Number(data.tds_amount) || 0,
        commission_amount: data.agent_id ? Number(data.commission_amount) || 0 : undefined,
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            quantity: Number(line.quantity) || 0,
            rate: Number(line.rate) || 0,
            discount: Number(line.discount) || 0,
            discount_type: line.discount_type,
        })),
    })).post('/sales', {
        preserveScroll: true,
        onSuccess: (page) => {
            if (print) {
                const newestSaleId = (page.props.sales ?? []).reduce((maxId, s) => Math.max(maxId, s.id), 0);
                if (newestSaleId) window.open(`/sales/${newestSaleId}/print`, '_blank');
            }
            emit('posted');
        },
    });
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

            <div>
                <div class="mb-2 grid grid-cols-[1fr_100px_100px_90px_40px_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Quantity</span>
                    <span>Rate</span>
                    <span>Discount</span>
                    <span></span>
                    <span></span>
                </div>

                <div v-for="(line, index) in form.lines" :key="index" class="mb-2 grid grid-cols-[1fr_100px_100px_90px_40px_28px] items-start gap-2">
                    <div>
                        <Combobox
                            :model-value="line.item_id"
                            :options="itemOptions"
                            placeholder="Select item"
                            @update:model-value="(v) => (line.item_id = v)"
                        />
                        <p v-if="itemsById[line.item_id]?.current_stock !== null && itemsById[line.item_id]?.current_stock !== undefined" class="mt-1 text-xs text-text-muted">
                            Stock: {{ itemsById[line.item_id].current_stock }}
                        </p>
                        <p v-if="form.errors[`lines.${index}.item_id`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`lines.${index}.item_id`] }}
                        </p>
                    </div>
                    <!-- No min="0": a negative quantity is a valid in-bill
                         return/adjustment line (see SaleController::store()'s
                         validation comment) - Input.vue doesn't forward
                         min/step to the real <input> anyway (they'd land on
                         its wrapper <div>), so this was always inert. -->
                    <Input v-model="line.quantity" type="number" step="0.0001" placeholder="0" />
                    <Input v-model="line.rate" type="number" min="0" step="0.01" placeholder="0.00" />
                    <Input
                        v-model="line.discount"
                        type="number"
                        min="0"
                        :max="line.discount_type === 'percentage' ? 100 : undefined"
                        :placeholder="line.discount_type === 'percentage' ? '%' : 'Rs'"
                    />
                    <button
                        type="button"
                        class="flex h-9 w-full items-center justify-center border-[1.5px] border-border bg-bg-subtle text-[10px] font-bold text-text-muted hover:border-primary hover:text-primary"
                        title="Click to switch between % and Rs discount"
                        @click="toggleLineDiscountType(index)"
                    >
                        {{ line.discount_type === 'percentage' ? '%' : 'Rs' }}
                    </button>
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
                    <Input v-model="form.vat_rate" type="number" min="0" step="0.01" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Payment mode <span class="text-danger">*</span></label>
                    <Select v-model="form.payment_mode" :options="paymentModeOptions" />
                </div>
            </div>

            <div v-if="showBankField" class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank account</label>
                    <Combobox
                        :model-value="form.bank_account_id"
                        :options="accountOptions"
                        placeholder="Select bank account"
                        @update:model-value="(v) => (form.bank_account_id = v)"
                    />
                    <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                </div>
            </div>

            <div v-if="showPartialFields" class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Cash amount</label>
                    <Input v-model="form.cash_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank amount</label>
                    <Input v-model="form.bank_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 border-t-[1.5px] border-border pt-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">TDS account (optional)</label>
                    <Combobox
                        :model-value="form.tds_account_id"
                        :options="accountOptions"
                        placeholder="Select TDS account"
                        @update:model-value="(v) => (form.tds_account_id = v)"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">TDS amount</label>
                    <Input v-model="form.tds_amount" type="number" min="0" step="0.01" placeholder="0.00" />
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

            <div class="grid grid-cols-4 gap-2 border-t-[1.5px] border-border pt-3 text-sm">
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Taxable</p>
                    <p class="font-bold text-text-strong">{{ taxableAmount.toFixed(2) }}</p>
                </div>
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Non-taxable</p>
                    <p class="font-bold text-text-strong">{{ nontaxableAmount.toFixed(2) }}</p>
                </div>
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">VAT</p>
                    <p class="font-bold text-text-strong">{{ vatAmount.toFixed(2) }}</p>
                </div>
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Total</p>
                    <p class="font-bold text-text-strong">{{ total.toFixed(2) }}</p>
                </div>
            </div>

            <p v-if="showPartialFields && !partialBalanced" class="text-xs font-semibold text-danger">
                Cash + bank amounts must add up to the settlement due ({{ settlementDue.toFixed(2) }}).
            </p>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button
                    variant="secondary"
                    tone="purple"
                    type="button"
                    :disabled="form.processing || !form.customer_id || !form.date || (showPartialFields && !partialBalanced)"
                    @click="submit(true)"
                >
                    Save &amp; Print
                </Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="submit"
                    :disabled="form.processing || !form.customer_id || !form.date || (showPartialFields && !partialBalanced)"
                >
                    Create Sale
                </Button>
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
