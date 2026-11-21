<script setup>
import { computed, h, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import { FileSpreadsheet } from '@lucide/vue';
import { formatQuantity, formatRate } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { itemLedgerColumn } from '@/lib/itemLedger';
import { stockBranchQuery } from '@/lib/stockBranchReport.js';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { useBranchReportColumns } from '@/composables/useBranchReportColumns';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import StockMovementBranchSummary from '@/components/branch/StockMovementBranchSummary.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    movements: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    subcategories: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    itemId: { type: [Number, null], default: null },
    storeId: { type: [Number, null], default: null },
    categoryId: { type: [Number, null], default: null },
    subcategoryId: { type: [Number, null], default: null },
    brandId: { type: [Number, null], default: null },
    // Applied direction filter: 'all', 'in' or 'out'.
    direction: { type: String, default: 'all' },
    // Applied movement type filter (StockMovementType value), null for all.
    movementType: { type: [String, null], default: null },
    // { value, label, direction: 'in'|'out' } per StockMovementType case.
    movementTypes: { type: Array, default: () => [] },
    // Per base unit over the whole result: { unit, in, out, net } exact strings.
    totals: { type: Array, default: () => [] },
    // True when only the first `rowLimit` rows were sent; totals still cover all.
    truncated: { type: Boolean, default: false },
    rowLimit: { type: Number, default: 5000 },
    // Branch filter and the by-branch summary; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Stock Movement Register');

const { can } = usePermissions();

const fromInput = ref(props.from);
const toInput = ref(props.to);
const itemIdInput = ref(props.itemId);
const storeIdInput = ref(props.storeId);
const categoryIdInput = ref(props.categoryId);
const subcategoryIdInput = ref(props.subcategoryId);
const brandIdInput = ref(props.brandId);
const directionChoice = ref(props.direction);
const typeChoice = ref(props.movementType);

// Follow what the server applied (it drops a store outside the chosen branches,
// and a fresh visit or Back button brings new props).
watch(
    () => [
        props.from,
        props.to,
        props.itemId,
        props.storeId,
        props.stores,
        props.categoryId,
        props.subcategoryId,
        props.brandId,
        props.direction,
        props.movementType,
    ],
    () => {
        fromInput.value = props.from;
        toInput.value = props.to;
        itemIdInput.value = props.itemId;
        storeIdInput.value = props.storeId;
        categoryIdInput.value = props.categoryId;
        subcategoryIdInput.value = props.subcategoryId;
        brandIdInput.value = props.brandId;
        directionChoice.value = props.direction;
        typeChoice.value = props.movementType;
    },
);

const branchFilter = useBranchReportFilter(() => props.branchReport, { byBranch: true });
const { byBranch: hasBranchSummary } = useBranchReportColumns(() => props.branchReport);

const itemOptions = computed(() => [
    { value: null, label: 'All items' },
    ...props.items.map((item) => ({ value: item.id, label: item.name })),
]);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const categoryOptions = computed(() => [
    { value: null, label: 'All categories' },
    ...props.categories.map((category) => ({ value: category.id, label: category.name })),
]);

// Narrowed to the picked category's own subcategories, same convention as
// the item-wise reports' subcategory picker.
const subcategoryOptions = computed(() => [
    { value: null, label: 'All subcategories' },
    ...props.subcategories
        .filter((subcategory) => !categoryIdInput.value || subcategory.item_category_id === categoryIdInput.value)
        .map((subcategory) => ({ value: subcategory.id, label: subcategory.name })),
]);

const brandOptions = computed(() => [
    { value: null, label: 'All brands' },
    ...props.brands.map((brand) => ({ value: brand.id, label: brand.name })),
]);

const directionOptions = [
    { value: 'all', label: 'In & Out' },
    { value: 'in', label: 'Stock in only' },
    { value: 'out', label: 'Stock out only' },
];

// Narrowed to the chosen direction's own movement types.
const typeOptions = computed(() => [
    { value: null, label: 'All movement types' },
    ...props.movementTypes
        .filter((type) => directionChoice.value === 'all' || type.direction === directionChoice.value)
        .map((type) => ({ value: type.value, label: type.label })),
]);

// A type that points the other way would only ever return an empty register.
watch(directionChoice, (direction) => {
    const picked = props.movementTypes.find((type) => type.value === typeChoice.value);
    if (picked && direction !== 'all' && picked.direction !== direction) {
        typeChoice.value = null;
    }
});

const isLoading = ref(false);

const hasActiveFilter = computed(
    () =>
        itemIdInput.value !== null ||
        storeIdInput.value !== null ||
        categoryIdInput.value !== null ||
        subcategoryIdInput.value !== null ||
        brandIdInput.value !== null ||
        directionChoice.value !== 'all' ||
        typeChoice.value !== null,
);

function resetFilters() {
    itemIdInput.value = null;
    storeIdInput.value = null;
    categoryIdInput.value = null;
    subcategoryIdInput.value = null;
    brandIdInput.value = null;
    directionChoice.value = 'all';
    typeChoice.value = null;
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            from: fromInput.value,
            to: toInput.value,
            item_id: itemIdInput.value ?? undefined,
            store_id: storeIdInput.value ?? undefined,
            category_id: categoryIdInput.value ?? undefined,
            subcategory_id: subcategoryIdInput.value ?? undefined,
            brand_id: brandIdInput.value ?? undefined,
            direction: directionChoice.value === 'all' ? undefined : directionChoice.value,
            type: typeChoice.value ?? undefined,
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

// Built from the currently-applied props so exporting always matches what's on screen.
const exportUrl = computed(() => {
    const params = new URLSearchParams({ from: props.from, to: props.to });
    const applied = {
        item_id: props.itemId,
        store_id: props.storeId,
        category_id: props.categoryId,
        subcategory_id: props.subcategoryId,
        brand_id: props.brandId,
        direction: props.direction === 'all' ? null : props.direction,
        type: props.movementType,
    };
    for (const [key, value] of Object.entries(applied)) {
        if (value !== null && value !== undefined) {
            params.set(key, String(value));
        }
    }
    branchFilter.appendTo(params);

    return `/reports/stock-movement-register/export?${params.toString()}`;
});

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

// The quantity arrives as an exact 4-decimal string, so its sign is read
// off the string itself - parsing it back into a float just to pick a
// colour is the habit this rewrite removed.
function quantitySign(value) {
    if (value.startsWith('-')) {
        return 'text-danger';
    }

    return /[1-9]/.test(value) ? 'text-success' : '';
}

// The applied period, store and branch, so the item ledger's balance matches the register.
const ledgerQuery = computed(() => {
    const branchQuery = branchFilter.toQuery();
    delete branchQuery.by_branch;

    return { from: props.from, to: props.to, store_id: props.storeId, ...branchQuery };
});

const columns = [
    { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { accessorKey: 'date', header: 'Date (AD)' },
    { accessorKey: 'itemName', header: 'Item' },
    { id: 'storeName', header: 'Store', numeric: false, cell: ({ row }) => row.original.storeName ?? '-' },
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
                (row.original.quantity.startsWith('-') ? '' : '+') + formatQuantity(row.original.quantity),
            ),
    },
    {
        id: 'unitCostRate',
        header: 'Cost per unit',
        numeric: true,
        cell: ({ row }) => (row.original.unitCostRate === null ? '-' : formatRate(row.original.unitCostRate)),
    },
    { accessorKey: 'reference', header: 'Reference' },
    itemLedgerColumn((row) => row.itemId, () => ledgerQuery.value),
];
</script>

<template>
    <div>
        <PageHeader
            title="Stock Movement Register"
            description="Every stock in and out movement in the selected dates, line by line."
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                v-model:by-branch="branchFilter.byBranch.value"
                :report="branchReport"
                :allow-by-branch="storeIdInput === null"
                id-prefix="stock-movement-branch"
                hide-label
                @change="applyFilter"
            />
            <Button v-if="can('stock_reports.export')" as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export to Excel
            </Button>
        </PageHeader>

        <p class="mb-4 text-[12.5px] text-text-muted">Showing report for {{ rangeLabel }}</p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <Label class="mb-1">From date (BS)</Label>
                    <NepaliDateInput v-model="fromInput" />
                </div>
                <div>
                    <Label class="mb-1">To date (BS)</Label>
                    <NepaliDateInput v-model="toInput" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Item</Label>
                    <Select v-model="itemIdInput" :options="itemOptions" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Store</Label>
                    <Select v-model="storeIdInput" :options="storeOptions" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Category</Label>
                    <Select v-model="categoryIdInput" :options="categoryOptions" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Subcategory</Label>
                    <Select v-model="subcategoryIdInput" :options="subcategoryOptions" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Brand</Label>
                    <Select v-model="brandIdInput" :options="brandOptions" />
                </div>
                <div class="w-48">
                    <Label class="mb-1">Direction</Label>
                    <Select v-model="directionChoice" :options="directionOptions" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Movement type</Label>
                    <Select v-model="typeChoice" :options="typeOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
        </Card>

        <StockMovementBranchSummary v-if="hasBranchSummary" :report="branchReport" />

        <div
            v-if="truncated"
            class="mb-3 rounded border border-warning-text bg-warning-bg px-3 py-2 text-[12.5px] font-semibold text-warning-text"
        >
            Showing the first {{ rowLimit.toLocaleString() }} movements only. Narrow the date range or filters, or export to Excel for every
            row. The totals below cover all movements.
        </div>

        <Card variant="panel" class="mb-4">
            <DataTable :columns="columns" :data="movements" :page-size="25" empty-message="No records for this period or filter. Try widening the date range." />
        </Card>

        <Card v-if="totals.length" variant="panel">
            <div class="mb-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Totals by unit</div>
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="border-b border-border text-left text-text-muted">
                        <th class="py-1.5 font-semibold">Unit</th>
                        <th class="py-1.5 text-right font-semibold">Total in</th>
                        <th class="py-1.5 text-right font-semibold">Total out</th>
                        <th class="py-1.5 text-right font-semibold">Net</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="total in totals" :key="total.unit">
                        <td class="py-1.5">{{ total.unit || '-' }}</td>
                        <td class="py-1.5 text-right">{{ formatQuantity(total.in) }}</td>
                        <td class="py-1.5 text-right">{{ formatQuantity(total.out) }}</td>
                        <td class="py-1.5 text-right font-bold" :class="quantitySign(total.net)">{{ formatQuantity(total.net) }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>
    </div>
</template>
