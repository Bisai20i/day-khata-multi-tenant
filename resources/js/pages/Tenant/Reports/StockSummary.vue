<script setup>
import { computed, h, ref, watch } from 'vue';
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
import { FileSpreadsheet } from '@lucide/vue';
import { formatMoney, formatQuantity, formatRate, parseQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { useBranchLabel } from '@/composables/useBranchLabel';
import { usePermissions } from '@/composables/usePermissions';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import { itemLedgerColumn } from '@/lib/itemLedger';

defineOptions({ layout: AppLayout });

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    grandTotalValuation: { type: String, default: '0.00' },
    stores: { type: Array, default: () => [] },
    storeId: { type: [Number, null], default: null },
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
    // Goods between branches as at `to` (BR19): { buckets: ['within'|'outgoing'|'incoming'], totals: { bucket: value } };
    // null unless something is in transit, so the page is unchanged otherwise.
    inTransit: { type: [Object, null], default: null },
});

const { label, plural } = useBranchLabel();
const { can } = usePermissions();

useLayoutChrome('Stock Summary');

const fromInput = ref(props.from);
const toInput = ref(props.to);
const storeIdInput = ref(props.storeId);
const categoryInput = ref(props.itemCategoryId);
const subcategoryInput = ref(props.itemSubcategoryId);
const brandInput = ref(props.brandId);
const searchInput = ref(props.search);

