<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
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
import { formatMoney, formatQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import { itemLedgerColumn } from '@/lib/itemLedger';

defineOptions({ layout: AppLayout });

const props = defineProps({
    lines: { type: Array, default: () => [] },
    itemWise: { type: Array, default: () => [] },
    // The list's totals row, per base unit: { unit, quantity, value, lines }.
    totalQuantities: { type: Array, default: () => [] },
    // Value written off across every line (Money string).
    totalValue: { type: String, default: '0.00' },
    // The view the server applied: 'list' or 'item'.
    view: { type: String, default: 'list' },
    items: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
    itemId: { type: [Number, null], default: null },
    reason: { type: String, default: null },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

const page = usePage();
useLayoutChrome('Damage & Lost Stock');

const { can } = usePermissions();

const fromInput = ref(props.from);
const toInput = ref(props.to);
const storeIdInput = ref(props.storeId);
const itemIdInput = ref(props.itemId);
const reasonInput = ref(props.reason);

// The local inputs follow what the server applied (it drops a store outside
// the chosen branches, and the top-bar switcher / back button change the props).
watch(
    () => [props.from, props.to, props.storeId, props.itemId, props.reason, props.stores],
    () => {
        fromInput.value = props.from;
        toInput.value = props.to;
        storeIdInput.value = props.storeId;
        itemIdInput.value = props.itemId;
        reasonInput.value = props.reason;
    },
);

const branchFilter = useBranchReportFilter(() => props.branchReport);

// Raw list mirrors legacy's damagestock() variant (one row per line);
// item-wise mirrors legacy's itemwisedamagestock()/
// SearchItemBetweenDateitemwiseDamage() variant (summed per item) - both
// come back from the same request, so switching is instant, no round trip.
// The Export button passes whichever one is showing.
const viewMode = ref(props.view);

watch(
    () => props.view,
    () => {
        viewMode.value = props.view;
    },
);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const itemOptions = computed(() => [
    { value: null, label: 'All items' },
    ...props.items.map((item) => ({ value: item.id, label: item.name })),
]);

const reasonOptions = [
    { value: null, label: 'Damage & Lost' },
    { value: 'damage', label: 'Damage only' },
    { value: 'lost', label: 'Lost only' },
];

const isLoading = ref(false);

const hasActiveFilter = computed(() => itemIdInput.value !== null || storeIdInput.value !== null || reasonInput.value !== null);

function resetFilters() {
    itemIdInput.value = null;
    storeIdInput.value = null;
    reasonInput.value = null;
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            from: fromInput.value,
            to: toInput.value,
            store_id: storeIdInput.value ?? undefined,
            item_id: itemIdInput.value ?? undefined,
            reason: reasonInput.value ?? undefined,
            view: viewMode.value,
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

// Built from the currently-applied props so exporting always matches what's on screen.
const exportUrl = computed(() => {
    const params = new URLSearchParams({ from: props.from, to: props.to, view: viewMode.value });
    if (props.storeId) {
        params.set('store_id', props.storeId);
    }
    if (props.itemId) {
        params.set('item_id', props.itemId);
    }
    if (props.reason) {
        params.set('reason', props.reason);
    }
    branchFilter.appendTo(params);

    return `/reports/damage-lost-stock/export?${params.toString()}`;
});

const reasonLabels = { damage: 'Damage', lost: 'Lost' };

const listColumns = [
    { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { accessorKey: 'date', header: 'Date (AD)' },
    { accessorKey: 'itemName', header: 'Item' },
    { accessorKey: 'unit', header: 'Unit' },
    { id: 'storeName', header: 'Store', numeric: false, cell: ({ row }) => row.original.storeName ?? '-' },
    { id: 'reason', header: 'Reason', numeric: false, cell: ({ row }) => reasonLabels[row.original.reason] ?? row.original.reason },
    { id: 'quantity', header: 'Quantity', numeric: true, cell: ({ row }) => formatQuantity(row.original.quantity) },
    { id: 'value', header: 'Value', numeric: true, cell: ({ row }) => formatMoney(row.original.value) },
    { id: 'remarks', header: 'Remarks', numeric: false, cell: ({ row }) => row.original.remarks ?? '-' },
];

// The item ledger opens on the same period, store and branch as this report
// (only the non-empty values), read from the applied props.
function ledgerQuery() {
    const query = { from: props.from, to: props.to, store_id: props.storeId, ...branchFilter.toQuery() };

    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => value !== null && value !== undefined && value !== '' && !(Array.isArray(value) && value.length === 0)),
    );
}

const itemWiseColumns = [
    { accessorKey: 'name', header: 'Item' },
    { accessorKey: 'unit', header: 'Unit' },
    { id: 'total_quantity', header: 'Total Quantity', numeric: true, cell: ({ row }) => formatQuantity(row.original.total_quantity) },
    { id: 'total_value', header: 'Total Value', numeric: true, cell: ({ row }) => formatMoney(row.original.total_value) },
    { accessorKey: 'transaction_count', header: 'Entries', numeric: true },
    itemLedgerColumn((row) => row.item_id, () => ledgerQuery()),
];

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);
</script>

<template>
    <div>
        <PageHeader title="Damage & Lost Stock" description="Stock written off as damaged or lost, with quantities for the selected dates.">
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="damage-lost-branch"
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
                <div class="w-48">
                    <Label class="mb-1">Store</Label>
                    <Select v-model="storeIdInput" :options="storeOptions" />
                </div>
                <div class="w-48">
                    <Label class="mb-1">Reason</Label>
                    <Select v-model="reasonInput" :options="reasonOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
        </Card>

        <div class="mb-3 flex items-center gap-2">
            <Button :variant="viewMode === 'list' ? 'primary' : 'secondary'" tone="purple" @click="viewMode = 'list'">List</Button>
            <Button :variant="viewMode === 'item' ? 'primary' : 'secondary'" tone="purple" @click="viewMode = 'item'">Item-wise</Button>
        </div>

        <Card variant="panel" v-if="viewMode === 'list'">
            <DataTable :columns="listColumns" :data="lines" :page-size="25" empty-message="No records for this period or filter. Try widening the date range." />
            <!-- Totals row per base unit, never one number: 3 Kg plus 2 Pcs is not "5". -->
            <table v-if="totalQuantities.length" class="mt-3 w-full border-t-[1.5px] border-border text-[13px]">
                <tbody class="divide-y divide-border">
                    <tr v-for="total in totalQuantities" :key="total.unit" class="font-bold text-text-strong">
                        <td class="py-1.5">
                            <span class="text-[10px] tracking-[.8px] text-text-muted uppercase">Total written off</span>
                            <span class="ml-2">{{ total.unit || '-' }}</span>
                        </td>
                        <td class="py-1.5 text-right text-text-muted">{{ total.lines }} {{ total.lines === 1 ? 'line' : 'lines' }}</td>
                        <td class="py-1.5 text-right">{{ formatQuantity(total.quantity) }} {{ total.unit }}</td>
                        <td class="py-1.5 text-right">{{ formatMoney(total.value) }}</td>
                    </tr>
                    <tr class="font-bold text-text-strong">
                        <td class="py-1.5" colspan="3">
                            <span class="text-[10px] tracking-[.8px] text-text-muted uppercase">Total value written off</span>
                        </td>
                        <td class="py-1.5 text-right">{{ formatMoney(totalValue) }}</td>
                    </tr>
                </tbody>
            </table>
        </Card>

        <Card variant="panel" v-else>
            <DataTable :columns="itemWiseColumns" :data="itemWise" :page-size="25" empty-message="No damage or lost stock entries in this range" />
            <p v-if="itemWise.length" class="mt-3 text-right text-[13px] font-bold text-text-strong">
                <span class="mr-2 text-[10px] tracking-[.8px] text-text-muted uppercase">Total value written off</span>
                {{ formatMoney(totalValue) }}
            </p>
        </Card>
    </div>
</template>
