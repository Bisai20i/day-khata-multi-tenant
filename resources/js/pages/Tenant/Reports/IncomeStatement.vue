<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import { addMoney, compareMoney, subtractMoney, sumMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { useBranchReportColumns } from '@/composables/useBranchReportColumns';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import BranchReportCells from '@/components/branch/BranchReportCells.vue';
import IncomeStatementTree from '@/components/accounting/IncomeStatementTree.vue';
import { collectKeys } from '@/lib/incomeStatementTree.js';
import IncomeStatementRow from '@/components/accounting/IncomeStatementRow.vue';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    // The legacy-layout statement (revenue, cost of sales, gross profit, other
    // income, expenses, net profit); undefined until the server sends it.
    statement: { type: [Object, null], default: null },
    // Flat figures still sent alongside `statement`; declared so they do not
    // fall through to the root element as attributes.
    income: { type: Array, default: () => [] },
    expenses: { type: Array, default: () => [] },
    stock: { type: Object, default: () => ({ opening: '0.00', closing: '0.00', posted: false }) },
    totalIncome: { type: String, default: '0.00' },
    totalExpenses: { type: String, default: '0.00' },
    grossProfit: { type: String, default: '0.00' },
    netProfit: { type: String, default: '0.00' },
    // Branch filter and by-branch columns; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
    // Project selector options and the applied project (null: all projects).
    projects: { type: Array, default: () => [] },
    projectId: { type: [Number, null], default: null },
});

useLayoutChrome('Income Statement');

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

// Editable inputs only; the applied values (what the server rendered) are the props.
const fiscalYearInput = ref(props.fiscalYearId);
const fromInput = ref(props.from);
const toInput = ref(props.to);
// 0 is "all projects": the select cannot hold an empty value, and the server
// reads a blank or 0 project_id as no filter.
const projectInput = ref(props.projectId ?? 0);

watch(
    () => [props.fiscalYearId, props.from, props.to, props.projectId],
    ([fiscalYearId, from, to, projectId]) => {
        fiscalYearInput.value = fiscalYearId;
        fromInput.value = from;
        toInput.value = to;
        projectInput.value = projectId ?? 0;
    },
);

const projectOptions = computed(() => [
    { value: 0, label: 'All projects' },
    ...props.projects.map((project) => ({ value: project.id, label: `${project.code} - ${project.name}` })),
]);

const appliedProject = computed(() => props.projects.find((project) => project.id === props.projectId) ?? null);

const branchFilter = useBranchReportFilter(() => props.branchReport, { byBranch: true });
const { byBranch: hasBranchColumns, columns: branchColumns, totalValues, minWidth } = useBranchReportColumns(
    () => props.branchReport,
);

const cogsScheduleUrl = computed(() => {
    const params = new URLSearchParams({ from: props.from ?? '', to: props.to ?? '' });
    branchFilter.appendTo(params);
    return `/reports/manufacturing/cogs-schedule?${params.toString()}`;
});

watch(fiscalYearInput, (value) => {
    if (value === props.fiscalYearId) {
        return;
    }

    const chosen = props.fiscalYears.find((year) => year.id === value);
    fromInput.value = chosen?.startDate ?? null;
    toInput.value = chosen?.endDate ?? null;
    apply();
});

function apply() {
    router.get(
        window.location.pathname,
        {
            fiscal_year_id: fiscalYearInput.value ?? undefined,
            from: fromInput.value ?? undefined,
            to: toInput.value ?? undefined,
            project_id: projectInput.value || undefined,
            ...branchFilter.toQuery(),
        },
        { preserveState: true, preserveScroll: true },
    );
}

// Sign tests run through the money helpers, never through Number(). A loss
// keeps its sign here; the result row prints it in parentheses.
const netProfitValue = computed(() => props.statement?.netProfit ?? props.netProfit);
const grossProfitValue = computed(() => props.statement?.grossProfit ?? props.grossProfit);
const isProfit = computed(() => compareMoney(netProfitValue.value, '0.00') >= 0);
const isGrossProfit = computed(() => compareMoney(grossProfitValue.value, '0.00') >= 0);

// Branch params for ledger links (the ledger ignores `by_branch`).
const branchQuery = computed(() => {
    const params = new URLSearchParams();
    branchFilter.appendTo(params);
    params.delete('by_branch');

    return params.toString();
});

