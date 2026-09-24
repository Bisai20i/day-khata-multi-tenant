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
import { FileSpreadsheet } from '@lucide/vue';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    rows: { type: Array, default: () => [] },
    totals: {
        type: Object,
        default: () => ({ taxable_amount: '0.00', nontaxable_amount: '0.00', vat_amount: '0.00', total: '0.00', count: 0 }),
    },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
});

useLayoutChrome('Purchase Return Register');

const from = ref(props.from);
const to = ref(props.to);
const storeId = ref(props.storeId);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

const isLoading = ref(false);

const hasActiveFilter = computed(() => storeId.value !== null);

function resetFilters() {
    storeId.value = null;
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: from.value, to: to.value, store_id: storeId.value ?? undefined },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

const exportUrl = computed(() => {
    const params = new URLSearchParams({ from: from.value, to: to.value });
    if (storeId.value) {
        params.set('store_id', storeId.value);
    }

    return `/reports/purchase-return-register/export?${params.toString()}`;
});

const columns = [
    { accessorKey: 'sn', header: 'SN', numeric: true },
    { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { accessorKey: 'date', header: 'Date (AD)' },
    { id: 'debit_note_number', header: 'Debit Note #', numeric: false, cell: ({ row }) => row.original.debit_note_number ?? '-' },
    { id: 'bill_number', header: 'Against Bill #', numeric: false, cell: ({ row }) => row.original.bill_number ?? '-' },
    { id: 'supplier', header: 'Supplier', numeric: false, cell: ({ row }) => row.original.supplier ?? '-' },
    { id: 'supplier_pan', header: 'Supplier PAN', numeric: false, cell: ({ row }) => row.original.supplier_pan ?? '-' },
    {
        id: 'entry',
        header: 'Entry',
        numeric: false,
        cell: ({ row }) =>
            row.original.entry === 'cancelled'
                ? h('span', { class: 'text-danger font-semibold' }, 'Cancelled')
                : 'Issued',
    },
    { id: 'taxable_amount', header: 'Taxable amount', numeric: true, cell: ({ row }) => formatMoney(row.original.taxable_amount) },
    { id: 'nontaxable_amount', header: 'Exempt amount', numeric: true, cell: ({ row }) => formatMoney(row.original.nontaxable_amount) },
    { id: 'vat_amount', header: 'VAT', numeric: true, cell: ({ row }) => formatMoney(row.original.vat_amount) },
    { id: 'total', header: 'Total amount', numeric: true, cell: ({ row }) => formatMoney(row.original.total) },
];
</script>

<template>
    <div>
        <PageHeader title="Purchase Return Register" description="Every posted purchase return (debit note) in the selected dates, with its amount and VAT.">
            <Button as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export to Excel
            </Button>
        </PageHeader>

        <p class="mb-1 text-[12.5px] text-text-muted">Showing report for {{ rangeLabel }}</p>

        <p class="mb-4 text-[12.5px] text-text-muted">
            The debit note book. Posted notes only: a pending return request reserves stock but has no money
            or VAT effect, and is never a debit note.
        </p>

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
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No records for this period or filter. Try widening the date range." />

            <div class="mt-3 flex flex-wrap items-center justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div class="font-bold text-text-strong">Total</div>
                <div><span class="text-text-muted">Taxable amount:</span> <span class="font-semibold">{{ formatMoney(totals.taxable_amount) }}</span></div>
                <div><span class="text-text-muted">Exempt amount:</span> <span class="font-semibold">{{ formatMoney(totals.nontaxable_amount) }}</span></div>
                <div><span class="text-text-muted">VAT:</span> <span class="font-semibold">{{ formatMoney(totals.vat_amount) }}</span></div>
                <div><span class="text-text-muted">Total amount:</span> <span class="font-bold">{{ formatMoney(totals.total) }}</span></div>
            </div>
        </Card>
    </div>
</template>
