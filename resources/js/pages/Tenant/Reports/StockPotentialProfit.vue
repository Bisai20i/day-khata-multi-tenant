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
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    asOf: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    // costValue covers every row (ties to Stock Valuation); sellValue, profit and
    // marginPercent cover only items with a selling rate; pricedCostValue is their cost.
    totals: {
        type: Object,
        default: () => ({ costValue: '0.00', pricedCostValue: '0.00', sellValue: '0.00', profit: '0.00', marginPercent: null }),
    },
    unpricedCount: { type: Number, default: 0 },
    stores: { type: Array, default: () => [] },
    storeId: { type: [Number, null], default: null },
    includeEmpty: { type: Boolean, default: false },
    sort: { type: String, default: 'profit' },
    // Applied item filters (null / '' when not filtering) and the select options for them.
    itemCategoryId: { type: [Number, null], default: null },
    itemSubcategoryId: { type: [Number, null], default: null },
    brandId: { type: [Number, null], default: null },
    search: { type: String, default: '' },
    filterOptions: {
        type: Object,
        default: () => ({ categories: [], subcategories: [], brands: [] }),
    },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

const { can } = usePermissions();

useLayoutChrome('Stock with Potential Profit');

const asOfInput = ref(props.asOf);
const storeIdInput = ref(props.storeId);
const includeEmptyInput = ref(props.includeEmpty);
const sortInput = ref(props.sort);
const categoryInput = ref(props.itemCategoryId);
const subcategoryInput = ref(props.itemSubcategoryId);
const brandInput = ref(props.brandId);
const searchInput = ref(props.search);

// Follow what the server applied (it drops a store outside the chosen branches,
// and a fresh visit or Back button brings new props).
watch(
    () => [props.asOf, props.storeId, props.stores, props.includeEmpty, props.sort, props.itemCategoryId, props.itemSubcategoryId, props.brandId, props.search],
    () => {
        asOfInput.value = props.asOf;
        storeIdInput.value = props.storeId;
        includeEmptyInput.value = props.includeEmpty;
        sortInput.value = props.sort;
        categoryInput.value = props.itemCategoryId;
        subcategoryInput.value = props.itemSubcategoryId;
        brandInput.value = props.brandId;
        searchInput.value = props.search;
    },
);

const branchFilter = useBranchReportFilter(() => props.branchReport);

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

// The server sorts (exact decimals); when sorting by profit, margin or sell value, items without a selling rate come last.
const sortOptions = [
    { value: 'profit', label: 'Potential profit, highest first' },
    { value: 'margin', label: 'Margin %, highest first' },
    { value: 'sell_value', label: 'Value at sell, highest first' },
    { value: 'cost_value', label: 'Value at cost, highest first' },
    { value: 'name', label: 'Item name' },
];

const isLoading = ref(false);

const hasActiveFilter = computed(
    () =>
        storeIdInput.value !== null
        || includeEmptyInput.value
        || sortInput.value !== 'profit'
        || categoryInput.value !== null
        || subcategoryInput.value !== null
        || brandInput.value !== null
        || searchInput.value.trim() !== '',
);

function resetFilters() {
    storeIdInput.value = null;
    includeEmptyInput.value = false;
    sortInput.value = 'profit';
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
            include_empty: includeEmptyInput.value ? 1 : undefined,
            sort: sortInput.value === 'profit' ? undefined : sortInput.value,
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
    const params = new URLSearchParams({ as_of: props.asOf, sort: props.sort });

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

    return `/reports/stock-potential-profit/export?${params.toString()}`;
});

const asOfLabel = computed(() => `As of BS ${formatBsDate(props.asOf)} (AD ${props.asOf})`);

const dash = (value, format) => (value === null || value === undefined ? '-' : format(value));
const percent = (value) => `${value}%`;

// The applied as-of date, store and branch, so the item ledger's balance matches the row.
const ledgerQuery = computed(() => ({ to: props.asOf, store_id: props.storeId, ...branchFilter.toQuery() }));

