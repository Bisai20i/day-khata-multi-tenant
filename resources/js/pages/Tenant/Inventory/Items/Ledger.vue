<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { formatQuantity, formatRate } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    item: {
        type: Object,
        required: true,
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
});

useLayoutChrome(() => `Item Ledger - ${props.item.name}`);

// Stock is not reset at year end, so unlike the account ledger there is no
// fiscal year picker: the window is any date range, and the backend works
// out the quantity on hand the day before "From" as the opening balance.
const from = ref(props.from);
const to = ref(props.to);
const storeId = ref(props.storeId);

// The backend always echoes a from/to (the fiscal year by default), so the
// props cannot tell a chosen window from the default one; the query string can.
const hasExplicitFilters = ['from', 'to', 'store_id'].some((key) => new URLSearchParams(window.location.search).has(key));

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

function filterParams() {
    return {
        from: from.value ?? undefined,
        to: to.value ?? undefined,
        store_id: storeId.value ?? undefined,
    };
}

function applyFilters() {
    router.get(window.location.pathname, filterParams(), { preserveState: true, preserveScroll: true });
}

function clearFilters() {
    from.value = null;
    to.value = null;
    storeId.value = null;
    applyFilters();
}

function fileUrl(suffix) {
    const params = new URLSearchParams(
        Object.entries(filterParams()).filter(([, value]) => value !== undefined),
    );
    return `${window.location.pathname}/${suffix}?${params.toString()}`;
}

const unitSuffix = computed(() => (props.item.unit ? ` ${props.item.unit}` : ''));

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
            :title="`Item Ledger: ${item.name}`"
            description="Every stock movement for this item, with a running balance. Narrow to a date range or a single store."
            back-href="/items"
            back-label="Back to items"
        >
            <a :href="fileUrl('print')" target="_blank" rel="noopener"><Button variant="secondary" tone="purple">Print ledger</Button></a>
            <a :href="fileUrl('export')"><Button variant="secondary" tone="purple">Export ledger</Button></a>
        </PageHeader>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From date</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To date</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilters">Apply filters</Button>
                <Button v-if="hasExplicitFilters" variant="secondary" tone="purple" @click="clearFilters">Clear filters</Button>
            </div>
            <p class="mt-2 text-xs text-text-muted">With no dates the current fiscal year is shown. The opening balance is the stock on hand the day before your From date.</p>
        </Card>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap justify-end gap-6 text-[13px]">
                <div><span class="text-text-muted">Opening balance:</span> <span class="font-semibold tabular-nums">{{ formatQuantity(openingBalance) }}{{ unitSuffix }}</span></div>
                <div><span class="text-text-muted">Closing balance:</span> <span class="font-semibold tabular-nums">{{ formatQuantity(closingBalance) }}{{ unitSuffix }}</span></div>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="entries" :page-size="25" empty-message="No stock movements in this period. Try a wider date range or another store." />
        </Card>
    </div>
</template>
