<script setup>
import { computed, reactive, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { compareMoney, formatMoney, parseMoney, sumMoney } from '@/lib/money';
import { formatBsDate, todayInKathmandu } from '@/lib/format';

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
    // Only accounts money can actually leave through, filtered server-side.
    bankAccounts: { type: Array, default: () => [] },
    outstandingPurchases: { type: Array, default: () => [] },
});

const emit = defineEmits(['cancel', 'posted']);

const supplierOptions = computed(() => props.suppliers.map((s) => ({ value: s.id, label: s.name })));
const bankAccountOptions = computed(() =>
    props.bankAccounts.map((account) => ({
        value: account.id,
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
];

const form = useForm({
    supplier_id: null,
    // todayInKathmandu(), never new Date().toISOString(): between midnight and
    // 05:45 Nepal time the UTC day is still yesterday, which dated every early
    // morning payment a day early.
    date: todayInKathmandu(),
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

/**
 * The allocation rows the user has actually filled in, as validated 2dp
 * strings. Anything half-typed is simply ignored until it parses, so the
 * running total never shows a number the server would refuse.
 */
const validAllocations = computed(() =>
    Object.entries(allocationAmounts)
        .map(([purchaseId, amount]) => ({ purchaseId: Number(purchaseId), parsed: parseMoney(amount) }))
        .filter((row) => row.parsed.ok && compareMoney(row.parsed.value, '0.00') > 0)
        .map((row) => ({ purchase_id: row.purchaseId, amount: row.parsed.value })),
);

const totalAllocated = computed(() => sumMoney(validAllocations.value.map((row) => row.amount)));

const paymentAmount = computed(() => {
    const parsed = parseMoney(form.amount);

    return parsed.ok ? parsed.value : '0.00';
});

// Exact, no tolerance: the old form let an over-allocation through and the
// server used to accept anything within a paisa of the amount (audit P0-4).
const overAllocated = computed(() => compareMoney(totalAllocated.value, paymentAmount.value) > 0);

function submit() {
    form.transform((data) => ({
        ...data,
        // The typed string, not Number(): the server's decimal rules reject
        // over-precise input rather than letting MySQL round it away.
        amount: data.amount,
        allocations: validAllocations.value,
    })).post('/payments', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <PageHeader title="New supplier payment" description="Record money paid to a supplier, and optionally apply it to their outstanding bills. Fields marked * are required.">
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </PageHeader>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <h4 class="border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Supplier &amp; payment</h4>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Supplier <span class="text-danger">*</span></label>
                    <Combobox
                        :model-value="form.supplier_id"
                        :options="supplierOptions"
                        placeholder="Select supplier"
                        @update:model-value="(v) => (form.supplier_id = v)"
                    />
                    <p v-if="form.errors.supplier_id" class="mt-1 text-sm text-danger">{{ form.errors.supplier_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Payment date (BS) <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Amount paid (Rs.) <span class="text-danger">*</span></label>
                    <Input v-model="form.amount" type="number" min="0.01" step="0.01" placeholder="0.00" required />
                    <p v-if="form.errors.amount" class="mt-1 text-sm text-danger">{{ form.errors.amount }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Paid by <span class="text-danger">*</span></label>
                    <Select
                        :model-value="form.payment_mode"
                        :options="paymentModeOptions"
                        @update:model-value="(v) => (form.payment_mode = v)"
                    />
                </div>
                <div v-if="showBankAccount">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank account <span class="text-danger">*</span></label>
                    <Combobox
                        :model-value="form.bank_account_id"
                        :options="bankAccountOptions"
                        placeholder="Select bank account"
                        @update:model-value="(v) => (form.bank_account_id = v)"
                    />
                    <p v-if="form.errors.bank_account_id" class="mt-1 text-sm text-danger">{{ form.errors.bank_account_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reference number</label>
                    <Input v-model="form.reference_number" type="text" maxlength="255" placeholder="Cheque or transfer number (optional)" />
                </div>
                <div class="sm:col-span-3">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Notes (narration)</label>
                    <Input v-model="form.narration" type="text" maxlength="255" placeholder="Optional note kept with this payment" />
                </div>
            </div>

            <div v-if="form.supplier_id" class="border-t-[1.5px] border-border pt-4">
                <h4 class="mb-2 border-b-[1.5px] border-border pb-1 text-sm font-bold text-text-strong">Apply to outstanding bills (optional)</h4>
                <p v-if="supplierPurchases.length === 0" class="text-sm text-text-muted">No outstanding bills for this supplier.</p>

                <div v-else class="flex flex-col gap-2">
                    <div class="grid grid-cols-[120px_1fr_110px_110px_130px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <span>Date (BS)</span>
                        <span>Supplier bill no.</span>
                        <span>Bill total</span>
                        <span>Still owed</span>
                        <span>Apply (Rs.)</span>
                    </div>
                    <div
                        v-for="purchase in supplierPurchases"
                        :key="purchase.id"
                        class="grid grid-cols-[120px_1fr_110px_110px_130px] items-center gap-2"
                    >
                        <span class="text-sm text-text-base">{{ formatBsDate(purchase.date) }}</span>
                        <span class="text-sm text-text-base">
                            {{ purchase.bill_number ? purchase.bill_number : `Purchase #${purchase.id}` }}
                        </span>
                        <span class="text-sm text-text-base">{{ formatMoney(purchase.total) }}</span>
                        <span class="text-sm text-text-base">{{ formatMoney(purchase.outstanding) }}</span>
                        <Input
                            v-model="allocationAmounts[purchase.id]"
                            type="number"
                            min="0"
                            :max="purchase.outstanding"
                            step="0.01"
                            placeholder="0.00"
                            :aria-label="`Amount to apply to bill ${purchase.bill_number ? purchase.bill_number : `Purchase #${purchase.id}`}`"
                        />
                    </div>
                </div>

                <p v-if="form.errors.allocations" class="mt-2 text-sm text-danger">{{ form.errors.allocations }}</p>
                <p v-if="overAllocated" class="mt-2 text-sm text-danger">
                    Allocated {{ formatMoney(totalAllocated) }} is more than the payment of {{ formatMoney(paymentAmount) }}.
                </p>
                <p class="mt-2 text-xs text-text-muted">
                    Allocated so far: {{ formatMoney(totalAllocated) }} of {{ formatMoney(paymentAmount) }}. Any
                    unallocated amount is recorded on account and won't reduce a specific bill's outstanding balance.
                </p>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="submit"
                    :loading="form.processing"
                    :disabled="form.processing || !form.supplier_id || overAllocated"
                >
                    Record payment
                </Button>
            </div>
        </form>
    </Card>
</template>
