<script setup>
/**
 * The ageing report shared by Aged Receivables and Aged Payables: the as-of
 * date / party / hide-zero / area / branch filters, the per-party table (flat
 * or grouped by area), the totals footer with advances, the footnote and the
 * Excel / PDF downloads. The two pages differ only in the copy they pass in.
 */
import { computed, h, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useAreaReportFilter } from '@/composables/useAreaReportFilter';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { usePermissions } from '@/composables/usePermissions';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Combobox from '@/components/ui/Combobox.vue';
import CheckboxField from '@/components/ui/CheckboxField.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import AreaFilterBar from '@/components/parties/AreaFilterBar.vue';
import AreaGroupedTable from '@/components/parties/AreaGroupedTable.vue';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import { FileSpreadsheet, FileText } from '@lucide/vue';
import { compareMoney, formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

const props = defineProps({
    // Report data, as the controller sends it.
    rows: { type: Array, default: () => [] },
    totals: {
        type: Object,
        default: () => ({
            opening: '0.00',
            current: '0.00',
            days31_60: '0.00',
            days61_90: '0.00',
            days90Plus: '0.00',
            total: '0.00',
        }),
    },
    advances: { type: String, default: '0.00' },
    parties: { type: Array, default: () => [] },
    partyId: { type: [Number, null], default: null },
    partyName: { type: [String, null], default: null },
    hideZero: { type: Boolean, default: true },
    asOf: { type: String, required: true },
    fiscalYearId: { type: [Number, null], default: null },
    areas: { type: Array, default: () => [] },
    area: { type: String, default: '' },
    areaLabel: { type: [String, null], default: null },
    groupByArea: { type: Boolean, default: false },
    groups: { type: [Array, null], default: null },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },

    // Copy and wiring the wrapper pages supply.
    pageTitle: { type: String, required: true },
    description: { type: String, required: true },
    partyLabel: { type: String, required: true }, // 'Customer' | 'Supplier'
    partyParam: { type: String, required: true }, // 'customer_id' | 'supplier_id'
    documentLabel: { type: String, required: true }, // 'invoices' | 'bills'
    openingTerm: { type: String, required: true }, // 'opening due' | 'opening payable'
    advanceTerm: { type: String, required: true }, // 'receipt' | 'payment'
    counterReportLabel: { type: String, required: true }, // 'Debtors' | 'Creditors'
    exportPermission: { type: String, required: true },
    basePath: { type: String, required: true }, // '/reports/aged-receivables' | '/reports/aged-payables'
    idPrefix: { type: String, required: true },
});

const { can } = usePermissions();

// Unapplied filter state, named *Input so it never shadows a prop. Each one
// follows the applied prop after every visit (back/forward, a link).
const asOfInput = ref(props.asOf);
const partyIdInput = ref(props.partyId);
const hideZeroInput = ref(props.hideZero);
const areaFilter = useAreaReportFilter(props);
const branchFilter = useBranchReportFilter(() => props.branchReport);

watch(
    () => [props.asOf, props.partyId, props.hideZero],
    ([asOf, partyId, hideZero]) => {
        asOfInput.value = asOf;
        partyIdInput.value = partyId;
        hideZeroInput.value = hideZero;
    },
);

const partyOptions = computed(() => [
    { value: null, label: `All ${props.partyLabel.toLowerCase()}s` },
    ...props.parties.map((party) => ({ value: party.id, label: party.name })),
]);

const asOfLabel = computed(() => `BS ${formatBsDate(props.asOf)} (AD ${props.asOf})`);
const partyLabelLower = computed(() => props.partyLabel.toLowerCase());
const documentSingular = computed(() => props.documentLabel.replace(/s$/, ''));
const canOpenLedger = computed(() => can('account_ledger.view') || can('party_ledger.view'));

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            as_of: asOfInput.value,
            [props.partyParam]: partyIdInput.value ?? undefined,
            hide_zero: hideZeroInput.value ? '1' : '0',
            ...areaFilter.toQuery(),
            ...branchFilter.toQuery(),
        },
        { preserveState: true, preserveScroll: true },
    );
}

