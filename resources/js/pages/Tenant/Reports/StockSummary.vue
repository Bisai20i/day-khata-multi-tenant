<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { navGroups } from '@/lib/nav-items.js';
import { formatMoney, formatQuantity, formatRate } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    grandTotalValuation: { type: String, default: '0.00' },
    stores: { type: Array, default: () => [] },
    storeId: { type: [Number, null], default: null },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const fromInput = ref(props.from);
const toInput = ref(props.to);
const storeId = ref(props.storeId);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: fromInput.value, to: toInput.value, store_id: storeId.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

const columns = [
    { accessorKey: 'name', header: 'Item' },
    { accessorKey: 'unit', header: 'Unit' },
    { accessorKey: 'opening', header: 'Opening', numeric: true, cell: ({ row }) => formatQuantity(row.original.opening) },
    { accessorKey: 'qtyIn', header: 'Qty In', numeric: true, cell: ({ row }) => formatQuantity(row.original.qtyIn) },
    { accessorKey: 'qtyOut', header: 'Qty Out', numeric: true, cell: ({ row }) => formatQuantity(row.original.qtyOut) },
    { accessorKey: 'closing', header: 'Closing', numeric: true, cell: ({ row }) => formatQuantity(row.original.closing) },
    { accessorKey: 'avgCost', header: 'Avg Cost', numeric: true, cell: ({ row }) => formatRate(row.original.avgCost) },
    { accessorKey: 'valuation', header: 'Valuation', numeric: true, cell: ({ row }) => formatMoney(row.original.valuation) },
];
</script>

<template>
    <AppLayout title="Stock Summary" :nav-items="navItems">
        <p class="mb-4 text-[12.5px] text-text-muted">
            {{ rangeLabel }}. with every store combined, transfers between your own stores are left out of
            Qty In and Qty Out; relocating goods is not stock entering or leaving the business.
        </p>

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="w-40">
                <label class="mb-1 block text-[11px] font-semibold text-text-muted">From</label>
                <NepaliDateInput v-model="fromInput" />
            </div>
            <div class="w-40">
                <label class="mb-1 block text-[11px] font-semibold text-text-muted">To</label>
                <NepaliDateInput v-model="toInput" />
            </div>
            <div class="w-56">
                <label class="mb-1 block text-[11px] font-semibold text-text-muted">Store</label>
                <Select v-model="storeId" :options="storeOptions" />
            </div>
            <button
                type="button"
                class="border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] font-semibold text-text-base hover:bg-white"
                @click="applyFilter"
            >
                Apply
            </button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No stock activity in this range" />
        </Card>

        <div class="mt-3 flex justify-end">
            <div class="border-[1.5px] border-border bg-bg-subtle px-4 py-2 text-[13px] font-bold text-text-strong">
                Total valuation: {{ formatMoney(grandTotalValuation) }}
            </div>
        </div>
    </AppLayout>
</template>
