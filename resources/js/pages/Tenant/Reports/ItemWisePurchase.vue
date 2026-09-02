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
    items: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ total_quantity: 0, total_value: 0 }) },
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

const columns = [
    { accessorKey: 'name', header: 'Item' },
    { accessorKey: 'unit', header: 'Unit' },
    { id: 'total_quantity', header: 'Quantity', numeric: true, cell: ({ row }) => row.original.total_quantity.toFixed(4) },
    { id: 'total_value', header: 'Value', numeric: true, cell: ({ row }) => row.original.total_value.toFixed(2) },
    { id: 'transaction_count', header: 'Transactions', numeric: true, cell: ({ row }) => row.original.transaction_count },
];
</script>

<template>
    <AppLayout title="Item-wise Purchase" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Item-wise Purchase</h2>
        </div>

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
            <DataTable :columns="columns" :data="items" :page-size="25" empty-message="No purchases in this range" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Total quantity:</span> <span class="font-semibold">{{ totals.total_quantity.toFixed(4) }}</span></div>
                <div><span class="text-text-muted">Total value:</span> <span class="font-semibold">{{ totals.total_value.toFixed(2) }}</span></div>
            </div>
        </Card>
    </AppLayout>
</template>
