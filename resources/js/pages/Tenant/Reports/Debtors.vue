<script setup>
import { computed, h, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import { useAreaReportFilter } from '@/composables/useAreaReportFilter';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import AreaFilterBar from '@/components/parties/AreaFilterBar.vue';
import AreaGroupedTable from '@/components/parties/AreaGroupedTable.vue';
import { FileSpreadsheet } from '@lucide/vue';
import { formatMoney } from '@/lib/money';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { usePartyBranchColumns } from '@/composables/usePartyBranchColumns';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

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
});

useLayoutChrome('Debtors');

const fiscalYearId = ref(props.fiscalYearId);
const areaFilter = useAreaReportFilter(props);
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

function applyFilter() {
    router.get(
        window.location.pathname,
        { fiscal_year_id: fiscalYearId.value ?? undefined, ...areaFilter.toQuery(), ...branchFilter.toQuery() },
        { preserveState: true, preserveScroll: true },
    );
}

// Built from what the server applied (props), not the unsaved dropdowns, so
// the workbook always matches the table on screen.
const exportUrl = computed(() => {
    const params = new URLSearchParams({
        ...(props.fiscalYearId ? { fiscal_year_id: props.fiscalYearId } : {}),
        ...areaFilter.toQuery({ area: props.area, groupByArea: props.groupByArea }),
    });
    branchFilter.appendTo(params);
    const query = params.toString();

    return query ? `/reports/debtors/export?${query}` : '/reports/debtors/export';
});

const nameColumn = { id: 'name', header: 'Customer', numeric: false, cell: ({ row }) => row.original.name };
const addressColumn = { id: 'address', header: 'Address', numeric: false, cell: ({ row }) => row.original.address ?? '-' };
const mobileColumn = { id: 'mobile_no', header: 'Mobile', numeric: false, cell: ({ row }) => row.original.mobile_no ?? '-' };
const areaColumn = { id: 'area', header: 'Area', numeric: false, cell: ({ row }) => row.original.area ?? '-' };
const balanceColumn = {
    id: 'balance',
    header: 'Balance',
    numeric: true,
    cell: ({ row }) => row.original.is_credit_balance
        ? h('span', { class: 'inline-flex items-center gap-1.5' }, [
            formatMoney(row.original.balance),
            h('span', { class: 'rounded-full bg-success/15 px-1.5 py-0.5 text-[10px] font-semibold text-success' }, 'Credit balance'),
        ])
        : formatMoney(row.original.balance),
};

// The flat list shows the area on each row; the grouped layout shows it as the heading instead.
const flatColumns = computed(() => [nameColumn, addressColumn, mobileColumn, areaColumn, balanceColumn, ...branchColumns.value]);
const groupedColumns = computed(() => [nameColumn, addressColumn, mobileColumn, balanceColumn, ...branchColumns.value]);
const subtotalColumns = computed(() => ['balance', ...branchSubtotalIds.value]);

const emptyMessage = computed(() =>
    props.areaLabel
        ? `No customer in "${props.areaLabel}" owes a balance in this fiscal year.`
        : 'No customer owes a balance in this fiscal year. Try another fiscal year.',
);
</script>

<template>
    <div>
        <PageHeader
            title="Debtors"
            description="Customers who owe you money, with the balance each one owes. Includes migrated opening dues no invoice explains."
        >
            <Button v-if="can('receivables_reports.export')" as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export to Excel
            </Button>
        </PageHeader>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-56">
                    <Label class="mb-1">Fiscal year</Label>
                    <Select v-model="fiscalYearId" :options="fiscalYearOptions" />
                </div>
                <AreaFilterBar
                    v-model:area="areaFilter.area.value"
                    v-model:group-by-area="areaFilter.groupByArea.value"
                    :areas="areas"
                    id-prefix="debtors"
                />
                <BranchReportFilter
                    v-if="branchReport"
                    v-model:choice="branchFilter.choice.value"
                    v-model:by-branch="branchFilter.byBranch.value"
                    :report="branchReport"
                    allow-by-branch
                    id-prefix="debtors-branch"
                    @change="applyFilter"
                />
                <Button variant="primary" tone="purple" @click="applyFilter">Generate report</Button>
            </div>
            <BranchReportNotes v-if="branchReport" :report="branchReport" />
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
                :empty-message="emptyMessage"
                :style="minWidth(720, 128)"
            />
            <DataTable
                v-else
                :key="branchColumnsKey"
                :columns="flatColumns"
                :data="rows"
                :page-size="25"
                :empty-message="emptyMessage"
                :style="minWidth(720, 128)"
            />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="font-bold text-text-strong">Total:</span> <span class="font-bold">{{ formatMoney(total) }}</span></div>
                <div v-for="branch in branchTotals" :key="branch.id">
                    <span class="text-text-muted">{{ branch.name }}:</span> <span class="font-semibold">{{ formatMoney(branch.total) }}</span>
                </div>
            </div>
        </Card>
    </div>
</template>
