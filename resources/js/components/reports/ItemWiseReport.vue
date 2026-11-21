<script setup>
// Shared body of the Item-wise Sales and Item-wise Purchase pages: the two
// controllers return the same payload shape, only the wording, the export
// URL and the document links differ (see `kind`).
import { computed, h, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { FileSpreadsheet } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import { formatMoney, formatQuantity, formatRate } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { useBranchLabel } from '@/composables/useBranchLabel';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';

const props = defineProps({
    kind: { type: String, required: true, validator: (value) => ['sales', 'purchase'].includes(value) },
    items: { type: Array, default: () => [] },
    totals: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    lineTotals: { type: Object, required: true },
    selectedItemName: { type: [String, null], default: null },
    itemsList: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    subcategories: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
    itemId: { type: [Number, null], default: null },
    categoryId: { type: [Number, null], default: null },
    subcategoryId: { type: [Number, null], default: null },
    brandId: { type: [Number, null], default: null },
    search: { type: String, default: '' },
    branchReport: { type: [Object, null], default: null },
});

const WORDING = {
    sales: {
        title: 'Item-wise Sales',
        description: 'How much of each item you sold in the selected dates, with the sales amount, VAT and returns.',
        slug: 'item-wise-sales',
        exportPermission: 'sales_reports.export',
        verb: 'Sold',
        value: 'Sales',
        documents: 'Invoices',
        document: 'Invoice / Credit Note',
        party: 'Customer',
        mainKind: 'Sale',
        register: 'Sales Register',
        returnRegister: 'Sales Return Register',
    },
    purchase: {
        title: 'Item-wise Purchase',
        description: 'How much of each item you bought in the selected dates, with the amount spent, VAT and returns.',
        slug: 'item-wise-purchase',
        exportPermission: 'purchase_reports.export',
        verb: 'Purchased',
        value: 'Purchases',
        documents: 'Bills',
        document: 'Bill / Debit Note',
        party: 'Supplier',
        mainKind: 'Purchase',
        register: 'Purchase Register',
        returnRegister: 'Purchase Return Register',
    },
};

const words = computed(() => WORDING[props.kind]);
const { can } = usePermissions();

const fromInput = ref(props.from);
const toInput = ref(props.to);
const storeIdInput = ref(props.storeId);
const itemIdInput = ref(props.itemId);
const categoryInput = ref(props.categoryId);
const subcategoryInput = ref(props.subcategoryId);
const brandInput = ref(props.brandId);
const searchInput = ref(props.search);

// The inputs follow whatever the server actually applied.
watch(
    () => [props.from, props.to, props.storeId, props.itemId, props.categoryId, props.subcategoryId, props.brandId, props.search],
    () => {
        fromInput.value = props.from;
        toInput.value = props.to;
        storeIdInput.value = props.storeId;
        itemIdInput.value = props.itemId;
        categoryInput.value = props.categoryId;
        subcategoryInput.value = props.subcategoryId;
        brandInput.value = props.brandId;
        searchInput.value = props.search;
    },
);

const branchFilter = useBranchReportFilter(() => props.branchReport);
const { label: branchWord } = useBranchLabel();

const withAll = (label, list) => [{ value: null, label }, ...list.map((entry) => ({ value: entry.id, label: entry.name }))];
const storeOptions = computed(() => withAll('All stores', props.stores));
const itemOptions = computed(() => withAll('All items (no drill-down)', props.itemsList));
const categoryOptions = computed(() => withAll('All categories', props.categories));
const brandOptions = computed(() => withAll('All brands', props.brands));
const subcategoryOptions = computed(() =>
    withAll(
        'All subcategories',
        props.subcategories.filter((sub) => categoryInput.value === null || sub.item_category_id === categoryInput.value),
    ),
);

// A subcategory from another category can no longer be chosen.
watch(categoryInput, () => {
    if (!subcategoryOptions.value.some((option) => option.value === subcategoryInput.value)) {
        subcategoryInput.value = null;
    }
});

// The drilled item must stay inside the item filters: once Category,
// Subcategory, Brand or the name search differ from what is applied, the
// picked item may no longer be listed, so it is cleared.
watch([categoryInput, subcategoryInput, brandInput, searchInput], () => {
    const itemFiltersChanged =
        categoryInput.value !== props.categoryId ||
        subcategoryInput.value !== props.subcategoryId ||
        brandInput.value !== props.brandId ||
        searchInput.value.trim() !== props.search;

    if (itemFiltersChanged) {
        itemIdInput.value = null;
    }
});

const isLoading = ref(false);

// From the applied props, so it shows exactly when the report is narrowed.
const hasActiveFilter = computed(
    () =>
        props.storeId !== null ||
        props.itemId !== null ||
        props.categoryId !== null ||
        props.subcategoryId !== null ||
        props.brandId !== null ||
        props.search !== '',
);

function inputQuery() {
    return {
        from: fromInput.value,
        to: toInput.value,
        store_id: storeIdInput.value ?? undefined,
        item_id: itemIdInput.value ?? undefined,
        category_id: categoryInput.value ?? undefined,
        subcategory_id: subcategoryInput.value ?? undefined,
        brand_id: brandInput.value ?? undefined,
        search: searchInput.value.trim() || undefined,
        ...branchFilter.toQuery(),
    };
}

// The filters the page is showing now (the props), never unapplied inputs.
function appliedParams() {
    const params = new URLSearchParams({ from: props.from, to: props.to });
    const applied = {
        store_id: props.storeId,
        item_id: props.itemId,
        category_id: props.categoryId,
        subcategory_id: props.subcategoryId,
        brand_id: props.brandId,
        search: props.search,
    };

    Object.entries(applied).forEach(([key, value]) => {
        if (value !== null && value !== '') {
            params.set(key, String(value));
        }
    });

    return branchFilter.appendTo(params);
}

function visit(query, url = window.location.pathname) {
    router.get(url, query, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => (isLoading.value = true),
        onFinish: () => (isLoading.value = false),
    });
}

