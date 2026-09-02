<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    pools: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

const accountOptions = computed(() =>
    props.accounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} — ${account.name}` : account.name,
    })),
);
const supplierOptions = computed(() => props.suppliers.map((s) => ({ value: s.id, label: s.name })));
const poolOptions = computed(() => props.pools.map((p) => ({ value: p.value, label: p.label })));
const poolsByValue = computed(() => new Map(props.pools.map((p) => [p.value, p])));

const methodOptions = [
    { value: 'slm', label: 'Straight-Line (SLM)' },
    { value: 'wdv', label: 'Written-Down Value (WDV)' },
];

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'credit', label: 'Credit' },
];

const form = useForm({
    asset_name: '',
    category: '',
    purchase_date: '',
    cost: '',
    salvage_value: '',
    depreciation_method: 'wdv',
    depreciation_rate: '',
    payment_mode: 'cash',
    bank_account_id: null,
    supplier_id: null,
    narration: '',
});

// Pre-fills the statutory rate for the chosen pool - stays editable
// afterwards, matching legacy's "convenience pre-fill, not a lock" behavior.
// Pool E has no fixed rate, so it's left for the user to enter.
watch(
    () => form.category,
    (category) => {
        const rate = poolsByValue.value.get(category)?.defaultRate;
        if (rate !== null && rate !== undefined) {
            form.depreciation_rate = String(rate);
        }
    },
);

const showBankAccount = computed(() => form.payment_mode === 'bank');
const showSupplier = computed(() => form.payment_mode === 'credit');

function submit() {
    form.transform((data) => ({
        ...data,
        cost: Number(data.cost) || 0,
        salvage_value: Number(data.salvage_value) || 0,
        depreciation_rate: Number(data.depreciation_rate) || 0,
    })).post('/fixed-assets', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New fixed asset</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Asset Name</label>
                    <Input v-model="form.asset_name" type="text" placeholder="e.g. Office Laptop" required />
                    <p v-if="form.errors.asset_name" class="mt-1 text-sm text-danger">{{ form.errors.asset_name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Depreciation Pool</label>
                    <Select
                        :model-value="form.category"
                        :options="poolOptions"
                        placeholder="Select pool"
                        @update:model-value="(v) => (form.category = v)"
                    />
                    <p v-if="form.errors.category" class="mt-1 text-sm text-danger">{{ form.errors.category }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Purchase Date</label>
                    <NepaliDateInput v-model="form.purchase_date" required />
                    <p v-if="form.errors.purchase_date" class="mt-1 text-sm text-danger">{{ form.errors.purchase_date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Cost</label>
                    <Input v-model="form.cost" type="number" min="0.01" step="0.01" required />
                    <p v-if="form.errors.cost" class="mt-1 text-sm text-danger">{{ form.errors.cost }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Salvage Value</label>
                    <Input v-model="form.salvage_value" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Depreciation Method</label>
                    <Select
                        :model-value="form.depreciation_method"
                        :options="methodOptions"
                        @update:model-value="(v) => (form.depreciation_method = v)"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Depreciation Rate (%)</label>
                    <Input v-model="form.depreciation_rate" type="number" min="0" max="100" step="0.01" required />
                    <p v-if="form.errors.depreciation_rate" class="mt-1 text-sm text-danger">{{ form.errors.depreciation_rate }}</p>
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
                <div v-if="showSupplier">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Supplier</label>
                    <Combobox
                        :model-value="form.supplier_id"
                        :options="supplierOptions"
                        placeholder="Select supplier"
                        @update:model-value="(v) => (form.supplier_id = v)"
                    />
                    <p v-if="form.errors.supplier_id" class="mt-1 text-sm text-danger">{{ form.errors.supplier_id }}</p>
                </div>
                <div class="col-span-3">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                    <Input v-model="form.narration" type="text" placeholder="Optional" />
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing">
                    Add asset
                </Button>
            </div>
        </form>
    </Card>
</template>
