<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    asOf: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    grandTotalValuation: { type: Number, default: 0 },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const asOfInput = ref(props.asOf);

function applyFilter() {
    router.get(
        window.location.pathname,
        { as_of: asOfInput.value },
        { preserveState: true, preserveScroll: true },
    );
}

const columns = [
    { accessorKey: 'name', header: 'Item' },
    { accessorKey: 'unit', header: 'Unit' },
    { accessorKey: 'quantity', header: 'Quantity', cell: ({ row }) => row.original.quantity.toFixed(2) },
    { accessorKey: 'avgCost', header: 'Avg Cost', cell: ({ row }) => row.original.avgCost.toFixed(2) },
    { accessorKey: 'valuation', header: 'Valuation', cell: ({ row }) => row.original.valuation.toFixed(2) },
];
</script>

<template>
    <AppLayout title="Stock Valuation" :nav-items="navItems">
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="w-40">
                <label class="mb-1 block text-[11px] font-semibold text-text-muted">As Of</label>
                <Input v-model="asOfInput" type="date" />
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
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No stock on hand as of this date" />
        </Card>

        <div class="mt-3 flex justify-end">
            <div class="border-[1.5px] border-border bg-bg-subtle px-4 py-2 text-[13px] font-bold text-text-strong">
                Total valuation: {{ grandTotalValuation.toFixed(2) }}
            </div>
        </div>
    </AppLayout>
</template>