function applyFilter() {
    visit(inputQuery());
}

function resetFilters() {
    storeIdInput.value = null;
    itemIdInput.value = null;
    categoryInput.value = null;
    subcategoryInput.value = null;
    brandInput.value = null;
    searchInput.value = '';
    applyFilter();
}

// A row click narrows the report the user is looking at, so it starts from
// the applied props and only swaps the item (as SalesByCategoryItems does).
function drillInto(itemId) {
    const params = appliedParams();
    params.set('item_id', String(itemId));

    visit({}, `${window.location.pathname}?${params.toString()}`);
}

// Built from the applied props (never the unapplied inputs) so the file matches what is on screen.
const exportUrl = computed(() => `/reports/${words.value.slug}/export?${appliedParams().toString()}`);

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

// Never one number across items: adding Kilograms to Pieces produces a
// figure nobody can use, so quantity totals are listed per base unit.
function quantityLabel(quantities) {
    if (!quantities || quantities.length === 0) {
        return '-';
    }

    return quantities.map((entry) => `${formatQuantity(entry.quantity)} ${entry.unit}`.trim()).join(', ');
}

const money = (key, header) => ({ id: key, header, numeric: true, cell: ({ row }) => formatMoney(row.original[key]) });
const quantity = (key, header) => ({ id: key, header, numeric: true, cell: ({ row }) => formatQuantity(row.original[key]) });

const columns = computed(() => [
    {
        id: 'name',
        header: 'Item',
        numeric: false,
        cell: ({ row }) =>
            h(
                'button',
                {
                    type: 'button',
                    class: 'text-left font-medium text-primary hover:underline',
                    title: 'Show this item\'s lines',
                    onClick: () => drillInto(row.original.item_id),
                },
                row.original.name ?? '-',
            ),
    },
    { accessorKey: 'unit', header: 'Unit' },
    quantity('total_quantity', `Qty ${words.value.verb}`),
    money('total_value', `${words.value.value} (excl. VAT)`),
    money('vat_amount', 'VAT'),
    money('gross_amount', `${words.value.value} incl. VAT`),
    quantity('returned_quantity', 'Qty Returned'),
    money('returned_value', 'Returns (excl. VAT)'),
    quantity('net_quantity', 'Net Qty'),
    money('net_value', 'Net (excl. VAT)'),
    { id: 'transaction_count', header: words.value.documents, numeric: true, cell: ({ row }) => row.original.transaction_count },
]);

function documentCell(line) {
    const label = line.document_number ?? '-';

    // Only a sale has a show route; purchases and returns are not linked.
    if (props.kind === 'sales' && line.kind === 'sale' && can('sales.view')) {
        return h(Link, { href: `/sales/${line.document_id}`, class: 'text-primary hover:underline' }, () => label);
    }

    return label;
}

