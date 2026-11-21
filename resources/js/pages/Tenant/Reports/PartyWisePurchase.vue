<script setup>
import { computed, h, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import { ArrowLeft, FileSpreadsheet } from '@lucide/vue';
import { formatMoney, subtractMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { useBranchLabel } from '@/composables/useBranchLabel';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';

defineOptions({ layout: AppLayout });

const BASE_URL = '/reports/party-wise-purchase';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    // The selected supplier's documents; null on the summary.
    detail: { type: [Object, null], default: null },
    suppliers: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    supplierId: { type: [Number, null], default: null },
    storeId: { type: [Number, null], default: null },
    paymentMode: { type: [String, null], default: null },
    search: { type: String, default: '' },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Supplier-wise Purchase');

const { can } = usePermissions();

// Unapplied filter inputs, named apart from the props they start from.
const fromInput = ref(props.from);
const toInput = ref(props.to);
const supplierInput = ref(props.supplierId);
const storeInput = ref(props.storeId);
const paymentModeInput = ref(props.paymentMode);
const searchInput = ref(props.search);

// Follow whatever the server actually applied (back/forward, drill-down links).
watch(
    () => [props.from, props.to, props.supplierId, props.storeId, props.paymentMode, props.search],
    () => {
        fromInput.value = props.from;
        toInput.value = props.to;
        supplierInput.value = props.supplierId;
        storeInput.value = props.storeId;
        paymentModeInput.value = props.paymentMode;
        searchInput.value = props.search;
    },
);

const branchFilter = useBranchReportFilter(() => props.branchReport);
const { label: branchWord } = useBranchLabel();

const supplierOptions = computed(() => [
    { value: null, label: 'All suppliers' },
    ...props.suppliers.map((supplier) => ({ value: supplier.id, label: supplier.name })),
]);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const paymentModeOptions = [
    { value: null, label: 'All payment modes' },
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'partial', label: 'Partial' },
    { value: 'credit', label: 'Credit' },
];

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

const isLoading = ref(false);

const hasActiveFilter = computed(
    () => props.supplierId !== null || props.storeId !== null || props.paymentMode !== null || props.search !== '',
);

function applyFilter() {
    router.get(
        BASE_URL,
        {
            from: fromInput.value,
            to: toInput.value,
            supplier_id: supplierInput.value ?? undefined,
            store_id: storeInput.value ?? undefined,
            payment_mode: paymentModeInput.value ?? undefined,
            search: searchInput.value || undefined,
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

function resetFilters() {
    supplierInput.value = null;
    storeInput.value = null;
    paymentModeInput.value = null;
    searchInput.value = '';
    applyFilter();
}

// Built from the applied props, never the unsaved inputs, so a link or an
// export always matches what is on screen.
function appliedParams({ supplierId = props.supplierId } = {}) {
    const params = new URLSearchParams({ from: props.from, to: props.to });

    if (supplierId) {
        params.set('supplier_id', supplierId);
    }
    if (props.storeId) {
        params.set('store_id', props.storeId);
    }
    if (props.paymentMode) {
        params.set('payment_mode', props.paymentMode);
    }
    if (props.search) {
        params.set('search', props.search);
    }
    branchFilter.appendTo(params);

    return params;
}

const exportUrl = computed(() => `${BASE_URL}/export?${appliedParams().toString()}`);
const summaryHref = computed(() => `${BASE_URL}?${appliedParams({ supplierId: null }).toString()}`);
const partyHref = (partyId) => `${BASE_URL}?${appliedParams({ supplierId: partyId }).toString()}`;

const money = (key, header) => ({ id: key, header, numeric: true, cell: ({ row }) => formatMoney(row.original[key]) });

const partyColumns = [
    { accessorKey: 'sn', header: 'SN', numeric: true },
    {
        id: 'party',
        header: 'Supplier',
        numeric: false,
        cell: ({ row }) =>
            row.original.party_id
                ? h(Link, { href: partyHref(row.original.party_id), class: 'hover:text-purple-600 hover:underline' }, () => row.original.party)
                : row.original.party,
    },
    { id: 'pan', header: 'PAN', numeric: false, cell: ({ row }) => row.original.pan ?? '-' },
    { accessorKey: 'documents', header: 'Bills', numeric: true },
    money('taxable_amount', 'Taxable'),
    money('nontaxable_amount', 'Exempt'),
    money('vat_amount', 'VAT'),
    money('total', 'Total'),
    { accessorKey: 'returns_count', header: 'Debit Notes', numeric: true },
    money('returns_total', 'Returns'),
    money('net_total', 'Net'),
    money('tds_amount', 'TDS'),
    money('received', 'Paid'),
    money('outstanding', 'Outstanding'),
];

const totalLines = [
    { key: 'total', label: 'Total purchases' },
    { key: 'returns_total', label: 'Returns' },
    { key: 'net_total', label: 'Net purchases' },
    { key: 'vat_amount', label: 'VAT' },
    { key: 'tds_amount', label: 'TDS' },
    { key: 'received', label: 'Paid' },
    { key: 'outstanding', label: 'Outstanding' },
];

const statusBadges = {
    paid: { variant: 'success', label: 'Paid' },
    partial: { variant: 'warning', label: 'Part paid' },
    unpaid: { variant: 'danger', label: 'Unpaid' },
    credit: { variant: 'neutral', label: 'Overpaid' },
};
const paymentModeLabels = { cash: 'Cash', bank: 'Bank', partial: 'Partial', credit: 'Credit' };

function withBranch(columns, afterId) {
    if (!props.branchReport) {
        return columns;
    }

    const at = columns.findIndex((column) => column.id === afterId) + 1;
    const branchColumn = { id: 'branch', header: branchWord(), numeric: false, cell: ({ row }) => row.original.branch ?? '-' };

    return [...columns.slice(0, at), branchColumn, ...columns.slice(at)];
}

function purchaseListHref(document) {
    const params = new URLSearchParams({ supplier_id: props.detail?.party?.id ?? '', from: document.date, to: document.date });

    if (document.bill_number) {
        params.set('search', document.bill_number);
    }

    return `/purchases?${params.toString()}`;
}

const documentColumns = computed(() =>
    withBranch(
        [
            { accessorKey: 'sn', header: 'SN', numeric: true },
            { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
            { id: 'number', header: 'Purchase No', numeric: false, cell: ({ row }) => row.original.number ?? `#${row.original.id}` },
            {
                // Purchases have no show page; the list filtered to this supplier, this date and (when there is
                // one) this bill number is the nearest existing view. Its search matches bill_number only, so a
                // bill without one links by supplier and date, labelled with its purchase number.
                id: 'bill_number',
                header: 'Supplier Bill No',
                numeric: false,
                cell: ({ row }) => {
                    const label = row.original.bill_number ?? row.original.number;

                    if (!label) {
                        return '-';
                    }
                    if (!can('purchases.view')) {
                        return label;
                    }

                    return h(Link, { href: purchaseListHref(row.original), class: 'hover:text-purple-600 hover:underline' }, () => label);
                },
            },
            { id: 'payment_mode', header: 'Mode', numeric: false, cell: ({ row }) => paymentModeLabels[row.original.payment_mode] ?? row.original.payment_mode },
            money('taxable_amount', 'Taxable'),
            money('nontaxable_amount', 'Exempt'),
            money('vat_amount', 'VAT'),
            money('total', 'Total'),
            money('tds_amount', 'TDS'),
            money('received_at_billing', 'Paid at billing'),
            money('outstanding', 'Outstanding'),
            {
                id: 'payment_status',
                header: 'Status',
                numeric: false,
                cell: ({ row }) => {
                    const badge = statusBadges[row.original.payment_status] ?? statusBadges.unpaid;

                    return h(Badge, { variant: badge.variant }, () => badge.label);
                },
            },
        ],
        'bill_number',
    ),
);

const returnColumns = computed(() =>
    withBranch(
        [
            { accessorKey: 'sn', header: 'SN', numeric: true },
            { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
            { id: 'number', header: 'Debit Note No', numeric: false, cell: ({ row }) => row.original.number ?? '-' },
            { id: 'against', header: 'Against Bill', numeric: false, cell: ({ row }) => row.original.against ?? 'Unlinked' },
            money('total', 'Total'),
            money('tds_amount', 'TDS given back'),
        ],
        'against',
    ),
);

const partyRow = computed(() => props.rows.find((row) => row.party_id === props.detail?.party?.id) ?? null);
</script>

<template>
    <div>
        <PageHeader
            title="Supplier-wise Purchase"
            description="Purchases, returns, payments and what is still owed, per supplier. Click a supplier to see its bills."
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="party-wise-purchase"
                hide-label
                @change="applyFilter"
            />
            <Button v-if="can('purchase_reports.export')" as="a" :href="exportUrl" variant="secondary" tone="purple">
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
                <div class="w-60">
                    <Label class="mb-1">Supplier</Label>
                    <Combobox v-model="supplierInput" :options="supplierOptions" placeholder="Search supplier..." />
                </div>
                <div class="w-48">
                    <Label class="mb-1">Store</Label>
                    <Select v-model="storeInput" :options="storeOptions" />
                </div>
                <div class="w-44">
                    <Label class="mb-1">Payment mode</Label>
                    <Select v-model="paymentModeInput" :options="paymentModeOptions" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Search</Label>
                    <Input v-model="searchInput" placeholder="Supplier name or PAN..." @keydown.enter.prevent="applyFilter" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Clear filters</Button>
            </div>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
        </Card>

        <template v-if="detail">
            <Card variant="panel" class="mb-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-[15px] font-bold text-text-strong">{{ detail.party.name }}</div>
                        <div class="text-[12.5px] text-text-muted">PAN: {{ detail.party.pan || '-' }}</div>
                    </div>
                    <Button as="a" :href="summaryHref" variant="secondary" tone="purple">
                        <ArrowLeft class="h-[14px] w-[14px]" aria-hidden="true" />
                        All suppliers
                    </Button>
                </div>
                <p class="mt-2 text-[12px] text-text-muted">
                    These lists are the supplier's row narrowed to its documents, so their totals equal the row:
                    bills give Total, debit notes give Returns, paid at billing plus payments gives Paid,
                    and the bills' outstanding plus earlier bills gives Outstanding.
                </p>
            </Card>

            <Card variant="panel" class="mb-4">
                <div class="mb-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Bills</div>
                <DataTable :columns="documentColumns" :data="detail.documents" :page-size="25" empty-message="No bills in this period." />
                <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 border-t-[1.5px] border-border pt-2 text-[13px]">
                    <span>Total <strong>{{ formatMoney(detail.documentTotals.total) }}</strong></span>
                    <span>VAT <strong>{{ formatMoney(detail.documentTotals.vat_amount) }}</strong></span>
                    <span>TDS <strong>{{ formatMoney(detail.documentTotals.tds_amount) }}</strong></span>
                    <span>Paid at billing <strong>{{ formatMoney(detail.documentTotals.received_at_billing) }}</strong></span>
                    <span>Outstanding <strong>{{ formatMoney(detail.documentTotals.outstanding) }}</strong></span>
                </div>
            </Card>

            <Card variant="panel" class="mb-4">
                <div class="mb-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Debit notes (posted)</div>
                <DataTable :columns="returnColumns" :data="detail.returns" :page-size="10" empty-message="No debit notes in this period." />
                <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 border-t-[1.5px] border-border pt-2 text-[13px]">
                    <span>Returns <strong>{{ formatMoney(detail.returnTotals.total) }}</strong></span>
                    <span>TDS given back <strong>{{ formatMoney(detail.returnTotals.tds_amount) }}</strong></span>
                </div>
            </Card>

            <Card variant="panel" class="mb-4">
                <div class="divide-y divide-border text-[13px]">
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">Payments in the period ({{ detail.receipts.count }})</span>
                        <span class="font-semibold">{{ formatMoney(detail.receipts.amount) }}</span>
                    </div>
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">Outstanding on bills dated before the period</span>
                        <span class="font-semibold">{{ formatMoney(detail.earlierOutstanding) }}</span>
                    </div>
                    <div class="flex items-center justify-between py-1.5">
                        <span class="text-text-muted">
                            TDS: bills {{ formatMoney(detail.documentTotals.tds_amount) }} less given back on debit notes
                            {{ formatMoney(detail.returnTotals.tds_amount) }}
                        </span>
                        <span class="font-semibold">{{ formatMoney(subtractMoney(detail.documentTotals.tds_amount, detail.returnTotals.tds_amount)) }}</span>
                    </div>
                    <div v-if="partyRow" class="flex items-center justify-between pt-2 text-text-strong">
                        <span class="font-bold">
                            Supplier row: Net {{ formatMoney(partyRow.net_total) }}, TDS {{ formatMoney(partyRow.tds_amount) }}, Paid
                            {{ formatMoney(partyRow.received) }}
                        </span>
                        <span class="font-bold">Outstanding {{ formatMoney(partyRow.outstanding) }}</span>
                    </div>
                </div>
            </Card>
        </template>

        <template v-else>
            <Card variant="panel" class="mb-4">
                <DataTable :columns="partyColumns" :data="rows" :page-size="25" empty-message="No supplier activity in this period. Try widening the date range." />
            </Card>

            <Card variant="panel" class="mb-4">
                <div class="mb-1 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    Totals ({{ totals.documents ?? 0 }} bills, {{ totals.returns_count ?? 0 }} debit notes)
                </div>
                <div class="grid gap-x-6 text-[13px] sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="line in totalLines" :key="line.key" class="flex items-center justify-between border-b border-border py-1.5">
                        <span class="text-text-muted">{{ line.label }}</span>
                        <span class="font-semibold">{{ formatMoney(totals[line.key] ?? '0.00') }}</span>
                    </div>
                </div>
            </Card>
        </template>

        <p v-if="storeId !== null || paymentMode !== null" class="mb-1 text-[12px] font-semibold text-text-muted">
            With a store or payment-mode filter, Paid counts only payments allocated to the bills that match it; advances not allocated to a bill are left out.
        </p>
        <p class="text-[12px] text-text-muted">
            Posted purchases and posted debit notes only; cancelled bills are left out, so Total equals the Purchase Register
            total for the same filters. Without a store or payment-mode filter, payments are per supplier and only the branch filter narrows them. Outstanding is
            what is still owed today on the supplier's bills dated up to the end date (later payments and returns are
            already counted); opening ledger balances are in the Creditors report.
        </p>
    </div>
</template>