// Downloads are built from what the server applied (props), not the unsaved
// filters, so the file always matches the table on screen.
function downloadUrl(suffix) {
    const params = new URLSearchParams({
        as_of: props.asOf,
        ...(props.partyId !== null ? { [props.partyParam]: props.partyId } : {}),
        hide_zero: props.hideZero ? '1' : '0',
        ...areaFilter.toQuery({ area: props.area, groupByArea: props.groupByArea }),
    });
    branchFilter.appendTo(params);

    return `${props.basePath}/${suffix}?${params.toString()}`;
}

const exportUrl = computed(() => downloadUrl('export'));
const pdfUrl = computed(() => downloadUrl('pdf'));

function ledgerUrl(accountId) {
    const params = new URLSearchParams();
    if (props.fiscalYearId !== null) {
        params.set('fiscal_year_id', props.fiscalYearId);
    }
    params.set('to', props.asOf);
    branchFilter.appendTo(params);

    return `/accounts/${accountId}/ledger?${params.toString()}`;
}

function isNegative(value) {
    return compareMoney(value, '0.00') < 0;
}

// Advances and receipts on account make a party's figure negative; keep the
// sign and tint it so it is not mistaken for an amount owed.
function moneyNode(value) {
    const text = formatMoney(value);

    return isNegative(value) ? h('span', { class: 'text-danger' }, text) : text;
}

function amountColumn(id, header) {
    return { id, header, numeric: true, cell: ({ row }) => moneyNode(row.original[id]) };
}

const partyColumn = computed(() => ({
    id: 'party',
    header: props.partyLabel,
    numeric: false,
    cell: ({ row }) => {
        const { party, account_id: accountId } = row.original;

        return accountId && canOpenLedger.value
            ? h(Link, { href: ledgerUrl(accountId), class: 'font-semibold text-primary hover:underline' }, { default: () => party })
            : party;
    },
}));
const areaColumn = { id: 'area', header: 'Area', numeric: false, cell: ({ row }) => row.original.area ?? '-' };
const contactColumn = { id: 'contact', header: 'Contact', numeric: false, cell: ({ row }) => row.original.mobile_no || '-' };
const oldestColumn = {
    id: 'oldest',
    header: 'Oldest unpaid',
    numeric: false,
    cell: ({ row }) => {
        const oldest = row.original.oldest;
        const label = oldest ? formatBsDate(oldest) : '';

        return label ? h('span', { title: `AD ${oldest}` }, label) : '-';
    },
};
const daysColumn = { id: 'days', header: 'Days', numeric: true, cell: ({ row }) => row.original.days ?? '-' };
const amountColumns = [
    amountColumn('opening', 'Opening / unallocated'),
    amountColumn('current', 'Current (0-30)'),
    amountColumn('days31_60', '31-60 days overdue'),
    amountColumn('days61_90', '61-90 days overdue'),
    amountColumn('days90Plus', 'Over 90 days overdue'),
    amountColumn('total', 'Total'),
];

// The flat list shows the area on each row; the grouped layout shows it as the heading instead.
const flatColumns = computed(() => [partyColumn.value, areaColumn, contactColumn, oldestColumn, daysColumn, ...amountColumns]);
const groupedColumns = computed(() => [partyColumn.value, contactColumn, oldestColumn, daysColumn, ...amountColumns]);
const subtotalColumns = amountColumns.map((column) => column.id);

const totalLines = computed(() => [
    { key: 'opening', label: 'Opening:' },
    { key: 'current', label: 'Current (0-30 days):' },
    { key: 'days31_60', label: '31-60 days overdue:' },
    { key: 'days61_90', label: '61-90 days overdue:' },
    { key: 'days90Plus', label: 'Over 90 days overdue:' },
]);

const emptyMessage = computed(() =>
    props.areaLabel
        ? `Nothing outstanding for "${props.areaLabel}" as of this date. Try a later date.`
        : 'Nothing outstanding as of this date. Try a later date.',
);
</script>