const lineColumns = computed(() => {
    const base = [
        { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
        { accessorKey: 'date', header: 'Date (AD)' },
        {
            id: 'kind',
            header: 'Type',
            numeric: false,
            cell: ({ row }) =>
                row.original.kind === 'return' ? h('span', { class: 'text-danger' }, 'Return') : words.value.mainKind,
        },
        { id: 'document_number', header: words.value.document, numeric: false, cell: ({ row }) => documentCell(row.original) },
        { id: 'party_name', header: words.value.party, numeric: false, cell: ({ row }) => row.original.party_name ?? '-' },
        { id: 'rate', header: 'Rate', numeric: true, cell: ({ row }) => formatRate(row.original.rate) },
        quantity('quantity', 'Qty (as entered)'),
        quantity('bonus_quantity', 'Bonus Qty'),
        quantity('base_quantity', 'Qty (base)'),
        money('net_amount', 'Value (excl. VAT)'),
        money('vat_amount', 'VAT'),
        money('gross_amount', 'Value incl. VAT'),
        { id: 'vatable', header: 'Vatable', numeric: false, cell: ({ row }) => (row.original.vatable ? 'Yes' : 'No') },
    ];

    // The branch column exists only for a multi-branch company.
    if (!props.branchReport) {
        return base;
    }

    const at = base.findIndex((column) => column.id === 'party_name') + 1;

    return [
        ...base.slice(0, at),
        { id: 'branch', header: branchWord(), numeric: false, cell: ({ row }) => row.original.branch ?? '-' },
        ...base.slice(at),
    ];
});

const totalRows = computed(() => [
    { label: words.value.verb, quantities: props.totals.quantities, value: props.totals.total_value, vat: props.totals.vat_amount, gross: props.totals.gross_amount },
    { label: 'Returned', quantities: props.totals.returned_quantities, value: props.totals.returned_value, vat: props.totals.returned_vat, gross: props.totals.returned_gross },
    { label: 'Net', quantities: props.totals.net_quantities, value: props.totals.net_value, vat: null, gross: props.totals.net_gross },
]);
</script>

<template>
    <div>
        <PageHeader :title="words.title" :description="words.description">
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                :id-prefix="words.slug"
                hide-label
                @change="applyFilter()"
            />
            <Button v-if="can(words.exportPermission)" as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export to Excel
            </Button>
        </PageHeader>

        <p class="mb-1 text-[12.5px] text-text-muted">Showing report for {{ rangeLabel }}</p>
        <p class="mb-4 text-[12.5px] text-text-muted">
            Quantities are in each item's base unit, so a line entered in Boxes of 12 counts as 12, and include bonus
            (free) units, as stock does. Values are after
            line and bill discounts; "incl. VAT" adds VAT and the round off share, so with no item filter the
            {{ words.value.toLowerCase() }} totals equal the {{ words.register }}. Cancelled documents are left out.
            Returns posted in these dates are shown in their own columns (they equal the {{ words.returnRegister }})
            and Net is {{ words.verb.toLowerCase() }} minus returned.
        </p>

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
                    <Label class="mb-1">Store</Label>
                    <Select v-model="storeIdInput" :options="storeOptions" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Category</Label>
                    <Select v-model="categoryInput" :options="categoryOptions" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Subcategory</Label>
                    <Select v-model="subcategoryInput" :options="subcategoryOptions" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Brand</Label>
                    <Select v-model="brandInput" :options="brandOptions" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Item name</Label>
                    <Input v-model="searchInput" placeholder="Search items..." @keydown.enter.prevent="applyFilter()" />
                </div>
                <div class="w-64">
                    <Label class="mb-1">Item (drill-down)</Label>
                    <Select v-model="itemIdInput" :options="itemOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter()">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">
                    Clear filters
                </Button>
            </div>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="items" :page-size="25" empty-message="No records for this period or filter. Try widening the date range." />

            <table class="mt-3 w-full border-t-[1.5px] border-border text-[12.5px]">
                <thead>
                    <tr class="text-text-muted">
                        <th class="py-1.5 text-left font-semibold">Total</th>
                        <th class="py-1.5 text-right font-semibold">Quantity</th>
                        <th class="py-1.5 text-right font-semibold">Value (excl. VAT)</th>
                        <th class="py-1.5 text-right font-semibold">VAT</th>
                        <th class="py-1.5 text-right font-semibold">Incl. VAT</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="total in totalRows" :key="total.label" class="font-semibold text-text-strong">
                        <td class="py-1">{{ total.label }}</td>
                        <td class="py-1 text-right">{{ quantityLabel(total.quantities) }}</td>
                        <td class="py-1 text-right">{{ formatMoney(total.value) }}</td>
                        <td class="py-1 text-right">{{ total.vat === null ? '-' : formatMoney(total.vat) }}</td>
                        <td class="py-1 text-right">{{ formatMoney(total.gross) }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <Card v-if="itemId" variant="panel" class="mt-4">
            <div class="mb-3">
                <h3 class="text-base font-bold text-text-strong">Transaction detail: {{ selectedItemName }}</h3>
                <p class="mt-0.5 text-[12.5px] text-text-muted">
                    Every line of this item in the selected dates, with returns shown as negative. Built from the same
                    rows as the summary, so the total below equals the item's Net figures above.
                </p>
            </div>
            <DataTable :columns="lineColumns" :data="lines" :page-size="25" empty-message="No lines for this item in this period or filter." />
            <div class="mt-3 flex flex-wrap items-center justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div class="font-bold text-text-strong">Total</div>
                <div><span class="text-text-muted">Qty (base):</span> <span class="font-semibold">{{ formatQuantity(lineTotals.base_quantity) }}</span></div>
                <div><span class="text-text-muted">Value (excl. VAT):</span> <span class="font-semibold">{{ formatMoney(lineTotals.net_amount) }}</span></div>
                <div><span class="text-text-muted">VAT:</span> <span class="font-semibold">{{ formatMoney(lineTotals.vat_amount) }}</span></div>
                <div><span class="text-text-muted">Incl. VAT:</span> <span class="font-semibold">{{ formatMoney(lineTotals.gross_amount) }}</span></div>
            </div>
        </Card>
    </div>
</template>