// Passed to every row so ledger links keep the report's fiscal year, range and branch.
const ledger = computed(() => ({
    columns: branchColumns.value,
    fiscalYearId: props.fiscalYearId,
    from: props.from,
    to: props.to,
    branchQuery: branchQuery.value,
    projectId: props.projectId,
}));

const isEmpty = computed(() => {
    const current = props.statement;

    if (!current) {
        return true;
    }

    const rowLists = [
        current.revenue.salesRows,
        current.revenue.salesReturnRows,
        current.costOfSales.purchaseRows,
        current.costOfSales.purchaseReturnRows,
        current.costOfSales.directExpenseRows,
        current.otherIncome.rows,
        current.expenses.rows,
    ];
    const amounts = [current.costOfSales.openingStock.amount, current.costOfSales.closingStock.amount, current.grossProfit, current.netProfit];

    return rowLists.every((rows) => rows.length === 0) && amounts.every((amount) => compareMoney(amount, '0.00') === 0);
});

// By-branch cells. Rows carry `by_branch`; the section totals are rebuilt
// from them (exact string arithmetic), stock falls back to the server's
// per-branch totals, and gross/net profit come straight from those totals.
function branchSum(rows, branchId) {
    return sumMoney(rows.map((row) => row.by_branch?.[branchId] ?? '0.00'));
}

function stockValues(row, key) {
    return hasBranchColumns.value ? (row.by_branch ?? totalValues(key)) : null;
}

function perBranch(compute) {
    if (!hasBranchColumns.value || !props.statement) {
        return null;
    }

    return Object.fromEntries(branchColumns.value.map((column) => [column.id, compute(column.id)]));
}

const salesValues = computed(() => perBranch((id) => branchSum(props.statement.revenue.salesRows, id)));
const salesReturnValues = computed(() => perBranch((id) => branchSum(props.statement.revenue.salesReturnRows, id)));
const purchaseValues = computed(() => perBranch((id) => branchSum(props.statement.costOfSales.purchaseRows, id)));
const purchaseReturnValues = computed(() => perBranch((id) => branchSum(props.statement.costOfSales.purchaseReturnRows, id)));
const directExpenseValues = computed(() => perBranch((id) => branchSum(props.statement.costOfSales.directExpenseRows, id)));

const netRevenueValues = computed(() =>
    perBranch((id) => subtractMoney(branchSum(props.statement.revenue.salesRows, id), branchSum(props.statement.revenue.salesReturnRows, id))),
);

const costOfSalesValues = computed(() =>
    perBranch((id) => {
        const cost = props.statement.costOfSales;
        const opening = stockValues(cost.openingStock, 'openingStock')[id] ?? '0.00';
        const closing = stockValues(cost.closingStock, 'closingStock')[id] ?? '0.00';
        const bought = subtractMoney(branchSum(cost.purchaseRows, id), branchSum(cost.purchaseReturnRows, id));
        // Direct Expenses (wages, hire, fuel) are cost of sales too.
        const direct = branchSum(cost.directExpenseRows, id);

        return subtractMoney(addMoney(addMoney(opening, bought), direct), closing);
    }),
);

const otherIncomeValues = computed(() => perBranch((id) => branchSum(props.statement.otherIncome.rows, id)));

// The server's branch `totalExpenses` also counts purchases and opening stock,
// so the below-the-line total is rebuilt from the listed Expense rows instead.
const expensesValues = computed(() => perBranch((id) => branchSum(props.statement.expenses.rows, id)));

// Open nodes of the group tree (see lib/incomeStatementTree.js). Everything
// starts closed, like the reference system: groups and their totals only.
const expanded = ref([]);

function toggleNode(key) {
    expanded.value = expanded.value.includes(key) ? expanded.value.filter((open) => open !== key) : [...expanded.value, key];
}

const allKeys = computed(() => {
    const current = props.statement;

    if (!current) {
        return [];
    }

    return [
        ...collectKeys('sales', current.revenue.salesGroups),
        ...collectKeys('salesReturn', current.revenue.salesReturnGroups),
        ...collectKeys('purchases', current.costOfSales.purchaseGroups),
        ...collectKeys('purchaseReturn', current.costOfSales.purchaseReturnGroups),
        ...collectKeys('direct', current.costOfSales.directExpenseGroups),
        ...collectKeys('otherIncome', current.otherIncome.groups),
        ...collectKeys('expenses', current.expenses.groups),
    ];
});

const allExpanded = computed(() => allKeys.value.length > 0 && allKeys.value.every((key) => expanded.value.includes(key)));

