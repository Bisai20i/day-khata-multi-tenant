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
import CheckboxField from '@/components/ui/CheckboxField.vue';
import { formatMoney, isZeroMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { useBranchReportColumns } from '@/composables/useBranchReportColumns';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import BranchReportCells from '@/components/branch/BranchReportCells.vue';
import BranchReportHeaderRow from '@/components/branch/BranchReportHeaderRow.vue';
import BalanceSheetSection from '@/components/accounting/BalanceSheetSection.vue';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    heads: { type: Array, default: () => [] },
    stock: { type: Object, default: () => ({ opening: '0.00', closing: '0.00', posted: false }) },
    // Still sent by the server, but it is already inside Capital (a synthetic ledger row) and inside
    // totalLiabilitiesAndCapital, so it is never rendered or added again here. Declared only so it does not
    // fall through to the root element as an attribute.
    currentYearEarnings: { type: String, default: '0.00' },
    // Effective value of the hide_zero filter (the server defaults it to true).
    hideZero: { type: Boolean, default: true },
    totalAssets: { type: String, default: '0.00' },
    totalLiabilitiesAndCapital: { type: String, default: '0.00' },
    balanceWarning: { type: [String, null], default: null },
    // Accounts with no head or group, listed under "Unclassified" (flags G-08).
    unclassifiedCount: { type: Number, default: 0 },
    // Branch filter and by-branch columns; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Balance Sheet');

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

// Editable inputs only; the applied values (what the server rendered) are the props.
const fiscalYearInput = ref(props.fiscalYearId);
const toInput = ref(props.to);
const hideZeroInput = ref(props.hideZero);

watch(
    () => [props.fiscalYearId, props.to, props.hideZero],
    ([fiscalYearId, to, hideZero]) => {
        fiscalYearInput.value = fiscalYearId;
        toInput.value = to;
        hideZeroInput.value = hideZero;
    },
);

watch(hideZeroInput, (value) => {
    if (value !== props.hideZero) {
        apply();
    }
});

const branchFilter = useBranchReportFilter(() => props.branchReport, { byBranch: true });
const { byBranch: hasBranchColumns, columns: branchColumns, unbalancedIds, totalValues, minWidth } = useBranchReportColumns(
    () => props.branchReport,
);

watch(fiscalYearInput, (value) => {
    if (value === props.fiscalYearId) {
        return;
    }

    const chosen = props.fiscalYears.find((year) => year.id === value);
    toInput.value = chosen?.endDate ?? null;
    apply();
});

function apply() {
    router.get(
        window.location.pathname,
        {
            fiscal_year_id: fiscalYearInput.value ?? undefined,
            to: toInput.value ?? undefined,
            hide_zero: hideZeroInput.value ? 1 : 0,
            ...branchFilter.toQuery(),
        },
        { preserveState: true, preserveScroll: true },
    );
}

// Liabilities & Equity (left) is every head except Assets, in the server order; Assets sits on the right.
// The current year profit is already a ledger row inside Capital, so there is no separate profit section.
const assetHeads = computed(() => props.heads.filter((head) => head.name === 'Assets'));
const otherHeads = computed(() => props.heads.filter((head) => head.name !== 'Assets'));
// Stacked (branch columns) keeps the wide amount cell so the branch cells stay aligned; the half-width
// two-sided layout uses a narrower one.
const amountWidth = computed(() => (hasBranchColumns.value ? 'w-32' : 'w-28'));
const isBalanced = computed(() => props.balanceWarning === null && !isZeroMoney(props.totalAssets));

// Branch params for ledger links (the ledger ignores `by_branch`).
const branchQuery = computed(() => {
    const params = new URLSearchParams();
    branchFilter.appendTo(params);
    params.delete('by_branch');

    return params.toString();
});

function inventoryScheduleUrl() {
    const params = new URLSearchParams({ to: props.stock.asOf });
    branchFilter.appendTo(params);
    return `/reports/manufacturing/inventory-schedule?${params.toString()}`;
}

// Built from the applied report (props), so Print and Export match what is on screen.
function appliedParams() {
    const params = new URLSearchParams({
        fiscal_year_id: props.fiscalYearId ?? '',
        to: props.to ?? '',
        hide_zero: props.hideZero ? '1' : '0',
    });
    branchFilter.appendTo(params);

    return params;
}

function printUrl() {
    return `/reports/balance-sheet/print?${appliedParams().toString()}`;
}

function exportUrl() {
    return `/reports/balance-sheet/export?${appliedParams().toString()}`;
}
</script>

<template>
    <div>
        <PageHeader
            title="Balance Sheet"
            description="What the business owns (assets) against what it owes and the owners' capital, on one date."
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                v-model:by-branch="branchFilter.byBranch.value"
                :report="branchReport"
                allow-by-branch
                id-prefix="balance-sheet"
                hide-label
                @change="apply"
            />
            <template v-if="fiscalYearId !== null">
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
                    <Label class="mb-1">As at date (BS)</Label>
                    <NepaliDateInput v-model="toInput" />
                </div>
                <CheckboxField
                    id="balance-sheet-hide-zero"
                    v-model="hideZeroInput"
                    label="Hide zero-balance accounts"
                    hint="Leave out accounts and groups with no balance as at this date."
                    class="pb-2"
                />
                <Button variant="primary" tone="purple" @click="apply">Generate report</Button>
            </div>
            <p v-if="to" class="mt-2 text-[12px] text-text-muted">
                Showing report as at {{ formatBsDate(to) }} BS <span class="text-text-muted">({{ to }})</span>
            </p>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
        </Card>

        <p v-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
            No fiscal year has been created yet.
        </p>

        <template v-else>
            <p
                v-if="unclassifiedCount > 0"
                class="mb-3 rounded border border-warning-text bg-warning-bg px-3 py-2 text-[12.5px] font-semibold text-warning-text"
            >
                {{ unclassifiedCount }} {{ unclassifiedCount === 1 ? 'account is' : 'accounts are' }} not filed under any head or group, so
                {{ unclassifiedCount === 1 ? 'it is' : 'they are' }} listed under Unclassified. Move {{ unclassifiedCount === 1 ? 'it' : 'them' }} into
                the right group in the chart of accounts.
            </p>
            <p
                v-if="balanceWarning"
                class="mb-4 rounded border border-danger px-3 py-2 text-[12.5px] font-semibold text-danger"
            >
                {{ balanceWarning }}
            </p>
            <p
                v-else-if="isBalanced"
                class="mb-4 rounded border border-success bg-success-bg-soft px-3 py-2 text-[12.5px] font-semibold text-success"
                role="status"
            >
                Balance Sheet is balanced
            </p>

            <div class="grid items-start gap-4" :class="{ 'md:grid-cols-2': !hasBranchColumns }">
                <Card variant="panel" title="Liabilities &amp; Equity" :class="{ 'overflow-x-auto': hasBranchColumns }">
                    <div class="divide-y divide-border" :style="minWidth(320, 128)">
                        <BranchReportHeaderRow
                            v-if="hasBranchColumns"
                            :columns="branchColumns"
                            :warn-ids="unbalancedIds"
                            warn-title="Assets and liabilities &amp; equity do not match for this column."
                        />
                        <p v-if="otherHeads.length === 0" class="px-1 py-4 text-center text-[13px] text-text-muted">
                            Nothing to show{{ hideZero ? '. Untick "Hide zero-balance accounts" to list every account.' : '.' }}
                        </p>
                        <BalanceSheetSection
                            v-for="head in otherHeads"
                            :key="head.name"
                            :head="head"
                            :base-level="1"
                            show-heading
                            show-head-total
                            :columns="branchColumns"
                            :has-branch-columns="hasBranchColumns"
                            :can-ledger="can('account_ledger.view')"
                            :fiscal-year-id="fiscalYearId"
                            :to="to"
                            :branch-query="branchQuery"
                        />
                        <div class="flex items-center px-1 pt-2 text-[13px] font-bold text-text-strong">
                            <div class="flex-1">Total Liabilities &amp; Equity</div>
                            <div :class="amountWidth" class="text-right">{{ formatMoney(totalLiabilitiesAndCapital) }}</div>
                            <BranchReportCells v-if="hasBranchColumns" :columns="branchColumns" :values="totalValues('totalLiabilitiesAndCapital')" cell-class="w-32" />
                        </div>
                    </div>
                </Card>

                <Card variant="panel" title="Assets" :class="{ 'overflow-x-auto': hasBranchColumns }">
                    <div class="divide-y divide-border" :style="minWidth(320, 128)">
                        <BranchReportHeaderRow
                            v-if="hasBranchColumns"
                            :columns="branchColumns"
                            :warn-ids="unbalancedIds"
                            warn-title="Assets and liabilities &amp; equity do not match for this column."
                        />
                        <p v-if="assetHeads.length === 0" class="px-1 py-4 text-center text-[13px] text-text-muted">
                            Nothing to show{{ hideZero ? '. Untick "Hide zero-balance accounts" to list every account.' : ' as at this date. Try a later date.' }}
                        </p>
                        <BalanceSheetSection
                            v-for="head in assetHeads"
                            :key="head.name"
                            :head="head"
                            :columns="branchColumns"
                            :has-branch-columns="hasBranchColumns"
                            :can-ledger="can('account_ledger.view')"
                            :fiscal-year-id="fiscalYearId"
                            :to="to"
                            :branch-query="branchQuery"
                        />
                        <div class="flex items-center px-1 pt-2 text-[13px] font-bold text-text-strong">
                            <div class="flex-1">Total Assets</div>
                            <div :class="amountWidth" class="text-right">{{ formatMoney(totalAssets) }}</div>
                            <BranchReportCells v-if="hasBranchColumns" :columns="branchColumns" :values="totalValues('totalAssets')" cell-class="w-32" />
                        </div>
                    </div>
                    <p v-if="!stock.posted" class="mt-2 text-[12px] text-text-muted">
                        Stock in Hand is valued live at {{ formatMoney(stock.closing) }} as at {{ stock.asOf }}, because
                        this fiscal year has not been closed yet. Closing the year posts exactly that amount.
                    </p>
                    <p v-if="can('manufacturing_reports.view')" class="mt-2 text-[12px]">
                        <a :href="inventoryScheduleUrl()" class="font-semibold text-primary hover:underline">
                            Inventory schedule: raw material, work in process and finished goods
                        </a>
                    </p>
                </Card>
            </div>
        </template>
    </div>
</template>
