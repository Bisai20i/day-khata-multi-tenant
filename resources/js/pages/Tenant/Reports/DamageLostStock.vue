<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { formatQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    lines: { type: Array, default: () => [] },
    itemWise: { type: Array, default: () => [] },
    totalQuantities: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
    itemId: { type: [Number, null], default: null },
    reason: { type: String, default: null },
});

const page = usePage();
useLayoutChrome('Damage & Lost Stock');

const from = ref(props.from);
const to = ref(props.to);
const storeId = ref(props.storeId);
const itemId = ref(props.itemId);
const reason = ref(props.reason);

// Raw list mirrors legacy's damagestock() variant (one row per line);
// item-wise mirrors legacy's itemwisedamagestock()/
// SearchItemBetweenDateitemwiseDamage() variant (summed per item) - both
// come back from the same request, so switching is instant, no round trip.
const view = ref('list');

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const itemOptions = computed(() => [
    { value: null, label: 'All items' },
    ...props.items.map((item) => ({ value: item.id, label: item.name })),
]);

const reasonOptions = [
    { value: null, label: 'Damage & Lost' },
    { value: 'damage', label: 'Damage only' },
    { value: 'lost', label: 'Lost only' },
];

const isLoading = ref(false);

const hasActiveFilter = computed(() => itemId.value !== null || storeId.value !== null || reason.value !== null);

function resetFilters() {
    itemId.value = null;
    storeId.value = null;
    reason.value = null;
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            from: from.value,
            to: to.value,
            store_id: storeId.value ?? undefined,
            item_id: itemId.value ?? undefined,
            reason: reason.value ?? undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

const reasonLabels = { damage: 'Damage', lost: 'Lost' };

const listColumns = [
    { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { accessorKey: 'date', header: 'Date (AD)' },
    { accessorKey: 'itemName', header: 'Item' },
    { accessorKey: 'unit', header: 'Unit' },
    { id: 'storeName', header: 'Store', numeric: false, cell: ({ row }) => row.original.storeName ?? '-' },
    { id: 'reason', header: 'Reason', numeric: false, cell: ({ row }) => reasonLabels[row.original.reason] ?? row.original.reason },
    { id: 'quantity', header: 'Quantity', numeric: true, cell: ({ row }) => formatQuantity(row.original.quantity) },
    { id: 'remarks', header: 'Remarks', numeric: false, cell: ({ row }) => row.original.remarks ?? '-' },
];

const itemWiseColumns = [
    { accessorKey: 'name', header: 'Item' },
    { accessorKey: 'unit', header: 'Unit' },
    { id: 'total_quantity', header: 'Total Quantity', numeric: true, cell: ({ row }) => formatQuantity(row.original.total_quantity) },
    { accessorKey: 'transaction_count', header: 'Entries', numeric: true },
];

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

// Per base unit, never one number: writing off 3 Kilograms and 2 Pieces is
// not "5". The server sums each unit separately in exact Quantity.
const totalQuantityLabel = computed(() => {
    if (props.totalQuantities.length === 0) {
        return '-';
    }

    return props.totalQuantities
        .map((entry) => `${formatQuantity(entry.quantity)} ${entry.unit}`.trim())
        .join(', ');
});
</script>

<template>
    <div>
        <PageHeader title="Damage & Lost Stock" description="Stock written off as damaged or lost, with quantity and value for the selected dates." />

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
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Item</label>
                    <Select v-model="itemId" :options="itemOptions" />
                </div>
                <div class="w-48">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <div class="w-48">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Reason</label>
                    <Select v-model="reason" :options="reasonOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
        </Card>

        <div class="mb-3 flex items-center gap-2">
            <Button :variant="view === 'list' ? 'primary' : 'secondary'" tone="purple" @click="view = 'list'">List</Button>
            <Button :variant="view === 'item-wise' ? 'primary' : 'secondary'" tone="purple" @click="view = 'item-wise'">Item-wise</Button>
        </div>

        <Card variant="panel" v-if="view === 'list'">
            <DataTable :columns="listColumns" :data="lines" :page-size="25" empty-message="No records for this period or filter. Try widening the date range." />
            <div class="mt-3 border-t-[1.5px] border-border pt-3 text-sm">
                <span class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Total quantity written off:</span>
                <span class="ml-2 font-bold text-text-strong">{{ totalQuantityLabel }}</span>
            </div>
        </Card>

        <Card variant="panel" v-else>
            <DataTable :columns="itemWiseColumns" :data="itemWise" :page-size="25" empty-message="No damage or lost stock entries in this range" />
        </Card>
    </div>
</template>
