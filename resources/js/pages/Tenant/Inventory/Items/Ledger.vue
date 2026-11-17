<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
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
import { formatQuantity, formatRate } from '@/lib/money';
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
    openingBalance: {
        type: String,
        default: '0.0000',
    },
    closingBalance: {
        type: String,
        default: '0.0000',
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
const from = ref(props.from);
const to = ref(props.to);
const storeId = ref(props.storeId);
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
        storeId.value = props.storeId;
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
const hasExplicitFilters = ['from', 'to', 'store_id'].some((key) => new URLSearchParams(window.location.search).has(key));

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: storeLabel(store) })),
]);

function filterParams() {
    return {
        item_id: selectedItemId.value ?? undefined,
        from: from.value ?? undefined,
        to: to.value ?? undefined,
        store_id: storeId.value ?? undefined,
    };
}

function applyFilters() {
    router.get('/reports/item-ledger', { ...filterParams(), ...branchFilter.toQuery() }, { preserveState: true, preserveScroll: true });
}

function clearFilters() {
    from.value = null;
    to.value = null;
    storeId.value = null;
    applyFilters();
}

function fileUrl(suffix) {
    if (!props.item?.id) return '#';
    const params = new URLSearchParams(
        Object.entries(filterParams()).filter(([key, value]) => value !== undefined && key !== 'item_id'),
    );
    branchFilter.appendTo(params);
    return `/items/${props.item.id}/ledger/${suffix}?${params.toString()}`;
}

const unitSuffix = computed(() => (props.item?.unit ? ` ${props.item.unit}` : ''));

// Quantities arrive as exact 4-decimal strings (the running balance is
// accumulated with App\Support\Money\Quantity on the server); the page only
// formats them, never adds them up.
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
    { accessorKey: 'reference', header: 'Reference', numeric: false },
    {
        accessorKey: 'quantity',
        header: 'Quantity',
        cell: ({ row }) => formatQuantity(row.original.quantity),
    },
    {
        accessorKey: 'unitCostRate',
        header: 'Unit cost',
        cell: ({ row }) => (row.original.unitCostRate === null ? '-' : formatRate(row.original.unitCostRate)),
    },
    {
        accessorKey: 'balance',
        header: 'Running balance',
        cell: ({ row }) => formatQuantity(row.original.balance),
    },
];
</script>

<template>
    <div>
        <PageHeader
            :title="item ? `Item Ledger: ${item.name}` : 'Item Ledger'"
            description="Every stock movement for this item, with a running balance. Narrow to a date range or a single store."
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
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <Label class="mb-1">To date</Label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Store</Label>
                    <Select v-model="storeId" :options="storeOptions" />
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
                <div class="flex flex-wrap justify-end gap-6 text-[13px]">
                    <div><span class="text-text-muted">Opening balance:</span> <span class="font-semibold tabular-nums">{{ formatQuantity(openingBalance) }}{{ unitSuffix }}</span></div>
                    <div><span class="text-text-muted">Closing balance:</span> <span class="font-semibold tabular-nums">{{ formatQuantity(closingBalance) }}{{ unitSuffix }}</span></div>
                </div>
            </Card>

            <Card variant="panel">
                <DataTable :columns="columns" :data="entries" :page-size="25" empty-message="No stock movements in this period. Try a wider date range or another store." />
            </Card>
        </template>
    </div>
</template>