// Server order is the sort; numeric columns are not re-sorted client-side (string decimals).
const columns = [
    { accessorKey: 'name', header: 'Item' },
    { accessorKey: 'unit', header: 'Unit', enableSorting: false },
    { accessorKey: 'quantity', header: 'Quantity', numeric: true, enableSorting: false, cell: ({ row }) => formatQuantity(row.original.quantity) },
    { accessorKey: 'costRate', header: 'Cost rate', numeric: true, enableSorting: false, cell: ({ row }) => formatRate(row.original.costRate) },
    { accessorKey: 'sellRate', header: 'Sell rate', numeric: true, enableSorting: false, cell: ({ row }) => dash(row.original.sellRate, formatRate) },
    { accessorKey: 'costValue', header: 'Value at cost', numeric: true, enableSorting: false, cell: ({ row }) => formatMoney(row.original.costValue) },
    { accessorKey: 'sellValue', header: 'Value at sell', numeric: true, enableSorting: false, cell: ({ row }) => dash(row.original.sellValue, formatMoney) },
    { accessorKey: 'profit', header: 'Potential profit', numeric: true, enableSorting: false, cell: ({ row }) => dash(row.original.profit, formatMoney) },
    { accessorKey: 'marginPercent', header: 'Margin', numeric: true, enableSorting: false, cell: ({ row }) => dash(row.original.marginPercent, percent) },
    itemLedgerColumn((row) => row.itemId, () => ledgerQuery.value),
];
</script>

<template>
    <div>
        <PageHeader
            title="Stock with Potential Profit"
            description="What your stock on hand cost, what it would sell for at today's selling rates, and the difference."
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="stock-potential-profit-branch"
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
            Cost is the weighted average cost per base unit, the same basis the Balance Sheet uses, so the value at cost total
            equals the Stock Valuation total for the same date, store, branch and item filters (stock status "All"). Sell rate is
            the selling rate on the item record, before VAT. Potential profit is value at sell less value at cost; margin is
            profit as a percentage of value at sell.
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
            <div class="w-60">
                <Label class="mb-1 text-[11px]">Sort by</Label>
                <Select v-model="sortInput" :options="sortOptions" />
            </div>
            <CheckboxField
                id="stock-potential-profit-include-empty"
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
        </div>

        <p
            v-if="unpricedCount > 0"
            class="mb-3 border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[12.5px] text-text-strong"
        >
            {{ unpricedCount }} {{ unpricedCount === 1 ? 'item holding stock has' : 'items holding stock have' }} no selling rate. They show a dash and are
            left out of the value at sell and potential profit totals (cost of the priced items: {{ formatMoney(totals.pricedCostValue) }}).
            Set a selling rate on the item to include it.
        </p>

        <Card variant="panel">
            <DataTable
                :columns="columns"
                :data="rows"
                :page-size="25"
                empty-message="No records for this date or filter. Try a different date or store."
            />
        </Card>

        <div class="mt-3 flex flex-wrap justify-end gap-3">
            <div class="border-[1.5px] border-border bg-bg-subtle px-4 py-2 text-[12.5px] text-text-strong">
                <span class="text-text-muted">Value at cost:</span> <span class="font-semibold">{{ formatMoney(totals.costValue) }}</span>
            </div>
            <div class="border-[1.5px] border-border bg-bg-subtle px-4 py-2 text-[12.5px] text-text-strong">
                <span class="text-text-muted">Value at sell:</span> <span class="font-semibold">{{ formatMoney(totals.sellValue) }}</span>
            </div>
            <div class="border-[1.5px] border-border bg-bg-subtle px-4 py-2 text-[13px] font-bold text-text-strong">
                Potential profit: {{ formatMoney(totals.profit) }}
                <span v-if="totals.marginPercent !== null" class="font-semibold text-text-muted">({{ totals.marginPercent }}%)</span>
            </div>
        </div>
    </div>
</template>
