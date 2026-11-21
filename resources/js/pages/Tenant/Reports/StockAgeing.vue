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
import Input from '@/components/ui/Input.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import { FileSpreadsheet } from '@lucide/vue';
import { formatMoney, formatQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import { itemLedgerColumn } from '@/lib/itemLedger';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    asOf: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    valuationTotal: { type: String, default: '0.00' },
    negativeStockCount: { type: Number, default: 0 },
    excludedNonStockCount: { type: Number, default: 0 },
    stores: { type: Array, default: () => [] },
    storeId: { type: [Number, null], default: null },
    categories: { type: Array, default: () => [] },
    subcategories: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    categoryId: { type: [Number, null], default: null },
    subcategoryId: { type: [Number, null], default: null },
    brandId: { type: [Number, null], default: null },
    search: { type: String, default: '' },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Stock Ageing');

const asOfInput = ref(props.asOf);
const storeFilter = ref(props.storeId);
const categoryFilter = ref(props.categoryId);
const subcategoryFilter = ref(props.subcategoryId);
const brandFilter = ref(props.brandId);
const searchFilter = ref(props.search);

// The server drops a store outside the chosen branches; follow what it applied.
watch(
    () => [props.asOf, props.storeId, props.stores, props.categoryId, props.subcategoryId, props.brandId, props.search],
    () => {
        asOfInput.value = props.asOf;
        storeFilter.value = props.storeId;
        categoryFilter.value = props.categoryId;
        subcategoryFilter.value = props.subcategoryId;
        brandFilter.value = props.brandId;
        searchFilter.value = props.search;
    },
);

const branchFilter = useBranchReportFilter(() => props.branchReport);
const isLoading = ref(false);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const categoryOptions = computed(() => [
    { value: null, label: 'All categories' },
    ...props.categories.map((category) => ({ value: category.id, label: category.name })),
]);

// Narrowed to the picked category's own subcategories.
const subcategoryOptions = computed(() => [
    { value: null, label: 'All subcategories' },
    ...props.subcategories
        .filter((subcategory) => !categoryFilter.value || subcategory.item_category_id === categoryFilter.value)
        .map((subcategory) => ({ value: subcategory.id, label: subcategory.name })),
]);

const brandOptions = computed(() => [
    { value: null, label: 'All brands' },
    ...props.brands.map((brand) => ({ value: brand.id, label: brand.name })),
]);

// A subcategory that no longer belongs to the picked category is dropped.
watch(categoryFilter, () => {
    const stillValid = subcategoryOptions.value.some((option) => option.value === subcategoryFilter.value);
    if (!stillValid) {
        subcategoryFilter.value = null;
    }
});

const asOfLabel = computed(() => `As of BS ${formatBsDate(props.asOf)} (AD ${props.asOf})`);

const hasActiveFilter = computed(
    () =>
        storeFilter.value !== null ||
        categoryFilter.value !== null ||
        subcategoryFilter.value !== null ||
        brandFilter.value !== null ||
        searchFilter.value.trim() !== '',
);

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            as_of: asOfInput.value,
            store_id: storeFilter.value ?? undefined,
            item_category_id: categoryFilter.value ?? undefined,
            item_subcategory_id: subcategoryFilter.value ?? undefined,
            brand_id: brandFilter.value ?? undefined,
            search: searchFilter.value.trim() || undefined,
            ...branchFilter.toQuery(),
        },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

function resetFilters() {
    storeFilter.value = null;
    categoryFilter.value = null;
    subcategoryFilter.value = null;
    brandFilter.value = null;
    searchFilter.value = '';
    applyFilter();
}

// Built from the currently-applied filters so exporting always matches what's on screen.
const exportUrl = computed(() => {
    const params = new URLSearchParams({ as_of: props.asOf });
    const applied = {
        store_id: props.storeId,
        item_category_id: props.categoryId,
        item_subcategory_id: props.subcategoryId,
        brand_id: props.brandId,
        search: props.search,
    };
    for (const [key, value] of Object.entries(applied)) {
        if (value !== null && value !== '') {
            params.set(key, value);
        }
    }
    branchFilter.appendTo(params);

    return `/reports/stock-ageing/export?${params.toString()}`;
});

const buckets = [
    { key: 'd0_30', label: '0-30 days' },
    { key: 'd31_60', label: '31-60 days' },
    { key: 'd61_90', label: '61-90 days' },
    { key: 'd90_plus', label: 'Over 90 days' },
];

// The applied as-of date, store and branch, so the item ledger's balance matches the row.
const ledgerQuery = computed(() => ({ to: props.asOf, store_id: props.storeId, ...branchFilter.toQuery() }));

