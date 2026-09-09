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
    accounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

const customerOptions = computed(() => props.customers.map((c) => ({ value: c.id, label: c.name })));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
const accountOptions = computed(() =>
    props.accounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} — ${account.name}` : account.name,
    })),
);

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'partial', label: 'Partial (Cash + Bank)' },
    { value: 'credit', label: 'Credit' },
];

function emptyLine() {
    return { account_id: null, amount: '', narration: '' };
}

const form = useForm({
    customer_id: null,
    store_id: null,
    date: '',
    narration: '',
    payment_mode: 'cash',
    bank_account_id: null,
    cash_amount: '',
    bank_amount: '',
    vat_amount: '',
    lines: [emptyLine()],
});

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

const subtotal = computed(() => form.lines.reduce((sum, line) => sum + (Number(line.amount) || 0), 0));
const vatAmountNumber = computed(() => Number(form.vat_amount) || 0);
const grandTotal = computed(() => subtotal.value + vatAmountNumber.value);

const showBankAccount = computed(() => form.payment_mode === 'bank' || form.payment_mode === 'partial');
const showPartialSplit = computed(() => form.payment_mode === 'partial');
const customerRequired = computed(() => form.payment_mode === 'credit' || form.payment_mode === 'partial');

function submit() {
    form.transform((data) => ({
        ...data,
        vat_amount: Number(data.vat_amount) || 0,
        cash_amount: data.payment_mode === 'partial' ? Number(data.cash_amount) || 0 : undefined,
        bank_amount: data.payment_mode === 'partial' ? Number(data.bank_amount) || 0 : undefined,
        lines: data.lines.map((line) => ({
            account_id: line.account_id,
            amount: Number(line.amount) || 0,
            narration: line.narration || undefined,
        })),
    })).post('/capital-sales', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New capital sale</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">
                        Customer <span v-if="customerRequired" class="text-danger">*</span>
                    </label>
                    <Combobox
                        :model-value="form.customer_id"
                        :options="customerOptions"
                        placeholder="Optional unless credit/partial"
                        @update:model-value="(v) => (form.customer_id = v)"
                    />
                    <p v-if="form.errors.customer_id" class="mt-1 text-sm text-danger">{{ form.errors.customer_id }}</p>
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
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Payment Mode <span class="text-danger">*</span></label>
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
            </div>

            <div v-if="showPartialSplit" class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Cash Amount</label>
                    <Input v-model="form.cash_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank Amount</label>
                    <Input v-model="form.bank_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
            </div>

            <div>
                <div class="mb-2 grid grid-cols-[1fr_130px_1fr_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Account</span>
                    <span>Amount</span>
                    <span>Narration</span>
                    <span></span>
                </div>

                <div v-for="(line, index) in form.lines" :key="index" class="mb-2 grid grid-cols-[1fr_130px_1fr_28px] items-start gap-2">
                    <div>
                        <Combobox
                            :model-value="line.account_id"
                            :options="accountOptions"
                            placeholder="Select account"
                            @update:model-value="(v) => (line.account_id = v)"
                        />
                        <p v-if="form.errors[`lines.${index}.account_id`]" class="mt-1 text-xs text-danger">
                            {{ form.errors[`lines.${index}.account_id`] }}
                        </p>
                    </div>
                    <Input v-model="line.amount" type="number" min="0" step="0.01" placeholder="0.00" />
                    <Input v-model="line.narration" type="text" placeholder="Optional" />
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
                    <label class="mb-1 block text-sm font-semibold text-text-base">VAT Amount</label>
                    <Input v-model="form.vat_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
                <div class="col-span-2">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                    <Input v-model="form.narration" type="text" placeholder="Optional" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t-[1.5px] border-border pt-3 text-sm">
                <span class="text-text-muted">Subtotal</span>
                <span class="text-right font-semibold text-text-strong">{{ subtotal.toFixed(2) }}</span>
                <span class="text-text-muted">VAT</span>
                <span class="text-right font-semibold text-text-strong">{{ vatAmountNumber.toFixed(2) }}</span>
                <span class="font-bold text-text-strong">Grand Total</span>
                <span class="text-right font-bold text-text-strong">{{ grandTotal.toFixed(2) }}</span>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing">
                    Create Capital Sale
                </Button>
            </div>
        </form>
    </Card>
</template>
