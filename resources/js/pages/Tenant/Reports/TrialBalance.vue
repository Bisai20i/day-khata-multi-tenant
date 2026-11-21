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
import TrialBalanceSection from '@/components/accounting/TrialBalanceSection.vue';
import { formatMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';
import { formatBranchAmount } from '@/lib/branchReport.js';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchLabel } from '@/composables/useBranchLabel';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import { useBranchReportColumns } from '@/composables/useBranchReportColumns';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import BranchReportCells from '@/components/branch/BranchReportCells.vue';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    accounts: { type: Array, default: () => [] },
    accountId: { type: [Number, null], default: null },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    heads: { type: Array, default: () => [] },
    totalOpeningDebit: { type: String, default: '0.00' },
    totalOpeningCredit: { type: String, default: '0.00' },
    totalPeriodDebit: { type: String, default: '0.00' },
    totalPeriodCredit: { type: String, default: '0.00' },
    totalDebit: { type: String, default: '0.00' },
    totalCredit: { type: String, default: '0.00' },
    // inBalance is the closing columns; opening and period are checked separately.
    inBalance: { type: Boolean, default: true },
    openingInBalance: { type: Boolean, default: true },
    periodInBalance: { type: Boolean, default: true },
    hideZero: { type: Boolean, default: true },
    // 'group' | 'subgroup' | 'ledger': how deep the server expands the hierarchy.
    level: { type: String, default: 'ledger' },
    // Accounts with no head or group, listed under "Unclassified" (flags G-08).
    unclassifiedCount: { type: Number, default: 0 },
    // Branch filter and by-branch columns; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Trial Balance');

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

// Local inputs only; everything shown or linked reads the applied props.
const fiscalYearInput = ref(props.fiscalYearId);
const fromInput = ref(props.from);
const toInput = ref(props.to);
const accountIdInput = ref(props.accountId);
const hideZeroInput = ref(props.hideZero);
const levelInput = ref(props.level);

const levelOptions = [
    { value: 'group', label: 'Group' },
    { value: 'subgroup', label: 'Sub group' },
    { value: 'ledger', label: 'Ledger' },
];

const { label: branchWord } = useBranchLabel();
const branchFilter = useBranchReportFilter(() => props.branchReport, { byBranch: true });
const { byBranch: hasBranchColumns, columns: branchColumns, unbalancedIds, totalOf, minWidth } = useBranchReportColumns(
    () => props.branchReport,
);

// Legacy's `?accno=` single-ledger filter (audit T15-5): "All accounts" is
// the default, matching the unfiltered trial balance.
const accountOptions = computed(() => [
    { value: null, label: 'All accounts' },
    ...props.accounts.map((account) => ({ value: account.id, label: `${account.name} · ${account.code ?? '-'}` })),
]);

// The window only ever means "inside the chosen year", so switching years
// resets it rather than carrying dates that now fall outside.
watch(fiscalYearInput, (value) => {
    if (value === props.fiscalYearId) {
        return;
    }

    const chosen = props.fiscalYears.find((year) => year.id === value);
    fromInput.value = chosen?.startDate ?? null;
    toInput.value = chosen?.endDate ?? null;
    apply();
});

watch(hideZeroInput, (value) => {
    if (value !== props.hideZero) {
        apply();
    }
});

watch(levelInput, (value) => {
    if (value !== props.level) {
        apply();
    }
});

// The server may clamp or replace what was sent; re-sync the inputs to what it applied.
watch(
    () => [props.fiscalYearId, props.from, props.to, props.accountId, props.hideZero, props.level],
    () => {
        fiscalYearInput.value = props.fiscalYearId;
        fromInput.value = props.from;
        toInput.value = props.to;
        accountIdInput.value = props.accountId;
        hideZeroInput.value = props.hideZero;
        levelInput.value = props.level;
    },
);

const hasActiveFilter = computed(() => props.accountId !== null || accountIdInput.value !== null);

function clearFilters() {
    accountIdInput.value = null;
    apply();
}

// Opening, period and closing each balance on their own; the banner names the ones that do not.
const isBalanced = computed(() => props.openingInBalance && props.periodInBalance && props.inBalance);
const imbalanceLines = computed(() => {
    const lines = [];

    if (!props.openingInBalance) {
        lines.push(`Opening: debit ${formatMoney(props.totalOpeningDebit)} against credit ${formatMoney(props.totalOpeningCredit)}.`);
    }
    if (!props.periodInBalance) {
        lines.push(`Period: debit ${formatMoney(props.totalPeriodDebit)} against credit ${formatMoney(props.totalPeriodCredit)}.`);
    }
    if (!props.inBalance) {
        lines.push(`Closing: debit ${formatMoney(props.totalDebit)} against credit ${formatMoney(props.totalCredit)}.`);
    }

    return lines;
});

function apply() {
    router.get(
        window.location.pathname,
        {
            fiscal_year_id: fiscalYearInput.value ?? undefined,
            from: fromInput.value ?? undefined,
            to: toInput.value ?? undefined,
            account_id: accountIdInput.value ?? undefined,
            hide_zero: hideZeroInput.value ? 1 : 0,
            level: levelInput.value,
            ...branchFilter.toQuery(),
        },
        { preserveState: true, preserveScroll: true },
    );
}

// Built from the applied props so Print and Export always match what is on screen.
function reportUrl(action) {
    const params = new URLSearchParams();
    if (props.fiscalYearId !== null) {
        params.set('fiscal_year_id', String(props.fiscalYearId));
    }
    if (props.from) {
        params.set('from', props.from);
    }
    if (props.to) {
        params.set('to', props.to);
    }
    if (props.accountId !== null) {
        params.set('account_id', String(props.accountId));
    }
    params.set('hide_zero', props.hideZero ? '1' : '0');
    params.set('level', props.level);
    branchFilter.appendTo(params);
    return `/reports/trial-balance/${action}?${params.toString()}`;
}

