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
import { formatMoney, formatQuantity, formatRate } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    items: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ total_value: '0.00', quantities: [] }) },
    lines: { type: Array, default: () => [] },
    itemsList: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
    itemId: { type: [Number, null], default: null },
});

useLayoutChrome('Item-wise Purchase');

const from = ref(props.from);
const to = ref(props.to);
const storeId = ref(props.storeId);
const itemId = ref(props.itemId);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const itemOptions = computed(() => [
    { value: null, label: 'All items (no drill-down)' },
    ...props.itemsList.map((item) => ({ value: item.id, label: item.name })),
]);

const isLoading = ref(false);

const hasActiveFilter = computed(() => storeId.value !== null || itemId.value !== null);

function resetFilters() {
    storeId.value = null;
    itemId.value = null;
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: from.value, to: to.value, store_id: storeId.value ?? undefined, item_id: itemId.value ?? undefined },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

const selectedItemName = computed(
    () => props.itemsList.find((item) => item.id === props.itemId)?.name ?? '',
);

const lineColumns = [
    { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { accessorKey: 'date', header: 'Date (AD)' },
    { accessorKey: 'document_number', header: 'Bill No.' },
    { id: 'party_name', header: 'Supplier', numeric: false, cell: ({ row }) => row.original.party_name ?? '-' },
    { id: 'rate', header: 'Rate', numeric: true, cell: ({ row }) => formatRate(row.original.rate) },
    { id: 'quantity', header: 'Quantity', numeric: true, cell: ({ row }) => formatQuantity(row.original.quantity) },
    { id: 'line_total', header: 'Line Value', numeric: true, cell: ({ row }) => formatMoney(row.original.line_total) },
    { id: 'vatable', header: 'Vatable', numeric: false, cell: ({ row }) => (row.original.vatable ? 'Yes' : 'No') },
];

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

// Never one number across items: adding Kilograms to Pieces produces a
// figure nobody can use, so the grand total is listed per base unit.
function quantityLabel(quantities) {
    if (!quantities || quantities.length === 0) {
        return '-';
    }

    return quantities.map((entry) => `${formatQuantity(entry.quantity)} ${entry.unit}`.trim()).join(', ');
}

const columns = [
    { accessorKey: 'name', header: 'Item' },
    { accessorKey: 'unit', header: 'Unit' },
    { id: 'total_quantity', header: 'Qty Purchased (base)', numeric: true, cell: ({ row }) => formatQuantity(row.original.total_quantity) },
    { id: 'total_value', header: 'Value', numeric: true, cell: ({ row }) => formatMoney(row.original.total_value) },
    { id: 'transaction_count', header: 'Transactions', numeric: true, cell: ({ row }) => row.original.transaction_count },
];
</script>

<template>
    <div>
        <PageHeader title="Item-wise Purchase" description="How much of each item you bought in the selected dates, with the amount spent." />

        <p class="mb-1 text-[12.5px] text-text-muted">Showing report for {{ rangeLabel }}</p>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Quantities are in each item's base unit, so a line entered in Boxes of 12 counts as 12.
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
                <div class="w-64">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Item (drill-down)</label>
                    <Select v-model="itemId" :options="itemOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="items" :page-size="25" empty-message="No records for this period or filter. Try widening the date range." />

            <div class="mt-3 flex flex-wrap items-center justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div class="font-bold text-text-strong">Total</div>
                <div><span class="text-text-muted">Total quantity:</span> <span class="font-semibold">{{ quantityLabel(totals.quantities) }}</span></div>
                <div><span class="text-text-muted">Total value:</span> <span class="font-semibold">{{ formatMoney(totals.total_value) }}</span></div>
            </div>
        </Card>

        <Card v-if="itemId" variant="panel" class="mt-4">
            <div class="mb-3">
                <h3 class="text-base font-bold text-text-strong">Transaction detail: {{ selectedItemName }}</h3>
                <p class="mt-0.5 text-[12.5px] text-text-muted">
                    Every purchase line of this item in the selected dates, at what rate and from whom.
                </p>
            </div>
            <DataTable
                :columns="lineColumns"
                :data="lines"
                :page-size="25"
                empty-message="No individual purchase lines for this item in this period or filter."
            />
        </Card>
    </div>
</template>
