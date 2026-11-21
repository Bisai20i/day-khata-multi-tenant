<script setup>
import { computed, h, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Button from '@/components/ui/Button.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney, formatQuantity, formatRate } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { storeLabel } from '@/lib/storeLabel.js';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    item: {
        type: [Object, null],
        default: null,
    },
    stores: {
        type: Array,
        default: () => [],
    },
    storeId: {
        type: [Number, null],
        default: null,
    },
    from: {
        type: [String, null],
        default: null,
    },
    to: {
        type: [String, null],
        default: null,
    },
    // Set when the server filled in or replaced a date bound; explains the applied range.
    periodNote: {
        type: [String, null],
        default: null,
    },
    // Exact 4-decimal strings in the item's base unit.
    totals: {
        type: Object,
        default: () => ({ in: '0.0000', out: '0.0000', net: '0.0000' }),
    },
    openingBalance: {
        type: String,
        default: '0.0000',
    },
    closingBalance: {
        type: String,
        default: '0.0000',
    },
    // The opening / closing balance valued (Money strings); null when no value can be given.
    openingValue: {
        type: [String, null],
        default: null,
    },
    closingValue: {
        type: [String, null],
        default: null,
    },
    entries: {
        type: Array,
        default: () => [],
    },
    // Branch filter; null when the company has no branches.
    branchReport: {
        type: [Object, null],
        default: null,
    },
});

useLayoutChrome(() => (props.item ? `Item Ledger - ${props.item.name}` : 'Item Ledger'));

// Stock is not reset at year end, so unlike the account ledger there is no
// fiscal year picker: the window is any date range, and the backend works
// out the quantity on hand the day before "From" as the opening balance.
const fromDate = ref(props.from);
const toDate = ref(props.to);
const selectedStoreId = ref(props.storeId);
const selectedItemId = ref(props.item?.id ?? null);

const itemOptions = computed(() =>
    props.items.map((i) => ({
        value: i.id,
        label: i.barcode ? `${i.name} (${i.barcode})` : i.name,
    })),
);

// The server drops a store outside the chosen branches; follow what it applied.
watch(
    () => [props.storeId, props.stores],
    () => {
        selectedStoreId.value = props.storeId;
    },
);

// preserveState keeps the typed dates; show the range the server actually
// applied. `entries` is a fresh array on every response, so this also fires
// when the server falls back to the same range the page already had.
watch(
    () => [props.from, props.to, props.entries],
    () => {
        fromDate.value = props.from;
        toDate.value = props.to;
    },
);

watch(selectedItemId, (newItemId) => {
    if (newItemId && newItemId !== props.item?.id) {
        applyFilters();
    }
});

const branchFilter = useBranchReportFilter(() => props.branchReport);

// The backend always echoes a from/to (the fiscal year by default), so the
// props cannot tell a chosen window from the default one; the query string can.
// A ref, not a const: preserveState reuses this component across visits, so it
// must follow the filters the user applies after the page first loads.
const hasExplicitFilters = ref(['from', 'to', 'store_id'].some((key) => new URLSearchParams(window.location.search).has(key)));

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: storeLabel(store) })),
]);

function filterParams() {
    return {
        item_id: selectedItemId.value ?? undefined,
        from: fromDate.value ?? undefined,
        to: toDate.value ?? undefined,
        store_id: selectedStoreId.value ?? undefined,
    };
}

function applyFilters() {
    hasExplicitFilters.value = [fromDate.value, toDate.value, selectedStoreId.value].some((value) => value !== null && value !== undefined);
    router.get('/reports/item-ledger', { ...filterParams(), ...branchFilter.toQuery() }, { preserveState: true, preserveScroll: true });
}

function clearFilters() {
    fromDate.value = null;
    toDate.value = null;
    selectedStoreId.value = null;
    applyFilters();
}

// Built from the applied props, never the typed inputs, so a download always
// matches the ledger on screen.
function fileUrl(suffix) {
    if (!props.item?.id) return '#';
    const params = new URLSearchParams({ from: props.from, to: props.to });
    if (props.storeId) {
        params.set('store_id', props.storeId);
    }
    branchFilter.appendTo(params);
    return `/items/${props.item.id}/ledger/${suffix}?${params.toString()}`;
}

const unitSuffix = computed(() => (props.item?.unit ? ` ${props.item.unit}` : ''));

function withUnit(value) {
    return `${formatQuantity(value)}${unitSuffix.value}`;
}

// Zero on the "other" side of a movement reads as a dash, like a ledger's Dr/Cr.
function sideQuantity(value) {
    return formatQuantity(value) === '0' ? '-' : withUnit(value);
}

function referenceCell(entry) {
    if (!entry.referenceUrl) return entry.reference;
    return h(Link, { href: entry.referenceUrl, class: 'text-primary hover:underline' }, () => entry.reference);
}