function toggleAll() {
    expanded.value = allExpanded.value ? [] : [...allKeys.value];
}

// Built from the applied report (props), so Print and Export match what is on screen.
function appliedParams() {
    const params = new URLSearchParams({ fiscal_year_id: props.fiscalYearId ?? '', from: props.from ?? '', to: props.to ?? '' });
    if (props.projectId) {
        params.set('project_id', String(props.projectId));
    }
    branchFilter.appendTo(params);

    return params;
}

function printUrl() {
    return `/reports/income-statement/print?${appliedParams().toString()}`;
}

function exportUrl() {
    return `/reports/income-statement/export?${appliedParams().toString()}`;
}
</script>

<template>
    <div>
        <PageHeader
            title="Income Statement"
            description="Profit and loss: income earned minus expenses incurred in the period. Also called Profit &amp; Loss."
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                v-model:by-branch="branchFilter.byBranch.value"
                :report="branchReport"
                allow-by-branch
                id-prefix="income-statement"
                hide-label
                @change="apply"
            />
            <template v-if="fiscalYearId !== null">
                <Button variant="secondary" tone="purple" @click="toggleAll">{{ allExpanded ? 'Collapse all' : 'Expand all' }}</Button>
                <a v-if="can('financial_statements.print')" :href="printUrl()" target="_blank" rel="noopener"><Button variant="secondary" tone="purple">Print</Button></a>
                <a v-if="can('financial_statements.export')" :href="exportUrl()"><Button variant="secondary" tone="purple">Export</Button></a>
            </template>
        </PageHeader>

        <Card v-if="fiscalYearId !== null" variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-56">
                    <Label class="mb-1">Fiscal year</Label>
                    <Select v-model="fiscalYearInput" :options="fiscalYearOptions" />
                </div>
                <div>
                    <Label class="mb-1">From date (BS)</Label>
                    <NepaliDateInput v-model="fromInput" />
                </div>
                <div>
                    <Label class="mb-1">To date (BS)</Label>
                    <NepaliDateInput v-model="toInput" />
                </div>
                <div class="w-64">
                    <Label class="mb-1">Project</Label>
                    <Select v-model="projectInput" :options="projectOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="apply">Generate report</Button>
            </div>
            <p v-if="from && to" class="mt-2 text-[12px] text-text-muted">
                Showing report for {{ formatBsDate(from) }} to {{ formatBsDate(to) }} BS
                <span class="text-text-muted">({{ from }} to {{ to }})</span>
            </p>
            <p v-if="appliedProject" class="mt-1 text-[12px] text-text-muted">
                Project: {{ appliedProject.code }} - {{ appliedProject.name }}. Only lines posted to this project are
                included, and stock is not valued per project, so opening and closing stock are zero.
            </p>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
        </Card>

        <p v-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
            No fiscal year has been created yet.
        </p>

        <p v-else-if="isEmpty" class="px-1 py-6 text-center text-[13px] text-text-muted">
            No transactions in this period. Try widening the date range.
        </p>

        <template v-else>
            <Card variant="panel" class="mb-4" :class="{ 'overflow-x-auto': hasBranchColumns }">
                <div :style="minWidth(360, 128)">
                    <div class="flex items-center border-b border-border px-1 pb-1.5 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <div class="flex-1">Particulars</div>
                        <div class="w-32 text-right">Amount (Rs.)</div>
                        <BranchReportCells v-if="hasBranchColumns" header :columns="branchColumns" cell-class="w-32" />
                    </div>

                    <div class="px-1 pt-3 pb-1 text-[13px] font-bold text-text-strong">Revenue from operations</div>
                    <IncomeStatementRow variant="section" label="Sales" :amount="statement.revenue.sales" :values="salesValues" :columns="branchColumns" />
                    <IncomeStatementTree v-bind="ledger" section="sales" :groups="statement.revenue.salesGroups" :expanded="expanded" @toggle="toggleNode" />
                    <IncomeStatementRow variant="section" label="Less: Sales Return" :amount="statement.revenue.salesReturn" :values="salesReturnValues" :columns="branchColumns" />
                    <IncomeStatementTree v-bind="ledger" section="salesReturn" :groups="statement.revenue.salesReturnGroups" :expanded="expanded" @toggle="toggleNode" />
                    <IncomeStatementRow variant="subtotal" label="Net Revenue from operations" :amount="statement.revenue.net" :values="netRevenueValues" :columns="branchColumns" />

                    <div class="px-1 pt-3 pb-1 text-[13px] font-bold text-text-strong">Cost of goods sold / direct cost</div>
                    <IncomeStatementRow v-bind="ledger" indent :row="statement.costOfSales.openingStock" :values="stockValues(statement.costOfSales.openingStock, 'openingStock')" />
                    <IncomeStatementRow variant="section" label="Add: Purchases" :amount="statement.costOfSales.purchases" :values="purchaseValues" :columns="branchColumns" />
                    <IncomeStatementTree v-bind="ledger" section="purchases" :groups="statement.costOfSales.purchaseGroups" :expanded="expanded" @toggle="toggleNode" />
                    <IncomeStatementRow variant="section" label="Less: Purchase Return" :amount="statement.costOfSales.purchaseReturn" :values="purchaseReturnValues" :columns="branchColumns" />
                    <IncomeStatementTree v-bind="ledger" section="purchaseReturn" :groups="statement.costOfSales.purchaseReturnGroups" :expanded="expanded" @toggle="toggleNode" />
                    <template v-if="statement.costOfSales.directExpenseGroups.length > 0">
                        <IncomeStatementRow variant="section" label="Add: Direct Expenses" :amount="statement.costOfSales.directExpenses" :values="directExpenseValues" :columns="branchColumns" />
                        <IncomeStatementTree v-bind="ledger" section="direct" :groups="statement.costOfSales.directExpenseGroups" :expanded="expanded" @toggle="toggleNode" />
                    </template>
                    <IncomeStatementRow v-bind="ledger" indent :row="statement.costOfSales.closingStock" :values="stockValues(statement.costOfSales.closingStock, 'closingStock')" />
                    <IncomeStatementRow variant="subtotal" label="Total Cost of Sales" :amount="statement.costOfSales.total" :values="costOfSalesValues" :columns="branchColumns" />

                    <IncomeStatementRow
                        variant="result"
                        :tone="isGrossProfit ? 'success' : 'danger'"
                        label="Gross Profit / (Loss)"
                        :amount="statement.grossProfit"
                        :values="hasBranchColumns ? totalValues('grossProfit') : null"
                        :columns="branchColumns"
                    />

                    <div class="px-1 pt-3 pb-1 text-[13px] font-bold text-text-strong">Add: Other Business Income</div>
                    <IncomeStatementTree v-bind="ledger" section="otherIncome" :groups="statement.otherIncome.groups" :expanded="expanded" @toggle="toggleNode" />
                    <IncomeStatementRow variant="subtotal" label="Total Other Business Income" :amount="statement.otherIncome.total" :values="otherIncomeValues" :columns="branchColumns" />

                    <div class="px-1 pt-3 pb-1 text-[13px] font-bold text-text-strong">Less: Expenses</div>
                    <IncomeStatementTree v-bind="ledger" section="expenses" :groups="statement.expenses.groups" :expanded="expanded" @toggle="toggleNode" />
                    <IncomeStatementRow variant="subtotal" label="Total Expenses" :amount="statement.expenses.total" :values="expensesValues" :columns="branchColumns" />

                    <IncomeStatementRow
                        variant="result"
                        :tone="isProfit ? 'success' : 'danger'"
                        label="Net Profit / (Loss)"
                        :amount="statement.netProfit"
                        :values="hasBranchColumns ? totalValues('netProfit') : null"
                        :columns="branchColumns"
                    />
                </div>

                <p v-if="!stock.projectFiltered && !stock.posted && stock.yearPosted" class="mt-3 text-[12px] text-text-muted">
                    Stock is valued live for this date range: opening
                    <template v-if="stock.openingAsOf">as at {{ stock.openingAsOf }}</template
                    ><template v-else>as brought forward for the year</template>, closing as at {{ stock.asOf }}.
                </p>
                <p v-else-if="!stock.projectFiltered && !stock.posted" class="mt-3 text-[12px] text-text-muted">
                    This fiscal year has not been closed yet, so the stock figures above are valued live from stock
                    movements (as at {{ stock.asOf }}) rather than read from posted entries. Closing the year posts exactly these amounts.
                </p>
                <p v-if="can('manufacturing_reports.view')" class="mt-2 text-[12px]">
                    Opening and closing stock above cover trading items only. Manufactured goods reach this statement
                    through Cost of Goods Sold:
                    <a :href="cogsScheduleUrl" class="font-semibold text-primary hover:underline">COGM and COGS schedule</a>
                </p>
            </Card>
        </template>
    </div>
</template>
