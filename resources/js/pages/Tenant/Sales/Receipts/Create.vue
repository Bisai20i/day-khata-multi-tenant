<script setup>
import { computed, reactive, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { addMoney, compareMoney, formatMoney, isZeroMoney, parseMoney } from '@/lib/money';
import { todayInKathmandu, formatBsDate } from '@/lib/format';

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
        label: account.code ? `${account.code} - ${account.name}` : account.name,
    })),
);

const paymentModeOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
];

const form = useForm({
    customer_id: null,
    // Kathmandu's today, not the browser's UTC today: between midnight and
    // 05:45 Nepal time a UTC default dates the receipt a day early.
    date: todayInKathmandu(),
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

/**
 * The allocations as the server will read them: a valid, positive amount per
 * invoice. Everything is an exact 2dp string end to end - a 0.01 allocation
 * is as real as any other, and one paisa over an invoice's outstanding
 * balance is refused rather than absorbed by a tolerance (audit P0-4).
 */
const allocations = computed(() =>
    customerSales.value
        .map((sale) => ({ sale, parsed: parseMoney(allocationAmounts[sale.id] ?? '') }))
        .filter(({ parsed }) => parsed.ok && !isZeroMoney(parsed.value))
        .map(({ sale, parsed }) => ({ sale_id: sale.id, amount: parsed.value })),
);

const allocationErrors = computed(() =>
    customerSales.value
        .filter((sale) => {
            const raw = allocationAmounts[sale.id];

            if (raw === undefined || raw === null || String(raw).trim() === '') {
                return false;
            }

            const parsed = parseMoney(raw);

            return !parsed.ok || compareMoney(parsed.value, sale.outstanding) > 0 || compareMoney(parsed.value, '0.00') < 0;
        })
        .map((sale) => sale.id),
);

const totalAllocated = computed(() => {
    let total = '0.00';

    for (const allocation of allocations.value) {
        total = addMoney(total, allocation.amount);
    }

    return total;
});

const receiptAmount = computed(() => {
    const parsed = parseMoney(form.amount === '' ? '0' : form.amount);

    return parsed.ok ? parsed.value : null;
});

const overAllocated = computed(
    () => receiptAmount.value !== null && compareMoney(totalAllocated.value, receiptAmount.value) > 0,
);

const canSubmit = computed(
    () =>
        !form.processing
        && !!form.customer_id
        && receiptAmount.value !== null
        && compareMoney(receiptAmount.value, '0.00') > 0
        && allocationErrors.value.length === 0
        && !overAllocated.value,
);

function submit() {
    form.transform((data) => ({
        ...data,
        amount: receiptAmount.value,
        allocations: allocations.value,
    })).post('/receipts', {
        preserveScroll: true,
        onSuccess: () => emit('posted'),
    });
}
</script>

<template>
    <Card variant="panel">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-text-strong">New receipt</h3>
                <p class="mt-0.5 text-sm text-text-muted">Money received from a customer. It reduces what they owe you.</p>
            </div>
            <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Customer <span class="text-danger">*</span></label>
                    <Combobox
                        :model-value="form.customer_id"
                        :options="customerOptions"
                        placeholder="Select customer"
                        @update:model-value="(v) => (form.customer_id = v)"
                    />
                    <p v-if="form.errors.customer_id" class="mt-1 text-sm text-danger">{{ form.errors.customer_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Date <span class="text-danger">*</span></label>
                    <NepaliDateInput v-model="form.date" required />
                    <p v-if="form.errors.date" class="mt-1 text-sm text-danger">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Amount <span class="text-danger">*</span></label>
                    <Input v-model="form.amount" type="text" inputmode="decimal" placeholder="0.00" required />
                    <p v-if="form.amount !== '' && receiptAmount === null" class="mt-1 text-sm text-danger">
                        Enter an amount with at most 2 decimals.
                    </p>
                    <p v-if="form.errors.amount" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.amount }}</p>
                    <p v-else class="mt-1 text-xs text-text-muted">Total amount the customer paid.</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Payment mode <span class="text-danger">*</span></label>
                    <Select
                        :model-value="form.payment_mode"
                        :options="paymentModeOptions"
                        @update:model-value="(v) => (form.payment_mode = v)"
                    />
                </div>
                <div v-if="showBankAccount">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Bank Account <span class="text-danger">*</span></label>
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
                    <p class="mt-1 text-xs text-text-muted">Cheque or transaction number, if any.</p>
                </div>
                <div class="col-span-3">
                    <label class="mb-1 block text-sm font-semibold text-text-base">Narration</label>
                    <Input v-model="form.narration" type="text" placeholder="Optional" />
                </div>
            </div>

            <div v-if="form.customer_id">
                <p class="mb-2 text-sm font-semibold text-text-base">Match to unpaid invoices (optional)</p>
                <p class="mb-2 text-xs text-text-muted">Enter how much of this receipt pays off each invoice. Anything left over stays as an advance on the customer's account.</p>
                <p v-if="!customerSales.length" class="text-sm text-text-muted">This customer has no outstanding invoices.</p>

                <div v-else class="flex flex-col gap-2">
                    <div class="grid grid-cols-[110px_1fr_110px_110px_130px] gap-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <span>Date</span>
                        <span>Invoice</span>
                        <span class="text-right">Total</span>
                        <span class="text-right">Outstanding</span>
                        <span>Allocate</span>
                    </div>
                    <div v-for="sale in customerSales" :key="sale.id" class="grid grid-cols-[110px_1fr_110px_110px_130px] items-center gap-2">
                        <span class="text-sm text-text-base">{{ formatBsDate(sale.date) }}</span>
                        <span class="text-sm text-text-base">{{ sale.invoice_number ?? `Sale #${sale.id}` }}</span>
                        <span class="text-right text-sm text-text-base">{{ formatMoney(sale.total) }}</span>
                        <span class="text-right text-sm text-text-base">{{ formatMoney(sale.outstanding) }}</span>
                        <Input
                            :model-value="allocationAmounts[sale.id] ?? ''"
                            type="text"
                            inputmode="decimal"
                            placeholder="0.00"
                            @update:model-value="(v) => (allocationAmounts[sale.id] = v)"
                        />
                    </div>
                </div>

                <p v-if="allocationErrors.length" class="mt-2 text-sm text-danger">
                    An allocation is not a valid amount, or is more than that invoice still owes.
                </p>
                <p v-if="overAllocated" class="mt-2 text-sm text-danger">Allocations add up to more than the receipt amount.</p>
                <p v-if="form.errors.allocations" class="mt-2 text-sm text-danger">{{ form.errors.allocations }}</p>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t-[1.5px] border-border pt-3 text-sm">
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Allocated total</p>
                    <p class="font-bold text-text-strong">{{ formatMoney(totalAllocated) }}</p>
                </div>
                <div>
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Receipt amount</p>
                    <p class="font-bold text-text-strong">{{ receiptAmount === null ? '-' : formatMoney(receiptAmount) }}</p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" tone="purple" type="button" @click="emit('cancel')">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" :disabled="!canSubmit || form.processing" :loading="form.processing">Save receipt</Button>
            </div>
        </form>
    </Card>
</template>
