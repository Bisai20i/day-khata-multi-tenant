<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import { formatMoney, formatQuantity, formatRate } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { itemLedgerColumn } from '@/lib/itemLedger';
import { stockBranchQuery } from '@/lib/stockBranchReport.js';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { useStockValuationBranchColumns } from '@/composables/useStockValuationBranchColumns';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import { useBranchLabel } from '@/composables/useBranchLabel';

defineOptions({ layout: AppLayout });

const props = defineProps({
    asOf: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    grandTotalValuation: { type: String, default: '0.00' },
    stores: { type: Array, default: () => [] },
    storeId: { type: [Number, null], default: null },
    stockStatus: { type: String, default: 'all' },
    // Branch filter and by-branch columns; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

const { label } = useBranchLabel();

useLayoutChrome('Stock Valuation');

const asOfInput = ref(props.asOf);
const storeId = ref(props.storeId);
const stockStatus = ref(props.stockStatus);

// The server drops a store outside the chosen branches; follow what it applied.
watch(
    () => [props.storeId, props.stores],
    () => {
        storeId.value = props.storeId;
    },
);

const branchFilter = useBranchReportFilter(() => props.branchReport, { byBranch: true });
const { hasBranchColumns, branchColumns, columnsKey, branchTotals, minWidth } = useStockValuationBranchColumns(
    () => props.branchReport,
);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

// Mirrors legacy's stock_status filter: hunting down negative-stock
// data-entry errors is the whole reason this control exists. 'all' applies
// no sign filter, the same rows the report has always shown.
const stockStatusOptions = [
    { value: 'all', label: 'All' },
    { value: 'positive', label: 'Positive' },
    { value: 'negative', label: 'Negative' },
];

const isLoading = ref(false);

const hasActiveFilter = computed(() => storeId.value !== null || stockStatus.value !== 'all');

function resetFilters() {
    storeId.value = null;
    stockStatus.value = 'all';
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            as_of: asOfInput.value,
            store_id: storeId.value ?? undefined,
            stock_status: stockStatus.value,
            ...stockBranchQuery(branchFilter, storeId.value),
        },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

const asOfLabel = computed(() => `As of BS ${formatBsDate(props.asOf)} (AD ${props.asOf})`);

const baseColumns = [
    { accessorKey: 'name', header: 'Item' },
    { id: 'hsCode', header: 'HS Code', numeric: false, cell: ({ row }) => row.original.hsCode ?? '-' },
    { accessorKey: 'unit', header: 'Unit' },
    { accessorKey: 'quantity', header: 'Quantity', numeric: true, cell: ({ row }) => formatQuantity(row.original.quantity) },
    { accessorKey: 'avgCost', header: 'Average cost per unit', numeric: true, cell: ({ row }) => formatRate(row.original.avgCost) },
    { accessorKey: 'valuation', header: 'Valuation', numeric: true, cell: ({ row }) => formatMoney(row.original.valuation) },
];

// The ledger link stays last; the branch columns (quantity over value) sit before it.
const columns = computed(() => [...baseColumns, ...branchColumns.value, itemLedgerColumn((row) => row.itemId)]);
</script>

<template>
    <div>
        <PageHeader title="Stock Valuation" description="What your stock on hand is worth on a chosen date, item by item." />

        <p class="mb-1 text-[12.5px] text-text-muted">Showing report: {{ asOfLabel }}</p>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Weighted average cost per base unit, the same basis the Balance Sheet and the year-end closing entry use.
        </p>

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="w-40">
                <Label class="mb-1 text-[11px]">As of date (BS)</Label>
                <NepaliDateInput v-model="asOfInput" />
            </div>
            <div class="w-56">
                <Label class="mb-1 text-[11px]">Store</Label>
                <Select v-model="storeId" :options="storeOptions" />
            </div>
            <div class="w-40">
                <Label class="mb-1 text-[11px]">Stock status</Label>
                <Select v-model="stockStatus" :options="stockStatusOptions" />
            </div>
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                v-model:by-branch="branchFilter.byBranch.value"
                :report="branchReport"
                :allow-by-branch="storeId === null"
                id-prefix="stock-valuation-branch"
                @change="applyFilter"
            />
            <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
            <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
        </div>

        <div v-if="branchReport" class="-mt-2 mb-4">
            <BranchReportNotes :report="branchReport" />
            <p v-if="hasBranchColumns" class="mt-1 text-[12px] text-text-muted">
                Each {{ label('lower') }} column shows the quantity above and the value below.
            </p>
        </div>

        <Card variant="panel" :class="hasBranchColumns ? 'overflow-x-auto' : undefined">
            <DataTable
                :key="columnsKey"
                :columns="columns"
                :data="rows"
                :page-size="25"
                empty-message="No records for this date or filter. Try a different date or store."
                :style="minWidth(900, 140)"
            />
        </Card>

        <div class="mt-3 flex flex-wrap justify-end gap-3">
            <div v-for="branch in branchTotals" :key="branch.id" class="border-[1.5px] border-border bg-bg-subtle px-4 py-2 text-[12.5px] text-text-strong">
                <span class="text-text-muted">{{ branch.name }}:</span> <span class="font-semibold">{{ formatMoney(branch.total) }}</span>
            </div>
            <div class="border-[1.5px] border-border bg-bg-subtle px-4 py-2 text-[13px] font-bold text-text-strong">
                Total valuation: {{ formatMoney(grandTotalValuation) }}
            </div>
        </div>
    </div>
</template>
