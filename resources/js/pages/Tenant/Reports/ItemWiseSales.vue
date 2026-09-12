<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { navGroups } from '@/lib/nav-items.js';
import { formatMoney, formatQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

const props = defineProps({
    items: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ total_value: '0.00', quantities: [] }) },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const from = ref(props.from);
const to = ref(props.to);
const storeId = ref(props.storeId);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: from.value, to: to.value, store_id: storeId.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

// Never one number across items: adding Kilograms to Pieces produces a
// figure nobody can use, so the grand total is listed per base unit.
function quantityLabel(quantities) {
    if (!quantities || quantities.length === 0) {
        return '—';
    }

    return quantities.map((entry) => `${formatQuantity(entry.quantity)} ${entry.unit}`.trim()).join(', ');
}

const columns = [
    { accessorKey: 'name', header: 'Item' },
    { accessorKey: 'unit', header: 'Unit' },
    { id: 'total_quantity', header: 'Qty Sold (base)', numeric: true, cell: ({ row }) => formatQuantity(row.original.total_quantity) },
    { id: 'total_value', header: 'Sales Value', numeric: true, cell: ({ row }) => formatMoney(row.original.total_value) },
    { id: 'transaction_count', header: 'Transactions', numeric: true, cell: ({ row }) => row.original.transaction_count },
];
</script>

<template>
    <AppLayout title="Item-wise Sales" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Item-wise Sales</h2>
        </div>

        <p class="mb-4 text-[12.5px] text-text-muted">
            {{ rangeLabel }}. quantities are in each item's base unit, so a line entered in Boxes of 12
            counts as 12.
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
            <DataTable :columns="columns" :data="items" :page-size="25" empty-message="No sales in this range" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Total quantity:</span> <span class="font-semibold">{{ quantityLabel(totals.quantities) }}</span></div>
                <div><span class="text-text-muted">Total value:</span> <span class="font-semibold">{{ formatMoney(totals.total_value) }}</span></div>
            </div>
        </Card>
    </AppLayout>
</template>
