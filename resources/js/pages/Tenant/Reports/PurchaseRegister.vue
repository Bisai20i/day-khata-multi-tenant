<script setup>
import { computed, h, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    purchases: { type: Array, default: () => [] },
    totals: {
        type: Object,
        default: () => ({ taxable_amount: '0.00', nontaxable_amount: '0.00', vat_amount: '0.00', total: '0.00' }),
    },
    suppliers: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    supplierId: { type: [Number, null], default: null },
    storeId: { type: [Number, null], default: null },
    paymentMode: { type: [String, null], default: null },
});

useLayoutChrome('Purchase Register');

const from = ref(props.from);
const to = ref(props.to);
const supplierId = ref(props.supplierId);
const storeId = ref(props.storeId);
const paymentMode = ref(props.paymentMode);

const supplierOptions = computed(() => [
    { value: null, label: 'All suppliers' },
    ...props.suppliers.map((supplier) => ({ value: supplier.id, label: supplier.name })),
]);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const paymentModeOptions = [
    { value: null, label: 'All payment modes' },
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'partial', label: 'Partial' },
    { value: 'credit', label: 'Credit' },
];

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

const isLoading = ref(false);

const hasActiveFilter = computed(() => supplierId.value !== null || storeId.value !== null || paymentMode.value !== null);

function resetFilters() {
    supplierId.value = null;
    storeId.value = null;
    paymentMode.value = null;
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            from: from.value,
            to: to.value,
            supplier_id: supplierId.value ?? undefined,
            store_id: storeId.value ?? undefined,
            payment_mode: paymentMode.value ?? undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

const paymentModeLabels = { cash: 'Cash', bank: 'Bank', partial: 'Partial', credit: 'Credit' };

// A cancelled bill is still listed here (audit trail), but it is not part of
// the total below, so every cell in its row is struck through and greyed
// out - a user who exports/eyeballs the table can never mistake "listed" for
// "included in the total" (audit T15-13).
function cellClasses(row) {
    return row.original.status === 'cancelled' ? 'text-text-faint line-through decoration-1' : '';
}

const columns = [
    { id: 'date', header: 'Date (BS)', numeric: false, cell: ({ row }) => h('span', { class: cellClasses(row) }, formatBsDate(row.original.date)) },
    { accessorKey: 'date', header: 'Date (AD)', cell: ({ row }) => h('span', { class: cellClasses(row) }, row.original.date) },
    { id: 'bill_number', header: 'Bill #', numeric: false, cell: ({ row }) => h('span', { class: cellClasses(row) }, row.original.bill_number ?? '-') },
    { id: 'supplier', header: 'Supplier', numeric: false, cell: ({ row }) => h('span', { class: cellClasses(row) }, row.original.supplier ?? '-') },
    { id: 'pan_number', header: 'PAN', numeric: false, cell: ({ row }) => h('span', { class: cellClasses(row) }, row.original.pan_number ?? '-') },
    { id: 'taxable_amount', header: 'Taxable amount', numeric: true, cell: ({ row }) => h('span', { class: cellClasses(row) }, formatMoney(row.original.taxable_amount)) },
    { id: 'nontaxable_amount', header: 'Non-taxable amount', numeric: true, cell: ({ row }) => h('span', { class: cellClasses(row) }, formatMoney(row.original.nontaxable_amount)) },
    { id: 'vat_amount', header: 'VAT', numeric: true, cell: ({ row }) => h('span', { class: cellClasses(row) }, formatMoney(row.original.vat_amount)) },
    { id: 'total', header: 'Total amount', numeric: true, cell: ({ row }) => h('span', { class: cellClasses(row) }, formatMoney(row.original.total)) },
    {
        id: 'payment_mode',
        header: 'Payment',
        numeric: false,
        cell: ({ row }) => h('span', { class: cellClasses(row) }, paymentModeLabels[row.original.payment_mode] ?? row.original.payment_mode),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(
                'span',
                { class: row.original.status === 'cancelled' ? 'text-danger font-semibold line-through decoration-1' : 'text-success font-semibold' },
                row.original.status === 'cancelled' ? 'Cancelled' : 'Posted',
            ),
    },
];
</script>

<template>
    <div>
        <PageHeader title="Purchase Register" description="Purchase Register: every purchase bill in the period with taxable amount, VAT and total, in date order." />

        <p class="mb-4 text-[12.5px] text-text-muted">Showing report for {{ rangeLabel }}</p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From date (BS)</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To date (BS)</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Supplier</label>
                    <Select v-model="supplierId" :options="supplierOptions" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <div class="w-48">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Payment mode</label>
                    <Select v-model="paymentMode" :options="paymentModeOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="purchases" :page-size="25" empty-message="No records for this period or filter. Try widening the date range." />

            <div class="mt-3 flex flex-wrap items-center justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div class="font-bold text-text-strong">Total</div>
                <div><span class="text-text-muted">Taxable amount:</span> <span class="font-semibold">{{ formatMoney(totals.taxable_amount) }}</span></div>
                <div><span class="text-text-muted">Non-taxable amount:</span> <span class="font-semibold">{{ formatMoney(totals.nontaxable_amount) }}</span></div>
                <div><span class="text-text-muted">VAT:</span> <span class="font-semibold">{{ formatMoney(totals.vat_amount) }}</span></div>
                <div><span class="text-text-muted">Total amount:</span> <span class="font-bold">{{ formatMoney(totals.total) }}</span></div>
            </div>

            <p class="mt-3 text-[11.5px] text-text-faint">
                Cancelled bills are shown struck through above for the audit trail; the total below covers
                posted bills only and never includes them.
            </p>
        </Card>
    </div>
</template>