// Quantities and totals arrive as exact 4-decimal strings (summed with
// App\Support\Money\Quantity on the server); the page only formats them,
// never adds them up.
const columns = [
    {
        id: 'dateBs',
        header: 'Date (BS)',
        numeric: false,
        cell: ({ row }) => formatBsDate(row.original.date) || '-',
    },
    { accessorKey: 'date', header: 'Date (AD)' },
    { accessorKey: 'type', header: 'Type', numeric: false },
    {
        accessorKey: 'storeName',
        header: 'Store',
        numeric: false,
        cell: ({ row }) => row.original.storeName ?? '-',
    },
    {
        accessorKey: 'reference',
        header: 'Reference',
        numeric: false,
        cell: ({ row }) => referenceCell(row.original),
    },
    {
        accessorKey: 'qtyIn',
        header: 'In',
        cell: ({ row }) => sideQuantity(row.original.qtyIn),
    },
    {
        accessorKey: 'qtyOut',
        header: 'Out',
        cell: ({ row }) => sideQuantity(row.original.qtyOut),
    },
    {
        accessorKey: 'quantity',
        header: 'Quantity',
        cell: ({ row }) => withUnit(row.original.quantity),
    },
    {
        accessorKey: 'unitCostRate',
        header: 'Unit cost',
        cell: ({ row }) => (row.original.unitCostRate === null ? '-' : formatRate(row.original.unitCostRate)),
    },
    {
        accessorKey: 'balance',
        header: 'Running balance',
        cell: ({ row }) => withUnit(row.original.balance),
    },
    {
        accessorKey: 'value',
        header: 'Value',
        cell: ({ row }) => (row.original.value == null ? '-' : formatMoney(row.original.value)),
    },
];

function shownValue(value) {
    return value == null ? '-' : formatMoney(value);
}
</script>

<template>
    <div>
        <PageHeader
            :title="item ? `Item Ledger: ${item.name}` : 'Item Ledger'"
            description="Every stock movement for this item, with a running balance and value. Narrow to a date range or a single store."
            back-href="/reports"
            back-label="Back to reports"
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="item-ledger-branch"
                hide-label
                @change="applyFilters"
            />
            <a v-if="item && can('stock_reports.print')" :href="fileUrl('print')" target="_blank" rel="noopener"><Button variant="secondary" tone="purple">Print ledger</Button></a>
            <a v-if="item && can('stock_reports.export')" :href="fileUrl('export')"><Button variant="secondary" tone="purple">Export ledger</Button></a>
        </PageHeader>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div v-if="items.length > 0" class="w-64">
                    <Label class="mb-1">Item</Label>
                    <Combobox v-model="selectedItemId" :options="itemOptions" placeholder="Select item" />
                </div>
                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <Label>From date</Label>
                        <InfoTip text="With no dates the current fiscal year is shown. The opening balance is the stock on hand the day before your From date." />
                    </div>
                    <NepaliDateInput v-model="fromDate" />
                </div>
                <div>
                    <Label class="mb-1">To date</Label>
                    <NepaliDateInput v-model="toDate" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Store</Label>
                    <Select v-model="selectedStoreId" :options="storeOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilters">Apply filters</Button>
                <Button v-if="hasExplicitFilters" variant="secondary" tone="purple" @click="clearFilters">Clear filters</Button>
            </div>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
        </Card>

        <Card v-if="!item" variant="panel" class="py-12 text-center text-text-muted">
            <p class="text-sm">No item selected. Please select an item above to view its stock ledger.</p>
        </Card>

        <template v-else>
            <Card variant="panel" class="mb-4">
                <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 text-[13px]">
                    <div v-if="from && to">
                        <span class="text-text-muted">Period:</span>
                        <span class="font-semibold">{{ formatBsDate(from) || from }} to {{ formatBsDate(to) || to }}</span>
                        <span class="text-text-muted"> ({{ from }} to {{ to }})</span>
                    </div>
                    <div class="flex flex-wrap justify-end gap-6">
                        <div><span class="text-text-muted">Opening balance:</span> <span class="font-semibold tabular-nums">{{ withUnit(openingBalance) }}</span></div>
                        <div><span class="text-text-muted">Total in:</span> <span class="font-semibold tabular-nums">{{ withUnit(totals.in) }}</span></div>
                        <div><span class="text-text-muted">Total out:</span> <span class="font-semibold tabular-nums">{{ withUnit(totals.out) }}</span></div>
                        <div><span class="text-text-muted">Net:</span> <span class="font-semibold tabular-nums">{{ withUnit(totals.net) }}</span></div>
                        <div><span class="text-text-muted">Closing balance:</span> <span class="font-semibold tabular-nums">{{ withUnit(closingBalance) }}</span></div>
                        <div><span class="text-text-muted">Opening value:</span> <span class="font-semibold tabular-nums">{{ shownValue(openingValue) }}</span></div>
                        <div><span class="text-text-muted">Closing value:</span> <span class="font-semibold tabular-nums">{{ shownValue(closingValue) }}</span></div>
                    </div>
                </div>
                <p v-if="periodNote" class="mt-2 text-xs text-text-muted">{{ periodNote }}</p>
                <p class="mt-2 text-xs text-text-muted">
                    Value is the running balance at the stock's average cost on that date, the same basis as the stock valuation report. For manufactured items it is the end-of-day value, shown on the last movement of each day.
                </p>
            </Card>

            <Card variant="panel">
                <DataTable :columns="columns" :data="entries" :page-size="25" empty-message="No stock movements in this period. Try a wider date range or another store." />
            </Card>
        </template>
    </div>
</template>
