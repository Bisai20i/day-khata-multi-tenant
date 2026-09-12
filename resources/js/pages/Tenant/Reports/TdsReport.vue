<script setup>
import { computed, h, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { navGroups } from '@/lib/nav-items.js';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

const props = defineProps({
    sales: { type: Array, default: () => [] },
    purchases: { type: Array, default: () => [] },
    salesTotal: { type: String, default: '0.00' },
    purchasesTotal: { type: String, default: '0.00' },
    grandTotal: { type: String, default: '0.00' },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const from = ref(props.from);
const to = ref(props.to);
const storeId = ref(props.storeId);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: from.value, to: to.value, store_id: storeId.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

const entryLabels = {
    invoice: 'Invoice',
    credit_note: 'Credit note',
    debit_note: 'Debit note',
    cancelled: 'Cancelled',
};

function entryCell({ row }) {
    const label = entryLabels[row.original.entry] ?? row.original.entry;

    return row.original.entry === 'invoice'
        ? label
        : h('span', { class: 'text-danger font-semibold' }, label);
}

function columnsFor(partyHeader, documentHeader) {
    return [
        { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
        { accessorKey: 'date', header: 'Date (AD)' },
        {
            id: 'document_number',
            header: documentHeader,
            numeric: false,
            cell: ({ row }) => row.original.document_number ?? '—',
        },
        { id: 'party', header: partyHeader, numeric: false, cell: ({ row }) => row.original.party ?? '—' },
        { id: 'entry', header: 'Entry', numeric: false, cell: entryCell },
        { id: 'base_total', header: 'Gross Total', numeric: true, cell: ({ row }) => formatMoney(row.original.base_total) },
        { id: 'tds_amount', header: 'TDS', numeric: true, cell: ({ row }) => formatMoney(row.original.tds_amount) },
        { id: 'tds_account', header: 'TDS Account', numeric: false, cell: ({ row }) => row.original.tds_account ?? '—' },
    ];
}

const salesColumns = columnsFor('Customer', 'Invoice #');
const purchaseColumns = columnsFor('Supplier', 'Bill #');
</script>

<template>
    <AppLayout title="TDS Report" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">TDS Report</h2>
        </div>

        <p class="mb-1 text-[12.5px] text-text-muted">{{ rangeLabel }}</p>

        <p class="mb-4 text-[12.5px] text-text-muted">
            One row per event, in the period the event happened. A credit or debit note reverses TDS in its
            own period using the exact amount its voucher posted, and a cancellation reverses in the period
            it was cancelled, so an already-filed month never changes.
        </p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel" title="TDS on Sales (claimable credit)" class="mb-4">
            <DataTable :columns="salesColumns" :data="sales" :page-size="25" empty-message="No TDS withheld on sales in this range" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Total TDS on Sales:</span> <span class="font-semibold">{{ formatMoney(salesTotal) }}</span></div>
            </div>
        </Card>

        <Card variant="panel" title="TDS on Purchases (liability to remit)" class="mb-4">
            <DataTable :columns="purchaseColumns" :data="purchases" :page-size="25" empty-message="No TDS withheld on purchases in this range" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Total TDS on Purchases:</span> <span class="font-semibold">{{ formatMoney(purchasesTotal) }}</span></div>
            </div>
        </Card>

        <Card variant="panel">
            <div class="flex items-center px-1 py-1 text-[14px] font-bold text-text-strong">
                <div class="flex-1">Combined Grand Total (Sales TDS + Purchases TDS)</div>
                <div class="w-32 text-right">{{ formatMoney(grandTotal) }}</div>
            </div>
        </Card>
    </AppLayout>
</template>
