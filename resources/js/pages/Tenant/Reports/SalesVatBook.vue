<script setup>
import { computed, h, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
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
        default: () => ({
            taxable_amount: '0.00',
            nontaxable_amount: '0.00',
            vat_amount: '0.00',
            capital_amount: '0.00',
            total: '0.00',
            count: 0,
        }),
    },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
});

useLayoutChrome('Sales VAT Book');

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

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: from.value, to: to.value, store_id: storeId.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

// Built from the currently-applied filters (not just whatever was last
// submitted via Apply) so exporting always matches what's on screen.
const exportUrl = computed(() => {
    const params = new URLSearchParams({ from: from.value, to: to.value });
    if (storeId.value) {
        params.set('store_id', storeId.value);
    }

    return `/reports/sales-vat-book/export?${params.toString()}`;
});

const typeLabels = { full: 'Full', abbreviated: 'Abbreviated', pan: 'PAN', capital: 'Capital' };

const columns = [
    { accessorKey: 'sn', header: 'SN', numeric: true },
    { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { accessorKey: 'date', header: 'Date (AD)' },
    { id: 'invoice_number', header: 'Invoice #', numeric: false, cell: ({ row }) => row.original.invoice_number ?? '—' },
    { id: 'buyer_name', header: 'Buyer', numeric: false, cell: ({ row }) => row.original.buyer_name ?? '—' },
    { id: 'buyer_pan', header: 'Buyer PAN', numeric: false, cell: ({ row }) => row.original.buyer_pan ?? '—' },
    {
        id: 'invoice_type',
        header: 'Type',
        numeric: false,
        cell: ({ row }) => typeLabels[row.original.invoice_type] ?? row.original.invoice_type,
    },
    {
        id: 'entry',
        header: 'Entry',
        numeric: false,
        cell: ({ row }) =>
            row.original.entry === 'cancelled'
                ? h('span', { class: 'text-danger font-semibold' }, 'Cancelled')
                : 'Issued',
    },
    { id: 'taxable_amount', header: 'Taxable', numeric: true, cell: ({ row }) => formatMoney(row.original.taxable_amount) },
    { id: 'nontaxable_amount', header: 'Exempt', numeric: true, cell: ({ row }) => formatMoney(row.original.nontaxable_amount) },
    { id: 'vat_amount', header: 'VAT', numeric: true, cell: ({ row }) => formatMoney(row.original.vat_amount) },
    { id: 'capital_amount', header: 'Capital', numeric: true, cell: ({ row }) => formatMoney(row.original.capital_amount) },
    { id: 'total', header: 'Total', numeric: true, cell: ({ row }) => formatMoney(row.original.total) },
];
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Sales VAT Book</h2>
            <Button as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export to Excel
            </Button>
        </div>

        <p class="mb-1 text-[12.5px] text-text-muted">{{ rangeLabel }}</p>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Includes capital sales. A cancelled invoice stays in the month it was issued and comes back out as
            a negative row in the month it was cancelled, so a filed month never changes.
        </p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No sales in this range" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Taxable:</span> <span class="font-semibold">{{ formatMoney(totals.taxable_amount) }}</span></div>
                <div><span class="text-text-muted">Exempt:</span> <span class="font-semibold">{{ formatMoney(totals.nontaxable_amount) }}</span></div>
                <div><span class="text-text-muted">VAT:</span> <span class="font-semibold">{{ formatMoney(totals.vat_amount) }}</span></div>
                <div><span class="text-text-muted">Capital:</span> <span class="font-semibold">{{ formatMoney(totals.capital_amount) }}</span></div>
                <div><span class="text-text-muted">Total:</span> <span class="font-semibold">{{ formatMoney(totals.total) }}</span></div>
            </div>
        </Card>
    </div>
</template>
