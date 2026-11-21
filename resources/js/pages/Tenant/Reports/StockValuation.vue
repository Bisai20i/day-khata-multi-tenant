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
import Input from '@/components/ui/Input.vue';
import CheckboxField from '@/components/ui/CheckboxField.vue';
import { FileSpreadsheet } from '@lucide/vue';
import { formatMoney, formatQuantity, formatRate } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { itemLedgerColumn } from '@/lib/itemLedger';
import { stockBranchQuery } from '@/lib/stockBranchReport.js';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { useStockValuationBranchColumns } from '@/composables/useStockValuationBranchColumns';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import { useBranchLabel } from '@/composables/useBranchLabel';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    asOf: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    grandTotalValuation: { type: String, default: '0.00' },
    stores: { type: Array, default: () => [] },
    storeId: { type: [Number, null], default: null },
    stockStatus: { type: String, default: 'all' },
    // Applied item filters (null / '' when not filtering) and the select options for them.
    includeEmpty: { type: Boolean, default: false },
    itemCategoryId: { type: [Number, null], default: null },
    itemSubcategoryId: { type: [Number, null], default: null },
    brandId: { type: [Number, null], default: null },
    search: { type: String, default: '' },
    filterOptions: {
        type: Object,
        default: () => ({ categories: [], subcategories: [], brands: [] }),
    },
    // Branch filter and by-branch columns; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

const { label } = useBranchLabel();
const { can } = usePermissions();

useLayoutChrome('Stock Valuation');

const asOfInput = ref(props.asOf);
const storeIdInput = ref(props.storeId);
const stockStatusInput = ref(props.stockStatus);
const includeEmptyInput = ref(props.includeEmpty);
const categoryInput = ref(props.itemCategoryId);
const subcategoryInput = ref(props.itemSubcategoryId);
const brandInput = ref(props.brandId);
const searchInput = ref(props.search);

