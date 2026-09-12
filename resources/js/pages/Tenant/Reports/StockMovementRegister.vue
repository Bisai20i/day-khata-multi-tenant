<script setup>
import { computed, h, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { navGroups } from '@/lib/nav-items.js';
import { formatQuantity, formatRate } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

const props = defineProps({
    movements: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    itemId: { type: [Number, null], default: null },
    storeId: { type: [Number, null], default: null },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const from = ref(props.from);
const to = ref(props.to);
const itemId = ref(props.itemId);
const storeId = ref(props.storeId);

const itemOptions = computed(() => [
    { value: null, label: 'All items' },
    ...props.items.map((item) => ({ value: item.id, label: item.name })),
]);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: from.value, to: to.value, item_id: itemId.value ?? undefined, store_id: storeId.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

// The quantity arrives as an exact 4-decimal string, so its sign is read
// off the string itself - parsing it back into a float just to pick a
// colour is the habit this rewrite removed.
function quantitySign(value) {
    if (value.startsWith('-')) {
        return 'text-danger';
    }

    return /[1-9]/.test(value) ? 'text-success' : '';
}

const columns = [
    { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { accessorKey: 'date', header: 'Date (AD)' },
    { accessorKey: 'itemName', header: 'Item' },
    { id: 'storeName', header: 'Store', numeric: false, cell: ({ row }) => row.original.storeName ?? '—' },
    { accessorKey: 'unit', header: 'Unit' },
    { accessorKey: 'movementType', header: 'Movement Type' },
    {
        id: 'quantity',
        header: 'Quantity',
        numeric: true,
        cell: ({ row }) =>
            h(
                'span',
                { class: `font-semibold ${quantitySign(row.original.quantity)}` },
                (row.original.quantity.startsWith('-') ? '' : '+') + formatQuantity(row.original.quantity),
            ),
    },
    {
        id: 'unitCostRate',
        header: 'Unit Cost',
        numeric: true,
        cell: ({ row }) => (row.original.unitCostRate === null ? '—' : formatRate(row.original.unitCostRate)),
    },
    { accessorKey: 'reference', header: 'Reference' },
];
</script>

<template>
    <AppLayout title="Stock Movement Register" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Stock Movement Register</h2>
        </div>

        <p class="mb-4 text-[12.5px] text-text-muted">{{ rangeLabel }}</p>

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
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Item</label>
                    <Select v-model="itemId" :options="itemOptions" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="movements" :page-size="25" empty-message="No stock movements in this range" />
        </Card>
    </AppLayout>
</template>
