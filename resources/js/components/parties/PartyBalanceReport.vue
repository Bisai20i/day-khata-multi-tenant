<script setup>
import { computed, h, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useAreaReportFilter } from '@/composables/useAreaReportFilter';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import AreaFilterBar from '@/components/parties/AreaFilterBar.vue';
import AreaGroupedTable from '@/components/parties/AreaGroupedTable.vue';
import { FileSpreadsheet, FileText } from '@lucide/vue';
import { formatMoney, isZeroMoney } from '@/lib/money';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { usePartyBranchColumns } from '@/composables/usePartyBranchColumns';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';

/**
 * Shared body of the Debtors and Creditors reports. The two pages differ only in
 * copy and in which side of the books is normal: a debtor's balance is normally a
 * debit, a creditor's normally a credit (`creditNormal`). The amount column shows the
 * absolute amount and a Dr/Cr column shows the side, so a negative balance reads as the
 * opposite side instead of a misleading "credit balance" on both pages.
 */
const props = defineProps({
    rows: { type: Array, default: () => [] },
    total: { type: String, default: '0.00' },
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    creditBalanceNote: { type: String, default: '' },
    areas: { type: Array, default: () => [] },
    area: { type: String, default: '' },
    areaLabel: { type: [String, null], default: null },
    groupByArea: { type: Boolean, default: false },
    groups: { type: [Array, null], default: null },
    // Branch filter and by-branch balance columns; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
    pageTitle: { type: String, required: true },
    description: { type: String, default: '' },
    // Heading of the name column: 'Customer' or 'Supplier'.
    partyLabel: { type: String, required: true },
    // Shown when nothing matches; the area variant replaces `{area}` with the chosen area.
    emptyMessage: { type: String, required: true },
    emptyAreaMessage: { type: String, required: true },
    // True when a positive balance is a credit (Creditors); false when it is a debit (Debtors).
    creditNormal: { type: Boolean, default: false },
    exportPermission: { type: String, required: true },
    // '/reports/debtors' or '/reports/creditors'; the Excel and PDF routes hang off it.
    basePath: { type: String, required: true },
    idPrefix: { type: String, required: true },
});

const { can } = usePermissions();

const fiscalYearIdInput = ref(props.fiscalYearId);
const areaFilter = useAreaReportFilter(props);

// The dropdowns follow what the server applied (a visit, back/forward), not only the first render.
watch(
    () => [props.fiscalYearId, props.area, props.groupByArea],
    ([fiscalYearId, area, groupByArea]) => {
        fiscalYearIdInput.value = fiscalYearId;
        areaFilter.area.value = area ?? '';
        areaFilter.groupByArea.value = Boolean(groupByArea);
    },
);
const branchFilter = useBranchReportFilter(() => props.branchReport, { byBranch: true });
const { hasBranchColumns, branchColumns, branchSubtotalIds, groupsWithBranchTotals, branchTotals, minWidth } = usePartyBranchColumns(
    () => props.branchReport,
    () => props.groups,
);
// DataTable reads its columns once, so a change of branch columns needs a fresh table.
const branchColumnsKey = computed(() => branchColumns.value.map((column) => column.id).join(','));

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({ value: fiscalYear.id, label: fiscalYear.name })),
);

const canOpenLedger = computed(() => can('account_ledger.view') || can('party_ledger.view'));

function applyFilter() {
    router.get(
        window.location.pathname,
        { fiscal_year_id: fiscalYearIdInput.value ?? undefined, ...areaFilter.toQuery(), ...branchFilter.toQuery() },
        { preserveState: true, preserveScroll: true },
    );
}

// Built from what the server applied (props), not the unsaved dropdowns, so
// the file always matches the table on screen.
function fileUrl(suffix) {
    const params = new URLSearchParams({
        ...(props.fiscalYearId ? { fiscal_year_id: props.fiscalYearId } : {}),
        ...areaFilter.toQuery({ area: props.area, groupByArea: props.groupByArea }),
    });
    branchFilter.appendTo(params);
    const query = params.toString();

    return query ? `${props.basePath}/${suffix}?${query}` : `${props.basePath}/${suffix}`;
}

const exportUrl = computed(() => fileUrl('export'));
const pdfUrl = computed(() => fileUrl('pdf'));

// Pure string handling: the sign is a leading '-', never parsed to a number.
function isNegativeAmount(value) {
    return String(value).startsWith('-') && !isZeroMoney(value);
}

function absoluteAmount(value) {
    return String(value).replace(/^-/, '');
}

/** 'Dr', 'Cr' or '' (zero) for a signed balance, given which side is normal for this report. */
function sideOf(value) {
    if (isZeroMoney(value)) {
        return '';
    }

    const isDebit = props.creditNormal ? isNegativeAmount(value) : !isNegativeAmount(value);

    return isDebit ? 'Dr' : 'Cr';
}

