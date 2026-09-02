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
    suppliers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

const supplierOptions = computed(() => props.suppliers.map((s) => ({ value: s.id, label: s.name })));
const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));
const itemOptions = computed(() => props.items.map((i) => ({ value: i.id, label: `${i.name} (${i.unit})` })));
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

const itemsById = computed(() => new Map(props.items.map((i) => [i.id, i])));

function emptyLine() {
    return { item_id: null, quantity: '', rate: '', discount: '' };
}

const form = useForm({
    supplier_id: null,
    store_id: null,
    bill_number: '',
    pan_number: '',
    date: '',
    payment_mode: 'credit',
    bank_account_id: null,
    discount: '',
    vat_rate: '13',
    cash_amount: '',
    bank_amount: '',
    tds_account_id: null,
    tds_amount: '',
    narration: '',
    lines: [emptyLine()],
});

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

function lineTotal(line) {
    const qty = Number(line.quantity) || 0;
    const rate = Number(line.rate) || 0;
    const discount = Number(line.discount) || 0;
    return qty * rate - discount;
}

function isVatable(line) {
    return itemsById.value.get(line.item_id)?.is_vatable ?? false;
}

const vatableSubtotal = computed(() =>
    form.lines.filter(isVatable).reduce((sum, line) => sum + lineTotal(line), 0),
);
const nonVatableSubtotal = computed(() =>
    form.lines.filter((line) => !isVatable(line)).reduce((sum, line) => sum + lineTotal(line), 0),
);
const headerDiscount = computed(() => Number(form.discount) || 0);
const taxableAmount = computed(() => vatableSubtotal.value - headerDiscount.value);
const vatAmount = computed(() => (taxableAmount.value * (Number(form.vat_rate) || 0)) / 100);
const grandTotal = computed(() => taxableAmount.value + nonVatableSubtotal.value + vatAmount.value);
const tdsAmountNumber = computed(() => Number(form.tds_amount) || 0);
const settlementDue = computed(() => grandTotal.value - tdsAmountNumber.value);

const showBankAccount = computed(() => form.payment_mode === 'bank' || form.payment_mode === 'partial');
const showPartialSplit = computed(() => form.payment_mode === 'partial');

function submit() {
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
    })).post('/purchases', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-text-strong">New purchase</h3>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <p v-if="form.errors.lines" class="mb-4 border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger">
            {{ form.errors.lines }}
        </p>

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
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bill Number</label>
                    <Input v-model="form.bill_number" type="text" placeholder="Supplier's bill no." />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">PAN Number</label>
                    <Input v-model="form.pan_number" type="text" placeholder="Optional" />
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
            </div>

            <div v-if="showPartialSplit" class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Cash Amount</label>
                    <Input v-model="form.cash_amount" type="number" min="0" step="0.01" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank Amount</label>
                    <Input v-model="form.bank_amount" type="number" min="0" step="0.01" />
                </div>
            </div>

            <div>
                <div class="mb-2 grid grid-cols-[1fr_110px_110px_110px_90px_28px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <span>Item</span>
                    <span>Qty</span>
                    <span>Rate</span>
                    <span>Discount</span>
                    <span>Total</span>
                    <span></span>
                </div>

                <div v-for="(line, index) in form.lines" :key="index" class="mb-2 grid grid-cols-[1fr_110px_110px_110px_90px_28px] items-start gap-2">
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
                    <span class="pt-2 text-right text-sm font-semibold text-text-strong">{{ lineTotal(line).toFixed(2) }}</span>
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
                    <label class="mb-1 block text-sm font-semibold text-text-base">Header Discount</label>
                    <Input v-model="form.discount" type="number" min="0" step="0.01" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">VAT Rate (%)</label>
                    <Input v-model="form.vat_rate" type="number" min="0" step="0.01" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                    <Input v-model="form.narration" type="text" placeholder="Optional" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">TDS Account</label>
                    <Combobox
                        :model-value="form.tds_account_id"
                        :options="accountOptions"
                        placeholder="Optional"
                        @update:model-value="(v) => (form.tds_account_id = v)"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">TDS Amount</label>
                    <Input v-model="form.tds_amount" type="number" min="0" step="0.01" placeholder="0.00" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t-[1.5px] border-border pt-3 text-sm">
                <span class="text-text-muted">Taxable Amount</span>
                <span class="text-right font-semibold text-text-strong">{{ taxableAmount.toFixed(2) }}</span>
                <span class="text-text-muted">Non-Taxable Amount</span>
                <span class="text-right font-semibold text-text-strong">{{ nonVatableSubtotal.toFixed(2) }}</span>
                <span class="text-text-muted">VAT</span>
                <span class="text-right font-semibold text-text-strong">{{ vatAmount.toFixed(2) }}</span>
                <span class="font-bold text-text-strong">Grand Total</span>
                <span class="text-right font-bold text-text-strong">{{ grandTotal.toFixed(2) }}</span>
                <template v-if="tdsAmountNumber > 0">
                    <span class="text-text-muted">Amount Due (after TDS)</span>
                    <span class="text-right font-semibold text-text-strong">{{ settlementDue.toFixed(2) }}</span>
                </template>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="form.processing">
                    Post purchase
                </Button>
            </div>
        </form>
    </Card>
</template>
