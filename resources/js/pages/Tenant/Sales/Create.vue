<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';

const props = defineProps({
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    agents: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: c.name })));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
const accountOptions = computed(() =>
    props.accounts.map((a) => ({ value: a.id, label: a.code ? `${a.code} — ${a.name}` : a.name })),
);
const itemOptions = computed(() => props.items.map((i) => ({ value: i.id, label: `${i.name} (${i.unit})` })));
const itemsById = computed(() => Object.fromEntries(props.items.map((i) => [i.id, i])));
const agentOptions = computed(() => props.agents.map((a) => ({ value: a.id, label: a.name })));
const agentsById = computed(() => Object.fromEntries(props.agents.map((a) => [a.id, a])));

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

function emptyLine() {
    return { item_id: null, quantity: '', rate: '', discount: '' };
}

const form = useForm({
    customer_id: null,
    store_id: null,
    invoice_type: 'full',
    date: '',
    payment_mode: 'cash',
    bank_account_id: null,
    discount: '',
    vat_rate: '13',
    cash_amount: '',
    bank_amount: '',
    tds_account_id: null,
    tds_amount: '',
    agent_id: null,
    commission_amount: '',
    narration: '',
    lines: [emptyLine()],
});

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
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

function submit() {
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
        })),
    })).post('/sales', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New sale</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid grid-cols-4 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Customer</label>
                    <Combobox
                        :model-value="form.customer_id"
                        :options="customerOptions"
                        placeholder="Select customer"
                        @update:model-value="(v) => (form.customer_id = v)"
                    />
                    <p v-if="form.errors.customer_id" class="mt-1 text-sm text-danger">{{ form.errors.customer_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Invoice type</label>
                    <Select v-model="form.invoice_type" :options="invoiceTypeOptions" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date</label>
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
                <div class="mb-2 grid grid-cols-[1fr_110px_110px_100px_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Quantity</span>
                    <span>Rate</span>
                    <span>Discount</span>
                    <span></span>
                </div>

                <div v-for="(line, index) in form.lines" :key="index" class="mb-2 grid grid-cols-[1fr_110px_110px_100px_28px] items-start gap-2">
                    <div>
                        <Combobox
                            :model-value="line.item_id"
                            :options="itemOptions"
                            placeholder="Select item"
                            @update:model-value="(v) => (line.item_id = v)"
                        />
                        <p v-if="form.errors[`lines.${index}.item_id`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`lines.${index}.item_id`] }}
                        </p>
                    </div>
                    <Input v-model="line.quantity" type="number" min="0" step="0.0001" placeholder="0" />
                    <Input v-model="line.rate" type="number" min="0" step="0.01" placeholder="0.00" />
                    <Input v-model="line.discount" type="number" min="0" step="0.01" placeholder="0.00" />
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
                    <Input v-model="form.discount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">VAT rate (%)</label>
                    <Input v-model="form.vat_rate" type="number" min="0" step="0.01" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Payment mode</label>
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
                    <Input v-model="form.cash_amount" type="number" min="0" step="0.01" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank amount</label>
                    <Input v-model="form.bank_amount" type="number" min="0" step="0.01" />
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
                    variant="primary"
                    tone="purple"
                    type="submit"
                    :disabled="form.processing || !form.customer_id || !form.date || (showPartialFields && !partialBalanced)"
                >
                    Post sale
                </Button>
            </div>
        </form>
    </Card>
</template>
