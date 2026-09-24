<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { formatMoney, formatQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sales: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ count: 0, total: '0.00' }) },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
});

useLayoutChrome('Sales With Note');

const from = ref(props.from);
const to = ref(props.to);
const storeId = ref(props.storeId);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

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

function formatItems(items) {
    if (!items || items.length === 0) {
        return '-';
    }

    return items
        .map((item) => `${item.name ?? '-'} (${formatQuantity(item.quantity)}${item.unit ? ` ${item.unit}` : ''})`)
        .join(', ');
}

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

const columns = [
    { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { accessorKey: 'date', header: 'Date (AD)' },
    // The stored invoice number (C7), not the internal row id.
    { id: 'invoice_number', header: 'Bill No', numeric: false, cell: ({ row }) => row.original.invoice_number ?? '-' },
    { id: 'customer', header: "Buyer's Name", numeric: false, cell: ({ row }) => row.original.customer ?? '-' },
    { id: 'items', header: 'Items', numeric: false, cell: ({ row }) => formatItems(row.original.items) },
    { id: 'note', header: 'Note', numeric: false, cell: ({ row }) => row.original.note ?? '-' },
    { id: 'chalani_number', header: 'Chalani No', numeric: false, cell: ({ row }) => row.original.chalani_number ?? '-' },
    { id: 'total', header: 'Total amount', numeric: true, cell: ({ row }) => formatMoney(row.original.total) },
];
</script>

<template>
    <div>
        <PageHeader title="Sales With Note" description="Sales in the selected dates that have a note written on them." />

        <p class="mb-4 text-[12.5px] text-text-muted">
            Posted sales in this range that have a note recorded against them. Cancelled sales and sales with no note are excluded.
        </p>

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
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="sales" :page-size="25" empty-message="No records for this period or filter. Try widening the date range." />

            <div class="mt-3 flex flex-wrap items-center justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div class="font-bold text-text-strong">Total</div>
                <div><span class="text-text-muted">Sales with note:</span> <span class="font-semibold">{{ totals.count }}</span></div>
                <div><span class="text-text-muted">Total amount:</span> <span class="font-bold">{{ formatMoney(totals.total) }}</span></div>
            </div>
        </Card>
    </div>
</template>