// The ledger opens on the same applied fiscal year and branch filter, so its closing balance is this row's.
function ledgerHref(accountId) {
    const params = new URLSearchParams(props.fiscalYearId ? { fiscal_year_id: props.fiscalYearId } : {});
    branchFilter.appendTo(params);
    const query = params.toString();

    return query ? `/accounts/${accountId}/ledger?${query}` : `/accounts/${accountId}/ledger`;
}

function nameCell(row) {
    const label = row.account_id && canOpenLedger.value
        ? h(Link, { href: ledgerHref(row.account_id), class: 'text-primary hover:underline' }, () => row.name)
        : row.name;

    if (!row.is_overpaid) {
        return label;
    }

    return h('span', { class: 'inline-flex items-center gap-1.5' }, [
        label,
        h(Badge, { variant: 'warning', pill: true }, () => 'Overpaid'),
    ]);
}

const nameColumn = { id: 'name', header: props.partyLabel, numeric: false, cell: ({ row }) => nameCell(row.original) };
const addressColumn = { id: 'address', header: 'Address', numeric: false, cell: ({ row }) => row.original.address ?? '-' };
const mobileColumn = { id: 'mobile_no', header: 'Mobile', numeric: false, cell: ({ row }) => row.original.mobile_no ?? '-' };
const areaColumn = { id: 'area', header: 'Area', numeric: false, cell: ({ row }) => row.original.area ?? '-' };
const balanceColumn = { id: 'balance', header: 'Balance', numeric: true, cell: ({ row }) => formatMoney(absoluteAmount(row.original.balance)) };
const sideColumn = { id: 'side', header: 'Dr/Cr', numeric: false, cell: ({ row }) => sideOf(row.original.balance) };

// The flat list shows the area on each row; the grouped layout shows it as the heading instead.
const flatColumns = computed(() => [nameColumn, addressColumn, mobileColumn, areaColumn, balanceColumn, sideColumn, ...branchColumns.value]);
const groupedColumns = computed(() => [nameColumn, addressColumn, mobileColumn, balanceColumn, sideColumn, ...branchColumns.value]);
// Group subtotals stay signed (AreaGroupedTable formats them as given): a negative one is the opposite side.
const subtotalColumns = computed(() => ['balance', ...branchSubtotalIds.value]);

const resolvedEmptyMessage = computed(() =>
    props.areaLabel ? props.emptyAreaMessage.replace('{area}', () => props.areaLabel) : props.emptyMessage,
);
</script>

<template>
    <div>
        <PageHeader :title="pageTitle" :description="description">
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                v-model:by-branch="branchFilter.byBranch.value"
                :report="branchReport"
                allow-by-branch
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
                <div class="w-56">
                    <Label class="mb-1">Fiscal year</Label>
                    <Select v-model="fiscalYearIdInput" :options="fiscalYearOptions" />
                </div>
                <AreaFilterBar
                    v-model:area="areaFilter.area.value"
                    v-model:group-by-area="areaFilter.groupByArea.value"
                    :areas="areas"
                    :id-prefix="idPrefix"
                />
                <Button variant="primary" tone="purple" @click="applyFilter">Generate report</Button>
            </div>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
            <p v-if="areaLabel" class="mt-3 text-xs font-semibold text-text-strong">Area: {{ areaLabel }}</p>
            <p v-if="creditBalanceNote" class="mt-3 text-xs text-text-muted">{{ creditBalanceNote }}</p>
        </Card>

        <Card variant="panel" :class="hasBranchColumns ? 'overflow-x-auto' : undefined">
            <AreaGroupedTable
                v-if="groupByArea"
                :key="branchColumnsKey"
                :columns="groupedColumns"
                :groups="groupsWithBranchTotals"
                :subtotal-columns="subtotalColumns"
                :empty-message="resolvedEmptyMessage"
                :style="minWidth(720, 128)"
            />
            <DataTable
                v-else
                :key="branchColumnsKey"
                :columns="flatColumns"
                :data="rows"
                :page-size="25"
                :empty-message="resolvedEmptyMessage"
                :style="minWidth(720, 128)"
            />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div>
                    <span class="font-bold text-text-strong">Total:</span>
                    <span class="font-bold">{{ formatMoney(absoluteAmount(total)) }}</span>
                    <span v-if="sideOf(total)" class="ml-1 font-semibold text-text-muted">{{ sideOf(total) }}</span>
                </div>
                <div v-for="branch in branchTotals" :key="branch.id">
                    <span class="text-text-muted">{{ branch.name }}:</span> <span class="font-semibold">{{ formatMoney(branch.total) }}</span>
                </div>
            </div>
        </Card>
    </div>
</template>
