<script setup>
import { computed, h, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { FileSpreadsheet } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import Input from '@/components/ui/Input.vue';
import { formatMoney, formatQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { useBranchLabel } from '@/composables/useBranchLabel';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sales: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ count: 0, total: '0.00' }) },
    stores: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
    customerId: { type: [Number, null], default: null },
    search: { type: String, default: '' },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Sales With Note');

const { can } = usePermissions();

// Local filter state follows the applied props, so a back/forward visit or a
// reset never leaves the inputs showing something the table is not.
const fromInput = ref(props.from);
const toInput = ref(props.to);
const storeInput = ref(props.storeId);
const customerInput = ref(props.customerId);
const searchInput = ref(props.search);

watch(() => props.from, (value) => (fromInput.value = value));
watch(() => props.to, (value) => (toInput.value = value));
watch(() => props.storeId, (value) => (storeInput.value = value));
watch(() => props.customerId, (value) => (customerInput.value = value));
watch(() => props.search, (value) => (searchInput.value = value));

const branchFilter = useBranchReportFilter(() => props.branchReport);
const { label: branchWord } = useBranchLabel();

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const customerOptions = computed(() => [
    { value: null, label: 'All customers' },
    ...props.customers.map((customer) => ({ value: customer.id, label: customer.name })),
]);

const isLoading = ref(false);

const hasActiveFilter = computed(
    () =>
        props.storeId !== null ||
        props.customerId !== null ||
        props.search !== '' ||
        storeInput.value !== null ||
        customerInput.value !== null ||
        searchInput.value.trim() !== '',
);

function resetFilters() {
    storeInput.value = null;
    customerInput.value = null;
    searchInput.value = '';
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            from: fromInput.value,
            to: toInput.value,
            store_id: storeInput.value ?? undefined,
            customer_id: customerInput.value ?? undefined,
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

// Built from the applied props so the file always matches what is on screen.
const exportUrl = computed(() => {
    const params = new URLSearchParams({ from: props.from, to: props.to });

    if (props.storeId) {
        params.set('store_id', props.storeId);
    }

    if (props.customerId) {
        params.set('customer_id', props.customerId);
    }

    if (props.search !== '') {
        params.set('search', props.search);
    }

    branchFilter.appendTo(params);

    return `/reports/sales-with-note/export?${params.toString()}`;
});

function formatItems(items) {
    if (!items || items.length === 0) {
        return '-';
    }

    return items
        .map((item) => `${item.name ?? '-'} (${formatQuantity(item.quantity)}${item.unit ? ` ${item.unit}` : ''})`)
        .join(', ');
}

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

const baseColumns = [
    { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { accessorKey: 'date', header: 'Date (AD)' },
    // The stored invoice number (C7), not the internal row id.
    {
        id: 'invoice_number',
        header: 'Bill No',
        numeric: false,
        // A link only for a user who can open the sale; otherwise plain text, not a 403.
        cell: ({ row }) =>
            can('sales.view')
                ? h(Link, { href: `/sales/${row.original.id}`, class: 'font-semibold text-purple-600 hover:underline' }, () => row.original.invoice_number ?? '-')
                : (row.original.invoice_number ?? '-'),
    },
    { id: 'customer', header: "Buyer's Name", numeric: false, cell: ({ row }) => row.original.customer ?? '-' },
    { id: 'items', header: 'Items', numeric: false, cell: ({ row }) => formatItems(row.original.items) },
    { id: 'note', header: 'Note', numeric: false, cell: ({ row }) => row.original.note ?? '-' },
    { id: 'chalani_number', header: 'Chalani No', numeric: false, cell: ({ row }) => row.original.chalani_number ?? '-' },
    { id: 'total', header: 'Total amount', numeric: true, cell: ({ row }) => formatMoney(row.original.total) },
];

// The branch column exists only for a multi-branch company.
const columns = computed(() => {
    if (!props.branchReport) {
        return baseColumns;
    }

    const branchColumn = {
        id: 'branch',
        header: branchWord(),
        numeric: false,
        cell: ({ row }) => row.original.branch ?? '-',
    };
    const at = baseColumns.findIndex((column) => column.id === 'invoice_number') + 1;

    return [...baseColumns.slice(0, at), branchColumn, ...baseColumns.slice(at)];
});
</script>

<template>
    <div>
        <PageHeader title="Sales With Note" description="Sales in the selected dates that have a note written on them.">
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="sales-with-note"
                hide-label
                @change="applyFilter"
            />
            <Button v-if="can('sales_reports.export')" as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export to Excel
            </Button>
        </PageHeader>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Posted sales in this range that have a note recorded against them. Cancelled sales and sales with no note are excluded.
        </p>

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
                    <Label class="mb-1">Store</Label>
                    <Select v-model="storeInput" :options="storeOptions" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Customer</Label>
                    <Select v-model="customerInput" :options="customerOptions" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Note contains</Label>
                    <Input v-model="searchInput" type="text" maxlength="100" placeholder="Search the note text" @keydown.enter="applyFilter" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="sales" :page-size="25" empty-message="No records for this period or filter. Try widening the date range." />

            <div class="mt-3 flex flex-wrap items-center justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div class="font-bold text-text-strong">Total</div>
                <div><span class="text-text-muted">Sales with note:</span> <span class="font-semibold">{{ totals.count }}</span></div>
                <div><span class="text-text-muted">Total amount:</span> <span class="font-bold">{{ formatMoney(totals.total) }}</span></div>
            </div>
        </Card>
    </div>
</template>
