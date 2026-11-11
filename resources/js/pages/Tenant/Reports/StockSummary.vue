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
import { formatMoney, formatQuantity, formatRate, parseQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { useBranchLabel } from '@/composables/useBranchLabel';
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
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
    // Goods between branches as at `to` (BR19): { buckets: ['within'|'outgoing'|'incoming'], totals: { bucket: value } };
    // null unless something is in transit, so the page is unchanged otherwise.
    inTransit: { type: [Object, null], default: null },
});

const { label, plural } = useBranchLabel();

useLayoutChrome('Stock Summary');

const fromInput = ref(props.from);
const toInput = ref(props.to);
const storeId = ref(props.storeId);

// The server drops a store outside the chosen branches; follow what it applied.
watch(
    () => [props.storeId, props.stores],
    () => {
        storeId.value = props.storeId;
    },
);

const branchFilter = useBranchReportFilter(() => props.branchReport);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const isLoading = ref(false);

const hasActiveFilter = computed(() => storeId.value !== null);

function resetFilters() {
    storeId.value = null;
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: fromInput.value, to: toInput.value, store_id: storeId.value ?? undefined, ...branchFilter.toQuery() },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

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

const valueColumns = [
    { accessorKey: 'avgCost', header: 'Average cost per unit', numeric: true, cell: ({ row }) => formatRate(row.original.avgCost) },
    { accessorKey: 'valuation', header: 'Valuation', numeric: true, cell: ({ row }) => formatMoney(row.original.valuation) },
    itemLedgerColumn((row) => row.itemId),
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
        <PageHeader title="Stock Summary" description="Opening stock, stock in, stock out and closing stock for each item in the selected dates." />

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
                <Select v-model="storeId" :options="storeOptions" />
            </div>
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="stock-summary-branch"
                @change="applyFilter"
            />
            <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
            <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
        </div>

        <div v-if="branchReport" class="-mt-2 mb-4">
            <BranchReportNotes :report="branchReport" />
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