// The branch scope the ledger drill-down must carry so its total matches the row.
const ledgerBranchQuery = computed(() => {
    const params = branchFilter.appendTo(new URLSearchParams());
    params.delete('by_branch');

    return params.toString();
});

const printUrl = () => reportUrl('print');
const exportUrl = () => reportUrl('export');
</script>

<template>
    <div>
        <PageHeader
            title="Trial Balance"
            description="Total debits and credits of every account. The two totals must match."
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                v-model:by-branch="branchFilter.byBranch.value"
                :report="branchReport"
                allow-by-branch
                id-prefix="trial-balance"
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
                    <Label class="mb-1">From date (BS)</Label>
                    <NepaliDateInput v-model="fromInput" />
                </div>
                <div>
                    <Label class="mb-1">To date (BS)</Label>
                    <NepaliDateInput v-model="toInput" />
                </div>
                <div class="w-64">
                    <Label class="mb-1">Account</Label>
                    <Select v-model="accountIdInput" :options="accountOptions" />
                </div>
                <div class="w-40">
                    <Label class="mb-1">Level</Label>
                    <Select v-model="levelInput" :options="levelOptions" />
                </div>
                <CheckboxField
                    id="trial-balance-hide-zero"
                    v-model="hideZeroInput"
                    label="Hide zero-balance accounts"
                    hint="Leave out accounts with no opening, period or closing balance."
                    class="pb-2"
                />
                <Button variant="primary" tone="purple" @click="apply">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" @click="clearFilters">Reset</Button>
            </div>
            <p v-if="props.from && props.to" class="mt-2 text-[12px] text-text-muted">
                Showing report for {{ formatBsDate(props.from) }} to {{ formatBsDate(props.to) }} BS
                <span class="text-text-muted">({{ props.from }} to {{ props.to }})</span>
            </p>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
        </Card>

        <Card variant="panel">
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
                <template v-if="props.accountId === null && heads.length > 0">
                    <p
                        v-if="isBalanced"
                        class="mb-3 rounded border border-success bg-success-bg-soft px-3 py-2 text-[12.5px] font-semibold text-success"
                        role="status"
                    >
                        Balanced: opening, period and closing debits all equal their credits.
                    </p>
                    <div v-else class="mb-3 rounded border border-danger px-3 py-2 text-[12.5px] font-semibold text-danger">
                        <p>This trial balance does not balance:</p>
                        <p v-for="line in imbalanceLines" :key="line">{{ line }}</p>
                    </div>
                </template>

                <p v-if="hasBranchColumns && heads.length > 0" class="mb-2 text-[12px] text-text-muted">
                    {{ branchWord() }} columns show each account's closing balance in that {{ branchWord('lower') }}, as Dr or Cr.
                </p>

                <p v-if="heads.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                    {{ props.hideZero ? 'No accounts with a balance in this period. Untick "Hide zero-balance accounts" to list every account, or widen the date range.' : 'No transactions in this period. Try widening the date range.' }}
                </p>

                <div v-else class="overflow-x-auto">
                    <div class="min-w-[720px] divide-y divide-border" :style="minWidth(720, 112)">
                        <div class="flex items-center px-1 pb-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                            <div class="flex-1">Account</div>
                            <div class="w-28 text-right">Opening Debit (Dr)</div>
                            <div class="w-28 text-right">Opening Credit (Cr)</div>
                            <div class="w-28 text-right">Period Debit (Dr)</div>
                            <div class="w-28 text-right">Period Credit (Cr)</div>
                            <div class="w-28 text-right">Closing Debit (Dr)</div>
                            <div class="w-28 text-right">Closing Credit (Cr)</div>
                            <BranchReportCells
                                v-if="hasBranchColumns"
                                header
                                :columns="branchColumns"
                                :warn-ids="unbalancedIds"
                                warn-title="Total debit and credit do not match for this column."
                            />
                        </div>

                        <TrialBalanceSection
                            v-for="head in heads"
                            :key="head.name"
                            :head="head"
                            :columns="branchColumns"
                            :has-branch-columns="hasBranchColumns"
                            :can-ledger="can('account_ledger.view')"
                            :fiscal-year-id="fiscalYearId"
                            :from="props.from"
                            :to="props.to"
                            :ledger-query="ledgerBranchQuery"
                        />

                        <div class="flex items-center px-1 pt-2 text-[13px] font-bold text-text-strong">
                            <div class="flex-1">Total</div>
                            <div class="w-28 text-right">{{ formatMoney(totalOpeningDebit) }}</div>
                            <div class="w-28 text-right">{{ formatMoney(totalOpeningCredit) }}</div>
                            <div class="w-28 text-right">{{ formatMoney(totalPeriodDebit) }}</div>
                            <div class="w-28 text-right">{{ formatMoney(totalPeriodCredit) }}</div>
                            <div class="w-28 text-right">{{ formatMoney(totalDebit) }}</div>
                            <div class="w-28 text-right">{{ formatMoney(totalCredit) }}</div>
                            <BranchReportCells v-if="hasBranchColumns" :columns="branchColumns">
                                <template #default="{ column }">
                                    <span class="block">Dr {{ formatBranchAmount(totalOf(column.id)?.debit) }}</span>
                                    <span class="block">Cr {{ formatBranchAmount(totalOf(column.id)?.credit) }}</span>
                                </template>
                            </BranchReportCells>
                        </div>
                    </div>
                </div>
            </template>
        </Card>
    </div>
</template>