<template>
    <div>
        <PageHeader :title="pageTitle" :description="description">
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                :id-prefix="`${idPrefix}-branch`"
                hide-label
                @change="applyFilter"
            />
            <template v-if="can(exportPermission)">
                <Button as="a" :href="exportUrl" variant="secondary" tone="purple">
                    <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                    Export to Excel
                </Button>
                <Button as="a" :href="pdfUrl" target="_blank" rel="noopener" variant="secondary" tone="purple">
                    <FileText class="h-[14px] w-[14px]" aria-hidden="true" />
                    Export to PDF
                </Button>
            </template>
        </PageHeader>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <Label class="mb-1">As of date (BS)</Label>
                    <NepaliDateInput v-model="asOfInput" />
                </div>
                <div class="w-64">
                    <Label class="mb-1">{{ partyLabel }}</Label>
                    <Combobox v-model="partyIdInput" :options="partyOptions" :placeholder="`Search ${partyLabelLower}...`" />
                </div>
                <CheckboxField
                    :id="`${idPrefix}-hide-zero`"
                    v-model="hideZeroInput"
                    label="Hide zero balances"
                    hint="Leave out parties whose balance nets to exactly zero on this date."
                />
                <AreaFilterBar
                    v-model:area="areaFilter.area.value"
                    v-model:group-by-area="areaFilter.groupByArea.value"
                    :areas="areas"
                    :id-prefix="idPrefix"
                />
                <Button variant="primary" tone="purple" @click="applyFilter">Generate report</Button>
            </div>
            <p class="mt-2 text-[12px] text-text-muted">Showing report as of {{ asOfLabel }}</p>
            <p v-if="partyName" class="mt-1 text-xs font-semibold text-text-strong">{{ partyLabel }}: {{ partyName }}</p>
            <p v-if="areaLabel" class="mt-1 text-xs font-semibold text-text-strong">Area: {{ areaLabel }}</p>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
        </Card>

        <Card variant="panel">
            <AreaGroupedTable
                v-if="groupByArea"
                :columns="groupedColumns"
                :groups="groups ?? []"
                :subtotal-columns="subtotalColumns"
                :empty-message="emptyMessage"
            />
            <DataTable v-else :columns="flatColumns" :data="rows" :page-size="25" :empty-message="emptyMessage" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div v-for="line in totalLines" :key="line.key">
                    <span class="text-text-muted">{{ line.label }}</span>
                    <span class="font-semibold" :class="{ 'text-danger': isNegative(totals[line.key]) }"> {{ formatMoney(totals[line.key]) }}</span>
                </div>
                <div>
                    <span class="font-bold text-text-strong">Total:</span>
                    <span class="font-bold" :class="{ 'text-danger': isNegative(totals.total) }"> {{ formatMoney(totals.total) }}</span>
                </div>
                <div>
                    <span class="text-text-muted">Advances (not in Total):</span>
                    <span class="font-semibold"> {{ formatMoney(advances) }}</span>
                </div>
            </div>

            <p class="mt-3 text-[11.5px] text-text-faint">
                Each {{ partyLabelLower }}'s ledger balance as of the chosen date, within that date's fiscal year. Every
                {{ advanceTerm }}, return and other credit dated on or before the date is applied to the oldest entries first
                (FIFO), and what is left of each entry is aged from its voucher date. Anything dated after the chosen date is
                ignored, so a past date shows the position as it stood that day. "Opening / unallocated" is the part of the
                year's {{ openingTerm }} (the Opening Balance voucher) still unsettled; it has no date of its own. A cancelled
                {{ documentSingular }} and its reversal, both on or before the date, are left out together. A {{ partyLabelLower }}
                in credit (an advance) is not listed; those balances are summed as Advances, outside the Total. A row's total
                is the {{ partyLabelLower }}'s ledger balance on the date for the selected branches, so it ties to the ledger
                run to the same date. "Oldest unpaid" is the date of the oldest entry still open and "Days" is its age.
                Choosing an area keeps only the {{ partyLabelLower }}s assigned to it.
            </p>
        </Card>
    </div>
</template>