// Follow what the server applied (it drops a store outside the chosen branches,
// and a fresh visit or Back button brings new props).
watch(
    () => [props.asOf, props.storeId, props.stores, props.stockStatus, props.includeEmpty, props.itemCategoryId, props.itemSubcategoryId, props.brandId, props.search],
    () => {
        asOfInput.value = props.asOf;
        storeIdInput.value = props.storeId;
        stockStatusInput.value = props.stockStatus;
        includeEmptyInput.value = props.includeEmpty;
        categoryInput.value = props.itemCategoryId;
        subcategoryInput.value = props.itemSubcategoryId;
        brandInput.value = props.brandId;
        searchInput.value = props.search;
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

const categoryOptions = computed(() => [
    { value: null, label: 'All categories' },
    ...props.filterOptions.categories.map((category) => ({ value: category.id, label: category.name })),
]);

// Sub-categories narrow to the chosen category.
const subcategoryOptions = computed(() => [
    { value: null, label: 'All sub-categories' },
    ...props.filterOptions.subcategories
        .filter((sub) => categoryInput.value === null || sub.item_category_id === categoryInput.value)
        .map((sub) => ({ value: sub.id, label: sub.name })),
]);

const brandOptions = computed(() => [
    { value: null, label: 'All brands' },
    ...props.filterOptions.brands.map((brand) => ({ value: brand.id, label: brand.name })),
]);

// A sub-category from another category can no longer be chosen.
watch(categoryInput, () => {
    if (!subcategoryOptions.value.some((option) => option.value === subcategoryInput.value)) {
        subcategoryInput.value = null;
    }
});

// Mirrors legacy's stock_status filter: hunting down negative-stock
// data-entry errors is the whole reason this control exists. 'all' applies
// no sign filter, the same rows the report has always shown.
const stockStatusOptions = [
    { value: 'all', label: 'All' },
    { value: 'positive', label: 'Positive' },
    { value: 'negative', label: 'Negative' },
];

const isLoading = ref(false);

const hasActiveFilter = computed(
    () =>
        storeIdInput.value !== null
        || stockStatusInput.value !== 'all'
        || includeEmptyInput.value
        || categoryInput.value !== null
        || subcategoryInput.value !== null
        || brandInput.value !== null
        || searchInput.value.trim() !== '',
);

function resetFilters() {
    storeIdInput.value = null;
    stockStatusInput.value = 'all';
    includeEmptyInput.value = false;
    categoryInput.value = null;
    subcategoryInput.value = null;
    brandInput.value = null;
    searchInput.value = '';
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            as_of: asOfInput.value,
            store_id: storeIdInput.value ?? undefined,
            stock_status: stockStatusInput.value,
            include_empty: includeEmptyInput.value ? 1 : undefined,
            item_category_id: categoryInput.value ?? undefined,
            item_subcategory_id: subcategoryInput.value ?? undefined,
            brand_id: brandInput.value ?? undefined,
            search: searchInput.value.trim() || undefined,
            ...stockBranchQuery(branchFilter, storeIdInput.value),
        },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

// Built from the currently-applied props (never the unapplied inputs) so the export matches what's on screen.
const exportUrl = computed(() => {
    const params = new URLSearchParams({ as_of: props.asOf, stock_status: props.stockStatus });

    if (props.storeId) {
        params.set('store_id', String(props.storeId));
    }
    if (props.includeEmpty) {
        params.set('include_empty', '1');
    }
    if (props.itemCategoryId) {
        params.set('item_category_id', String(props.itemCategoryId));
    }
    if (props.itemSubcategoryId) {
        params.set('item_subcategory_id', String(props.itemSubcategoryId));
    }
    if (props.brandId) {
        params.set('brand_id', String(props.brandId));
    }
    if (props.search !== '') {
        params.set('search', props.search);
    }
    branchFilter.appendTo(params);

    return `/reports/stock-valuation/export?${params.toString()}`;
});

const asOfLabel = computed(() => `As of BS ${formatBsDate(props.asOf)} (AD ${props.asOf})`);

const baseColumns = [
    { accessorKey: 'name', header: 'Item' },
    { id: 'hsCode', header: 'HS Code', numeric: false, cell: ({ row }) => row.original.hsCode ?? '-' },
    { accessorKey: 'unit', header: 'Unit' },
    { accessorKey: 'quantity', header: 'Quantity', numeric: true, cell: ({ row }) => formatQuantity(row.original.quantity) },
    { accessorKey: 'avgCost', header: 'Average cost per unit', numeric: true, cell: ({ row }) => formatRate(row.original.avgCost) },
    { accessorKey: 'valuation', header: 'Valuation', numeric: true, cell: ({ row }) => formatMoney(row.original.valuation) },
];

// The applied as-of date, store and branch, so the item ledger's balance matches the row.
const ledgerQuery = computed(() => {
    const branchQuery = branchFilter.toQuery();
    delete branchQuery.by_branch;

    return { to: props.asOf, store_id: props.storeId, ...branchQuery };
});

// The ledger link stays last; the branch columns (quantity over value) sit before it.
const columns = computed(() => [
    ...baseColumns,
    ...branchColumns.value,
    itemLedgerColumn((row) => row.itemId, () => ledgerQuery.value),
]);
</script>

<template>
    <div>
        <PageHeader
            title="Stock Valuation"
            description="What your stock on hand is worth on a chosen date, item by item."
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                v-model:by-branch="branchFilter.byBranch.value"
                :report="branchReport"
                :allow-by-branch="storeIdInput === null"
                id-prefix="stock-valuation-branch"
                hide-label
                @change="applyFilter"
            />
            <Button v-if="can('stock_reports.export')" as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export to Excel
            </Button>
        </PageHeader>

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
                <Select v-model="storeIdInput" :options="storeOptions" />
            </div>
            <div class="w-40">
                <Label class="mb-1 text-[11px]">Stock status</Label>
                <Select v-model="stockStatusInput" :options="stockStatusOptions" />
            </div>
            <div class="w-48">
                <Label class="mb-1 text-[11px]">Category</Label>
                <Select v-model="categoryInput" :options="categoryOptions" />
            </div>
            <div class="w-48">
                <Label class="mb-1 text-[11px]">Sub-category</Label>
                <Select v-model="subcategoryInput" :options="subcategoryOptions" />
            </div>
            <div class="w-48">
                <Label class="mb-1 text-[11px]">Brand</Label>
                <Select v-model="brandInput" :options="brandOptions" />
            </div>
            <div class="w-56">
                <Label class="mb-1 text-[11px]">Item name</Label>
                <Input v-model="searchInput" placeholder="Search items..." @keydown.enter.prevent="applyFilter" />
            </div>
            <CheckboxField
                id="stock-valuation-include-empty"
                v-model="includeEmptyInput"
                label="Show zero-stock items"
                hint="Also list items with no quantity and no value on this date."
                class="pb-2"
            />
            <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
            <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
        </div>

        <div v-if="branchReport" class="-mt-2 mb-4">
            <BranchReportNotes :report="branchReport" hide-label />
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
