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
    suppliers: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    outstandingPurchases: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

const supplierOptions = computed(() => props.suppliers.map((s) => ({ value: s.id, label: s.name })));
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
    supplier_id: null,
    date: new Date().toISOString().slice(0, 10),
    amount: '',
    payment_mode: 'cash',
    bank_account_id: null,
    reference_number: '',
    narration: '',
});

const showBankAccount = computed(() => form.payment_mode === 'bank');

// Purchase id -> allocation amount string. Reset whenever the supplier
// changes so a stale allocation against a different supplier's invoice can
// never be silently submitted.
const allocationAmounts = reactive({});

watch(
    () => form.supplier_id,
    () => {
        for (const key of Object.keys(allocationAmounts)) delete allocationAmounts[key];
    },
);

const supplierPurchases = computed(() =>
    props.outstandingPurchases.filter((purchase) => purchase.supplier_id === form.supplier_id),
);

const totalAllocated = computed(() =>
    Object.values(allocationAmounts).reduce((sum, value) => sum + (Number(value) || 0), 0),
);

function submit() {
    const allocations = Object.entries(allocationAmounts)
        .filter(([, amount]) => Number(amount) > 0)
        .map(([purchase_id, amount]) => ({ purchase_id: Number(purchase_id), amount: Number(amount) }));

    form.transform((data) => ({
        ...data,
        amount: Number(data.amount) || 0,
        allocations,
    })).post('/payments', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New payment</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Supplier</label>
                    <Combobox
                        :model-value="form.supplier_id"
                        :options="supplierOptions"
                        placeholder="Select supplier"
                        @update:model-value="(v) => (form.supplier_id = v)"
                    />
                    <p v-if="form.errors.supplier_id" class="mt-1 text-sm text-danger">{{ form.errors.supplier_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date</label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Amount</label>
                    <Input v-model="form.amount" type="number" min="0.01" step="0.01" required />
                    <p v-if="form.errors.amount" class="mt-1 text-sm text-danger">{{ form.errors.amount }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Mode</label>
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

            <div v-if="form.supplier_id" class="border-t-[1.5px] border-border pt-4">
                <p class="mb-2 text-sm font-semibold text-text-base">Allocate against outstanding bills (optional)</p>
                <p v-if="supplierPurchases.length === 0" class="text-sm text-text-muted">No outstanding bills for this supplier.</p>

                <div v-else class="flex flex-col gap-2">
                    <div class="grid grid-cols-[100px_1fr_110px_110px_130px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <span>Date</span>
                        <span>Bill #</span>
                        <span>Total</span>
                        <span>Outstanding</span>
                        <span>Allocate</span>
                    </div>
                    <div
                        v-for="purchase in supplierPurchases"
                        :key="purchase.id"
                        class="grid grid-cols-[100px_1fr_110px_110px_130px] items-center gap-2"
                    >
                        <span class="text-sm text-text-base">{{ purchase.date }}</span>
                        <span class="text-sm text-text-base">Purchase #{{ purchase.id }}</span>
                        <span class="text-sm text-text-base">{{ purchase.total.toFixed(2) }}</span>
                        <span class="text-sm text-text-base">{{ purchase.outstanding.toFixed(2) }}</span>
                        <Input
                            v-model="allocationAmounts[purchase.id]"
                            type="number"
                            min="0"
                            :max="purchase.outstanding"
                            step="0.01"
                            placeholder="0.00"
                        />
                    </div>
                </div>

                <p v-if="form.errors.allocations" class="mt-2 text-sm text-danger">{{ form.errors.allocations }}</p>
                <p class="mt-2 text-xs text-text-muted">
                    Allocated so far: {{ totalAllocated.toFixed(2) }} of {{ (Number(form.amount) || 0).toFixed(2) }}. Any
                    unallocated amount is recorded on account and won't reduce a specific bill's outstanding balance.
                </p>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing || !form.supplier_id">
                    Record payment
                </Button>
            </div>
        </form>
    </Card>
</template>