const columns = [
    { accessorKey: 'name', header: 'Item' },
    { id: 'code', header: 'Code', numeric: false, cell: ({ row }) => row.original.barcode || row.original.hsCode || '-' },
    { accessorKey: 'unit', header: 'Unit' },
    { id: 'quantity', header: 'Quantity', numeric: true, cell: ({ row }) => formatQuantity(row.original.quantity) },
    { id: 'value', header: 'Value', numeric: true, cell: ({ row }) => formatMoney(row.original.value) },
    {
        id: 'oldestDays',
        header: 'Oldest stock (days)',
        numeric: true,
        cell: ({ row }) => (row.original.oldestDays === null || row.original.oldestDays === undefined ? '-' : String(row.original.oldestDays)),
    },
    ...buckets.flatMap((bucket) => [
        { id: `${bucket.key}Qty`, header: `${bucket.label} qty`, numeric: true, cell: ({ row }) => formatQuantity(row.original[`${bucket.key}Qty`]) },
        { id: `${bucket.key}Value`, header: `${bucket.label} value`, numeric: true, cell: ({ row }) => formatMoney(row.original[`${bucket.key}Value`]) },
    ]),
    { id: 'unagedQuantity', header: 'Unaged qty', numeric: true, cell: ({ row }) => formatQuantity(row.original.unagedQuantity) },
    { id: 'unagedValue', header: 'Unaged value', numeric: true, cell: ({ row }) => formatMoney(row.original.unagedValue) },
    itemLedgerColumn((row) => row.itemId, () => ledgerQuery.value),
];

const reconciles = computed(() => props.totals.value === props.valuationTotal);
</script>

<template>
    <div>
        <PageHeader
            title="Stock Ageing"
            description="How long the stock on hand has been sitting, item by item, grouped by age of the receipt."
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="stock-ageing-branch"
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
            Stock on hand is matched newest-first against the item's receipts (the oldest goods are assumed sold first).
            Values use the same weighted average cost as Stock Valuation, split across the age buckets by quantity.
        </p>

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="w-40">
                <Label class="mb-1 text-[11px]">As of date (BS)</Label>
                <NepaliDateInput v-model="asOfInput" />
            </div>
            <div class="w-56">
                <Label class="mb-1 text-[11px]">Store</Label>
                <Select v-model="storeFilter" :options="storeOptions" />
            </div>
            <div class="w-48">
                <Label class="mb-1 text-[11px]">Category</Label>
                <Select v-model="categoryFilter" :options="categoryOptions" />
            </div>
            <div class="w-48">
                <Label class="mb-1 text-[11px]">Subcategory</Label>
                <Select v-model="subcategoryFilter" :options="subcategoryOptions" />
            </div>
            <div class="w-48">
                <Label class="mb-1 text-[11px]">Brand</Label>
                <Select v-model="brandFilter" :options="brandOptions" />
            </div>
            <div class="w-56">
                <Label class="mb-1 text-[11px]">Item</Label>
                <Input v-model="searchFilter" placeholder="Search item name" @keyup.enter="applyFilter" />
            </div>
            <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
            <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
        </div>

        <div v-if="branchReport" class="-mt-2 mb-4">
            <BranchReportNotes :report="branchReport" hide-label />
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No stock on hand for this date, store or filter. Try a different date, store or filter." />
        </Card>

        <div class="mt-3 overflow-x-auto border-[1.5px] border-border bg-bg-subtle">
            <table class="w-full text-[12.5px]">
                <thead>
                    <tr class="text-text-muted">
                        <th class="px-4 py-1.5 text-left font-semibold">Totals</th>
                        <th v-for="bucket in buckets" :key="bucket.key" class="px-4 py-1.5 text-right font-semibold">{{ bucket.label }}</th>
                        <th class="px-4 py-1.5 text-right font-semibold">Unaged</th>
                        <th class="px-4 py-1.5 text-right font-bold text-text-strong">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="px-4 py-1.5 text-text-muted">Quantity</td>
                        <td v-for="bucket in buckets" :key="bucket.key" class="px-4 py-1.5 text-right font-semibold">{{ formatQuantity(totals[`${bucket.key}Qty`] ?? '0.0000') }}</td>
                        <td class="px-4 py-1.5 text-right font-semibold">{{ formatQuantity(totals.unagedQuantity ?? '0.0000') }}</td>
                        <td class="px-4 py-1.5 text-right font-bold">{{ formatQuantity(totals.quantity ?? '0.0000') }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-1.5 text-text-muted">Value</td>
                        <td v-for="bucket in buckets" :key="bucket.key" class="px-4 py-1.5 text-right font-semibold">{{ formatMoney(totals[`${bucket.key}Value`] ?? '0.00') }}</td>
                        <td class="px-4 py-1.5 text-right font-semibold">{{ formatMoney(totals.unagedValue ?? '0.00') }}</td>
                        <td class="px-4 py-1.5 text-right font-bold">{{ formatMoney(totals.value ?? '0.00') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-[11.5px] text-text-faint">
            <template v-if="reconciles">Total value equals the Stock Valuation report for the same date, store and filters ({{ formatMoney(valuationTotal) }}).</template>
            <template v-else>Total value {{ formatMoney(totals.value ?? '0.00') }} differs from Stock Valuation {{ formatMoney(valuationTotal) }}; please report this.</template>
            Only stockable items are listed
            <template v-if="excludedNonStockCount > 0">({{ excludedNonStockCount }} service / capital / non-stock items are excluded because they carry no stock)</template>.
            <template v-if="negativeStockCount > 0">
                {{ negativeStockCount }} item(s) have negative stock; their quantity and value are shown under "Unaged" and should be corrected with a stock adjustment.
            </template>
            "Unaged" also holds any quantity on hand that has no recorded receipt to date it by.
        </p>
    </div>
</template>
