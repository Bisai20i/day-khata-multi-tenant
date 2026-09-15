<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { formatMoney, formatQuantity, formatRate } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    asOf: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    grandTotalValuation: { type: String, default: '0.00' },
    stores: { type: Array, default: () => [] },
    storeId: { type: [Number, null], default: null },
});

useLayoutChrome('Stock Valuation');

const asOfInput = ref(props.asOf);
const storeId = ref(props.storeId);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

function applyFilter() {
    router.get(
        window.location.pathname,
        { as_of: asOfInput.value, store_id: storeId.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

const asOfLabel = computed(() => `As of BS ${formatBsDate(props.asOf)} (AD ${props.asOf})`);

const columns = [
    { accessorKey: 'name', header: 'Item' },
    { accessorKey: 'unit', header: 'Unit' },
    { accessorKey: 'quantity', header: 'Quantity', numeric: true, cell: ({ row }) => formatQuantity(row.original.quantity) },
    { accessorKey: 'avgCost', header: 'Avg Cost', numeric: true, cell: ({ row }) => formatRate(row.original.avgCost) },
    { accessorKey: 'valuation', header: 'Valuation', numeric: true, cell: ({ row }) => formatMoney(row.original.valuation) },
];
</script>

<template>
    <div>
        <p class="mb-4 text-[12.5px] text-text-muted">
            {{ asOfLabel }}. weighted average cost per base unit, the same basis the Balance Sheet and the
            year-end closing entry use.
        </p>

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="w-40">
                <label class="mb-1 block text-[11px] font-semibold text-text-muted">As Of</label>
                <NepaliDateInput v-model="asOfInput" />
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
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No stock on hand as of this date" />
        </Card>

        <div class="mt-3 flex justify-end">
            <div class="border-[1.5px] border-border bg-bg-subtle px-4 py-2 text-[13px] font-bold text-text-strong">
                Total valuation: {{ formatMoney(grandTotalValuation) }}
            </div>
        </div>
    </div>
</template>
