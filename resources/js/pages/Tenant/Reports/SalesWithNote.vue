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

const props = defineProps({
    sales: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ count: 0, total: 0 }) },
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

function formatItems(items) {
    if (!items || items.length === 0) {
        return '—';
    }

    return items
        .map((item) => `${item.name ?? '—'} (${Number(item.quantity).toString()}${item.unit ? ` ${item.unit}` : ''})`)
        .join(', ');
}

const columns = [
    { accessorKey: 'date', header: 'Date' },
    { id: 'id', header: 'Bill No', numeric: true, cell: ({ row }) => row.original.id },
    { id: 'customer', header: "Buyer's Name", numeric: false, cell: ({ row }) => row.original.customer ?? '—' },
    { id: 'items', header: 'Items', numeric: false, cell: ({ row }) => formatItems(row.original.items) },
    { id: 'note', header: 'Note', numeric: false, cell: ({ row }) => row.original.note ?? '—' },
    { id: 'chalani_number', header: 'Chalani No', numeric: false, cell: ({ row }) => row.original.chalani_number ?? '—' },
    { id: 'total', header: 'Total', numeric: true, cell: ({ row }) => row.original.total.toFixed(2) },
];
</script>

<template>
    <AppLayout title="Sales With Note" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Sales With Note</h2>
        </div>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Posted sales in this range that have a note recorded against them. Cancelled sales and sales with no note are excluded.
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
            <DataTable :columns="columns" :data="sales" :page-size="25" empty-message="No sales with a note in this range" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Sales with note:</span> <span class="font-semibold">{{ totals.count }}</span></div>
                <div><span class="text-text-muted">Total:</span> <span class="font-semibold">{{ totals.total.toFixed(2) }}</span></div>
            </div>
        </Card>
    </AppLayout>
</template>
