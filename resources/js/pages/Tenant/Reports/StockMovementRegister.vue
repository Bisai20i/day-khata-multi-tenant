<script setup>
import { computed, h, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    movements: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    itemId: { type: [Number, null], default: null },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const from = ref(props.from);
const to = ref(props.to);
const itemId = ref(props.itemId);

const itemOptions = computed(() => [
    { value: null, label: 'All items' },
    ...props.items.map((item) => ({ value: item.id, label: item.name })),
]);

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: from.value, to: to.value, item_id: itemId.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

function quantitySign(value) {
    return value > 0 ? 'text-success' : value < 0 ? 'text-danger' : '';
}

const columns = [
    { accessorKey: 'date', header: 'Date' },
    { accessorKey: 'itemName', header: 'Item' },
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
                (row.original.quantity > 0 ? '+' : '') + row.original.quantity.toFixed(4),
            ),
    },
    {
        id: 'unitCostRate',
        header: 'Unit Cost',
        numeric: true,
        cell: ({ row }) => (row.original.unitCostRate !== null ? row.original.unitCostRate.toFixed(4) : '—'),
    },
    { accessorKey: 'reference', header: 'Reference' },
];
</script>

<template>
    <AppLayout title="Stock Movement Register" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Stock Movement Register</h2>
        </div>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                    <Input v-model="from" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                    <Input v-model="to" type="date" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Item</label>
                    <Select v-model="itemId" :options="itemOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="movements" :page-size="25" empty-message="No stock movements in this range" />
        </Card>
    </AppLayout>
</template>
