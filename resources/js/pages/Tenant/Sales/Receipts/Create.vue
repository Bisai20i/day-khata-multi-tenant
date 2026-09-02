<script setup>
import { computed, reactive, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';

const props = defineProps({
    customers: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    outstandingSales: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: c.name })));
const accountOptions = computed(() =>
    props.accounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} — ${account.name}` : account.name,
    })),
);

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
];

const form = useForm({
    customer_id: null,
    date: new Date().toISOString().slice(0, 10),
    amount: '',
    payment_mode: 'cash',
    bank_account_id: null,
    reference_number: '',
    narration: '',
});

const showBankAccount = computed(() => form.payment_mode === 'bank');

// Keyed by sale id -> string allocation amount. A separate reactive map
// (not part of `form`, which only holds scalar fields) so the checklist can
// stay keyed by sale id regardless of which customer is currently selected.
const allocationAmounts = reactive({});

const customerSales = computed(() =>
    props.outstandingSales.filter((sale) => sale.customer_id === form.customer_id),
);

// Clear any stale allocations from a previously-selected customer's
// invoices when the customer changes.
watch(
    () => form.customer_id,
    () => {
        for (const key of Object.keys(allocationAmounts)) {
            delete allocationAmounts[key];
        }
    },
);

const totalAllocated = computed(() =>
    customerSales.value.reduce((sum, sale) => sum + (Number(allocationAmounts[sale.id]) || 0), 0),
);

function submit() {
    const allocations = customerSales.value
        .filter((sale) => Number(allocationAmounts[sale.id]) > 0)
        .map((sale) => ({ sale_id: sale.id, amount: Number(allocationAmounts[sale.id]) }));

    form.transform((data) => ({
        ...data,
        amount: Number(data.amount) || 0,
        allocations,
    })).post('/receipts', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New receipt</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid grid-cols-3 gap-4">
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
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date</label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Amount</label>
                    <Input v-model="form.amount" type="number" min="0.01" step="0.01" placeholder="0.00" required />
                    <p v-if="form.errors.amount" class="mt-1 text-sm text-danger">{{ form.errors.amount }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Payment Mode</label>
                    <Select
                        :model-value="form.payment_mode"
                        :options="paymentModeOptions"
                        @update:model-value="(v) => (form.payment_mode = v)"
                    />
                </div>
                <div v-if="showBankAccount">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank Account</label>
                    <Combobox
                        :model-value="form.bank_account_id"
                        :options="accountOptions"
                        placeholder="Select bank account"
                        @update:model-value="(v) => (form.bank_account_id = v)"
                    />
                    <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reference #</label>
                    <Input v-model="form.reference_number" type="text" placeholder="Optional" />
                </div>
                <div class="col-span-3">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                    <Input v-model="form.narration" type="text" placeholder="Optional" />
                </div>
            </div>

            <div v-if="form.customer_id">
                <p class="mb-2 text-sm font-semibold text-text-base">Allocate against outstanding invoices (optional)</p>
                <p v-if="!customerSales.length" class="text-sm text-text-muted">This customer has no outstanding credit sales.</p>

                <div v-else class="flex flex-col gap-2">
                    <div class="grid grid-cols-[100px_1fr_110px_110px_130px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <span>Date</span>
                        <span>Sale</span>
                        <span>Total</span>
                        <span>Outstanding</span>
                        <span>Allocate</span>
                    </div>
                    <div v-for="sale in customerSales" :key="sale.id" class="grid grid-cols-[100px_1fr_110px_110px_130px] items-center gap-2">
                        <span class="text-sm text-text-base">{{ sale.date }}</span>
                        <span class="text-sm text-text-base">Sale #{{ sale.id }}</span>
                        <span class="text-sm text-text-base">{{ Number(sale.total).toFixed(2) }}</span>
                        <span class="text-sm text-text-base">{{ Number(sale.outstanding).toFixed(2) }}</span>
                        <Input v-model="allocationAmounts[sale.id]" type="number" min="0" step="0.01" placeholder="0.00" />
                    </div>
                </div>

                <p v-if="form.errors.allocations" class="mt-2 text-sm text-danger">{{ form.errors.allocations }}</p>
            </div>

            <div class="grid grid-cols-1 gap-2 border-t-[1.5px] border-border pt-3 text-sm">
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Allocated total</p>
                    <p class="font-bold text-text-strong">{{ totalAllocated.toFixed(2) }}</p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing || !form.customer_id">
                    Save receipt
                </Button>
            </div>
        </form>
    </Card>
</template>