// The server drops a store outside the chosen branches; follow what it applied.
watch(
    () => [props.storeId, props.stores],
    () => {
        storeIdInput.value = props.storeId;
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

const isLoading = ref(false);

const hasActiveFilter = computed(
    () =>
        storeIdInput.value !== null
        || categoryInput.value !== null
        || subcategoryInput.value !== null
        || brandInput.value !== null
        || searchInput.value.trim() !== '',
);

function resetFilters() {
    storeIdInput.value = null;
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
            from: fromInput.value,
            to: toInput.value,
            store_id: storeIdInput.value ?? undefined,
            item_category_id: categoryInput.value ?? undefined,
            item_subcategory_id: subcategoryInput.value ?? undefined,
            brand_id: brandInput.value ?? undefined,
            search: searchInput.value.trim() || undefined,
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

// Built from the currently-applied props (never the unapplied inputs) so the export matches what's on screen.
const exportUrl = computed(() => {
    const params = new URLSearchParams({ from: props.from, to: props.to });

    if (props.storeId) {
        params.set('store_id', String(props.storeId));
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

    return `/reports/stock-summary/export?${params.toString()}`;
});

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

// In-transit columns (BR19), one per bucket the server reports: 'within' is
// what Closing qty is short of Opening + In - Out, 'outgoing' is already in
// Qty out, 'incoming' has not reached these stores yet.
const TRANSIT_HEADERS = {
    within: 'In transit',
    outgoing: 'Dispatched, awaiting receipt',
    incoming: 'Incoming',
};

const transitBuckets = computed(() => props.inTransit?.buckets ?? []);

// Exact: the server sends canonical 4dp strings; no Number() on quantities.
function isZeroQuantity(value) {
    const parsed = parseQuantity(value ?? '0');

    return parsed.ok && parsed.value === parseQuantity('0').value;
}

function transitCell(row, bucket) {
    const figures = row.transit?.[bucket];

    if (!figures || isZeroQuantity(figures.quantity)) {
        return '-';
    }

    return h('div', { class: 'whitespace-nowrap' }, [
        h('div', formatQuantity(figures.quantity)),
        h('div', { class: 'text-[11px] text-text-faint' }, formatMoney(figures.value)),
    ]);
}

const baseColumns = [
    { accessorKey: 'name', header: 'Item' },
    { accessorKey: 'unit', header: 'Unit' },
    { accessorKey: 'opening', header: 'Opening qty', numeric: true, cell: ({ row }) => formatQuantity(row.original.opening) },
    { accessorKey: 'qtyIn', header: 'Qty in', numeric: true, cell: ({ row }) => formatQuantity(row.original.qtyIn) },
    { accessorKey: 'qtyOut', header: 'Qty out', numeric: true, cell: ({ row }) => formatQuantity(row.original.qtyOut) },
    { accessorKey: 'closing', header: 'Closing qty', numeric: true, cell: ({ row }) => formatQuantity(row.original.closing) },
];

// The applied period, store and branch, so the item ledger's balance matches the row.
const ledgerQuery = computed(() => {
    const branchQuery = branchFilter.toQuery();
    delete branchQuery.by_branch;

    return { from: props.from, to: props.to, store_id: props.storeId, ...branchQuery };
});

const valueColumns = [
    { accessorKey: 'avgCost', header: 'Average cost per unit', numeric: true, cell: ({ row }) => formatRate(row.original.avgCost) },
    { accessorKey: 'valuation', header: 'Valuation', numeric: true, cell: ({ row }) => formatMoney(row.original.valuation) },
    itemLedgerColumn((row) => row.itemId, () => ledgerQuery.value),
];

const columns = computed(() => [
    ...baseColumns,
    ...transitBuckets.value.map((bucket) => ({
        id: `transit-${bucket}`,
        header: TRANSIT_HEADERS[bucket] ?? bucket,
        numeric: true,
        cell: ({ row }) => transitCell(row.original, bucket),
    })),
    ...valueColumns,
]);

// DataTable reads its columns once: a new key when the transit columns change.
const columnsKey = computed(() => transitBuckets.value.join('|') || 'plain');
</script>

<template>
    <div>
        <PageHeader
            title="Stock Summary"
            description="Opening stock, stock in, stock out and closing stock for each item in the selected dates."
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="stock-summary-branch"
                hide-label
                @change="applyFilter"
            />
            <Button v-if="can('stock_reports.export')" as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export to Excel
            </Button>
        </PageHeader>

        <p class="mb-1 text-[12.5px] text-text-muted">Showing report for {{ rangeLabel }}</p>

        <p class="mb-4 text-[12.5px] text-text-muted">
            With every store combined, transfers between your own stores are left out of Qty In and Qty Out; relocating goods is not stock entering or leaving the business.
        </p>

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="w-40">
                <Label class="mb-1 text-[11px]">From date (BS)</Label>
                <NepaliDateInput v-model="fromInput" />
            </div>
            <div class="w-40">
                <Label class="mb-1 text-[11px]">To date (BS)</Label>
                <NepaliDateInput v-model="toInput" />
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
            <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
            <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
        </div>

        <div v-if="branchReport" class="-mt-2 mb-4">
            <BranchReportNotes :report="branchReport" hide-label />
        </div>

        <div v-if="inTransit" class="mb-4 space-y-1 text-[12.5px] text-text-muted">
            <p v-if="transitBuckets.includes('within')">
                <span class="font-semibold text-text-strong">In transit</span>: goods sent between {{ plural('lower') }} that have left
                the sending store and not yet been received, as at {{ formatBsDate(to) }}. They have already left Closing qty
                and Valuation. A transfer between two stores in view is left out of Qty in and Qty out, so these goods, and any that
                were in transit at the start of the period, explain why Opening + Qty in - Qty out may differ from Closing qty.
            </p>
            <p v-if="transitBuckets.includes('outgoing')">
                <span class="font-semibold text-text-strong">Dispatched, awaiting receipt</span>: sent from these stores to
                another {{ label('lower') }} and counted in Qty out when it was sent in this period.
            </p>
            <p v-if="transitBuckets.includes('incoming')">
                <span class="font-semibold text-text-strong">Incoming</span>: on the way to these stores and not yet in their stock.
            </p>
            <p>Values are what each transfer was valued at when it was dispatched.</p>
        </div>

        <Card variant="panel">
            <DataTable
                :key="columnsKey"
                :columns="columns"
                :data="rows"
                :page-size="25"
                empty-message="No records for this period or filter. Try widening the date range."
            />
        </Card>

        <div class="mt-3 flex flex-wrap justify-end gap-2">
            <div
                v-for="bucket in transitBuckets"
                :key="`transit-total-${bucket}`"
                class="border-[1.5px] border-border bg-bg-subtle px-4 py-2 text-[13px] text-text-base"
            >
                {{ TRANSIT_HEADERS[bucket] ?? bucket }}: {{ formatMoney(inTransit.totals[bucket]) }}
            </div>
            <div class="border-[1.5px] border-border bg-bg-subtle px-4 py-2 text-[13px] font-bold text-text-strong">
                Total valuation: {{ formatMoney(grandTotalValuation) }}
            </div>
        </div>
    </div>
</template>
