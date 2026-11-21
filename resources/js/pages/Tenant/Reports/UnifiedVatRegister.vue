<script setup>
import { computed, h, ref } from 'vue';
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
import { formatMoney, compareMoney, subtractMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { useBranchLabel } from '@/composables/useBranchLabel';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const emptySide = () => ({
    count: 0,
    subtotal: '0.00',
    nontaxable_amount: '0.00',
    taxable_amount: '0.00',
    vat_amount: '0.00',
    total: '0.00',
});

const props = defineProps({
    rows: { type: Array, default: () => [] },
    totals: {
        type: Object,
        default: () => ({ output: emptySide(), input: emptySide(), netVatPayable: '0.00' }),
    },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Unified VAT Register');

const from = ref(props.from);
const to = ref(props.to);
const storeId = ref(props.storeId);

const branchFilter = useBranchReportFilter(() => props.branchReport);
const { label: branchWord } = useBranchLabel();

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

const isLoading = ref(false);

const hasActiveFilter = computed(() => storeId.value !== null);

function resetFilters() {
    storeId.value = null;
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: from.value, to: to.value, store_id: storeId.value ?? undefined, ...branchFilter.toQuery() },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

// Built from the currently-applied filters so exporting always matches what's on screen.
const exportUrl = computed(() => {
    const params = new URLSearchParams({ from: props.from, to: props.to });
    if (props.storeId) {
        params.set('store_id', props.storeId);
    }
    branchFilter.appendTo(params);

    return `/reports/vat-register/export?${params.toString()}`;
});

const returnKinds = ['sales_return', 'purchase_return'];

// Exact string comparison, never a float: "is this refundable" must not
// hinge on a rounding artefact.
const isPayable = computed(() => compareMoney(props.totals.netVatPayable, '0.00') >= 0);
const netVatLabel = computed(() =>
    isPayable.value ? 'Net VAT Payable (Output - Input)' : 'Net VAT Refundable (Input - Output)',
);
const netVatAmount = computed(() =>
    isPayable.value ? props.totals.netVatPayable : subtractMoney('0.00', props.totals.netVatPayable),
);

function typeCell(row) {
    const isReturn = returnKinds.includes(row.kind);
    const isCancelled = row.entry === 'cancelled';

    if (!isReturn && !isCancelled) {
        return row.type;
    }

    return h('span', [
        h('span', { class: isReturn ? 'text-danger' : '' }, row.type),
        isCancelled ? h('span', { class: 'ml-1 text-danger font-semibold' }, 'Cancelled') : null,
    ]);
}

const money = (key) => ({
    id: key,
    numeric: true,
    cell: ({ row }) => formatMoney(row.original[key]),
});

const baseColumns = [
    { accessorKey: 'sn', header: 'SN', numeric: true },
    { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { accessorKey: 'date', header: 'Date (AD)' },
    { id: 'type', header: 'Type', numeric: false, cell: ({ row }) => typeCell(row.original) },
    { id: 'document_number', header: 'Document No', numeric: false, cell: ({ row }) => row.original.document_number ?? '-' },
    { id: 'reference', header: 'Against', numeric: false, cell: ({ row }) => row.original.reference ?? '-' },
    { id: 'party', header: 'Party', numeric: false, cell: ({ row }) => row.original.party ?? '-' },
    { id: 'party_pan', header: 'PAN', numeric: false, cell: ({ row }) => row.original.party_pan ?? '-' },
    { ...money('subtotal'), header: 'Total Before Tax' },
    { ...money('nontaxable_amount'), header: 'Exempt' },
    { ...money('taxable_amount'), header: 'Taxable' },
    { ...money('vat_amount'), header: 'VAT' },
    { ...money('total'), header: 'Total' },
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
    const at = baseColumns.findIndex((column) => column.id === 'party_pan') + 1;

    return [...baseColumns.slice(0, at), branchColumn, ...baseColumns.slice(at)];
});

const sides = computed(() => [
    { key: 'output', title: 'Output VAT (sales side)', data: props.totals.output },
    { key: 'input', title: 'Input VAT (purchase side)', data: props.totals.input },
]);

const sideLines = [
    { key: 'subtotal', label: 'Total Before Tax' },
    { key: 'nontaxable_amount', label: 'Exempt' },
    { key: 'taxable_amount', label: 'Taxable' },
    { key: 'vat_amount', label: 'VAT' },
];
</script>

<template>
    <div>
        <PageHeader
            title="Unified VAT Register"
            description="Sales, purchases and their returns in one list. Output and input VAT are totalled separately - they are not added together."
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="unified-vat-register"
                hide-label
                @change="applyFilter"
            />
            <Button v-if="can('tax_reports.export')" as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export to Excel
            </Button>
        </PageHeader>

        <p class="mb-4 text-[12.5px] text-text-muted">Showing report for {{ rangeLabel }}</p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <Label class="mb-1">From date (BS)</Label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <Label class="mb-1">To date (BS)</Label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Store</Label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
        </Card>

        <Card variant="panel" class="mb-4">
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No VAT transactions in this period. Try widening the date range." />
        </Card>

        <Card variant="panel">
            <div class="grid gap-6 md:grid-cols-2">
                <div v-for="side in sides" :key="side.key">
                    <div class="mb-1 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">{{ side.title }}</div>
                    <div class="divide-y divide-border text-[13px]">
                        <div class="flex items-center justify-between py-1.5">
                            <span class="text-text-muted">Transactions</span>
                            <span class="font-semibold">{{ side.data.count }}</span>
                        </div>
                        <div v-for="line in sideLines" :key="line.key" class="flex items-center justify-between py-1.5">
                            <span class="text-text-muted">{{ line.label }}</span>
                            <span class="font-semibold">{{ formatMoney(side.data[line.key]) }}</span>
                        </div>
                        <div class="flex items-center justify-between pt-2 text-text-strong">
                            <span class="font-bold">Total</span>
                            <span class="font-bold">{{ formatMoney(side.data.total) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t-[1.5px] border-border pt-3">
                <div>
                    <div class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">{{ netVatLabel }}</div>
                    <p class="mt-1 text-[12px] text-text-muted">
                        {{ isPayable ? 'Amount owed to the tax authority for this period.' : 'Refundable or carry-forward credit for this period.' }}
                    </p>
                </div>
                <div
                    class="px-3 py-1.5 text-lg font-bold"
                    :class="isPayable ? 'bg-danger-bg text-danger' : 'bg-success-bg text-success'"
                >
                    {{ formatMoney(netVatAmount) }}
                </div>
            </div>
        </Card>
    </div>
</template>
