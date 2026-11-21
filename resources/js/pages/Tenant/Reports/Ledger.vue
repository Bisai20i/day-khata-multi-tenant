<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import LedgerEntriesTable from '@/components/accounting/LedgerEntriesTable.vue';
import StaffLedgerSection from '@/components/accounting/StaffLedgerSection.vue';
import SubLedgerSummaryTable from '@/components/accounting/SubLedgerSummaryTable.vue';
import { formatMoney } from '@/lib/money.js';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    accounts: { type: Array, default: () => [] },
    accountId: { type: [Number, null], default: null },
    account: { type: [Object, null], default: null },
    // Staff ledger integration
    staffList: { type: Array, default: () => [] },
    staffId: { type: [Number, null], default: null },
    staff: { type: [Object, null], default: null },
    staffSalaryEntries: { type: Array, default: () => [] },
    staffAdvanceEntries: { type: Array, default: () => [] },
    staffOpeningSalary: { type: String, default: '0.00' },
    staffOpeningAdvance: { type: String, default: '0.00' },
    staffClosingSalary: { type: String, default: '0.00' },
    staffClosingAdvance: { type: String, default: '0.00' },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    // Signed (debit-positive) balances; the Dr/Cr side is computed by the server.
    openingBalance: { type: String, default: '0.00' },
    openingSide: { type: String, default: '' },
    closingBalance: { type: String, default: '0.00' },
    closingSide: { type: String, default: '' },
    periodDebit: { type: String, default: '0.00' },
    periodCredit: { type: String, default: '0.00' },
    entries: { type: Array, default: () => [] },
    // Sub-accounts belonging to the selected account, if any.
    subAccounts: { type: Array, default: () => [] },
    includeSubAccounts: { type: Boolean, default: true },
    // Opening / Dr / Cr / Closing of every sub-ledger of the selected account.
    subLedgerSummary: { type: Object, default: () => ({ rows: [], total: null }) },
    // Project selector options and the applied project (null: all projects).
    projects: { type: Array, default: () => [] },
    projectId: { type: [Number, null], default: null },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Account Ledger');

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

const targetOptions = computed(() => {
    const list = [];

    // General ledger accounts
    props.accounts.forEach((acc) => {
        const parentTag = acc.parent ? ` ↳ ${acc.parent.name}` : '';
        const codeTag = acc.code ? ` (${acc.code})` : '';
        list.push({
            value: `acc-${acc.id}`,
            label: `${acc.name}${codeTag}${parentTag}`,
            searchValue: `${acc.name} ${acc.code ?? ''} ${acc.group ?? ''} ${acc.parent?.name ?? ''}`,
            type: 'account',
            rawId: acc.id,
        });
    });

    // Staff members
    props.staffList.forEach((st) => {
        const codeTag = st.staff_code ? ` (${st.staff_code})` : '';
        const desigTag = st.designation ? ` - ${st.designation}` : '';
        list.push({
            value: `staff-${st.id}`,
            label: `${st.name}${codeTag}${desigTag} [STAFF]`,
            searchValue: `${st.name} ${st.staff_code ?? ''} ${st.designation ?? ''} ${st.department ?? ''} staff employee hr`,
            type: 'staff',
            rawId: st.id,
        });
    });

    return list;
});

// Editable copies of the applied filters; the page shows the applied props and
// only sends these to the server on apply().
const fiscalYearInput = ref(props.fiscalYearId);
const accountIdInput = ref(props.accountId);
const staffIdInput = ref(props.staffId);
const fromInput = ref(props.from);
const toInput = ref(props.to);
const includeSubAccountsInput = ref(props.includeSubAccounts);
// 0 is "all projects": the select cannot hold an empty value, and the server
// reads a blank or 0 project_id as no filter.
const projectInput = ref(props.projectId ?? 0);

const projectOptions = computed(() => [
    { value: 0, label: 'All projects' },
    ...props.projects.map((project) => ({ value: project.id, label: `${project.code} - ${project.name}` })),
]);

const appliedProject = computed(() => props.projects.find((project) => project.id === props.projectId) ?? null);

watch(
    () => props.projectId,
    (v) => (projectInput.value = v ?? 0),
);

const selectedTarget = computed({
    get: () => {
        if (staffIdInput.value) return `staff-${staffIdInput.value}`;
        if (accountIdInput.value) return `acc-${accountIdInput.value}`;
        return null;
    },
    set: (val) => {
        if (!val) {
            accountIdInput.value = null;
            staffIdInput.value = null;
        } else if (String(val).startsWith('staff-')) {
            accountIdInput.value = null;
            staffIdInput.value = Number(String(val).replace('staff-', ''));
        } else if (String(val).startsWith('acc-')) {
            staffIdInput.value = null;
            accountIdInput.value = Number(String(val).replace('acc-', ''));
        } else {
            staffIdInput.value = null;
            accountIdInput.value = Number(val);
        }
        apply();
    },
});

watch(
    () => props.includeSubAccounts,
    (v) => (includeSubAccountsInput.value = v),
);

watch(
    () => props.accountId,
    (v) => (accountIdInput.value = v),
);

watch(
    () => props.staffId,
    (v) => (staffIdInput.value = v),
);

watch(
    () => props.fiscalYearId,
    (v) => (fiscalYearInput.value = v),
);

watch(
    () => props.from,
    (v) => (fromInput.value = v),
);

watch(
    () => props.to,
    (v) => (toInput.value = v),
);

const branchFilter = useBranchReportFilter(() => props.branchReport);

// The server always sends the fiscal year's own start/end, so "Clear dates" only
// matters once the applied window differs from them.
const hasCustomWindow = computed(() => {
    const year = props.fiscalYears.find((candidate) => candidate.id === props.fiscalYearId);

    if (!year) {
        return Boolean(props.from || props.to);
    }

    return (props.from !== null && props.from !== year.startDate) || (props.to !== null && props.to !== year.endDate);
});

// Unsigned amount of a signed balance, through the money helper (the Dr/Cr side comes from the server).
const absoluteMoney = (balance) => formatMoney(balance).replace(/^-/, '');
const sideClass = (side) => (side === 'Cr' ? 'text-danger' : 'text-primary');

function apply() {
    router.get(
        '/reports/ledger',
        {
            fiscal_year_id: fiscalYearInput.value ?? undefined,
            account_id: accountIdInput.value ?? undefined,
            staff_id: staffIdInput.value ?? undefined,
            from: fromInput.value ?? undefined,
            to: toInput.value ?? undefined,
            include_sub_accounts: includeSubAccountsInput.value ? '1' : '0',
            project_id: projectInput.value || undefined,
            ...branchFilter.toQuery(),
        },
        { preserveState: true, preserveScroll: true },
    );
}

function clearWindow() {
    fromInput.value = null;
    toInput.value = null;
    apply();
}

function onAccountSelect(val) {
    staffIdInput.value = null;
    accountIdInput.value = val;
    apply();
}

function onFiscalYearChange(value) {
    const chosen = props.fiscalYears.find((year) => year.id === value);
    fiscalYearInput.value = value;
    fromInput.value = chosen?.startDate ?? null;
    toInput.value = chosen?.endDate ?? null;
    apply();
}

// Print / Export follow the applied filters (props), not unapplied edits.
function reportUrl(path) {
    const params = new URLSearchParams({
        fiscal_year_id: props.fiscalYearId ?? '',
        account_id: props.accountId ?? '',
        staff_id: props.staffId ?? '',
        from: props.from ?? '',
        to: props.to ?? '',
        include_sub_accounts: props.includeSubAccounts ? '1' : '0',
    });
    if (props.projectId) {
        params.set('project_id', String(props.projectId));
    }
    branchFilter.appendTo(params);
    return `${path}?${params.toString()}`;
}

const printUrl = () => reportUrl('/reports/ledger/print');
const exportUrl = () => reportUrl('/reports/ledger/export');
</script>

<template>
    <div>
        <PageHeader
            title="General Ledger"
            description="View sequential transaction history, debits, credits, and running balance for any General Ledger account or staff member."
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="ledger"
                hide-label
                @change="apply"
            />
            <template v-if="(account || staff) && fiscalYearId !== null">
                <a v-if="can('account_ledger.print')" :href="printUrl()" target="_blank" rel="noopener">
                    <Button variant="secondary" tone="purple">Print ledger</Button>
                </a>
                <a v-if="can('account_ledger.export')" :href="exportUrl()">
                    <Button variant="secondary" tone="purple">Export to Excel</Button>
                </a>
            </template>
        </PageHeader>

        <!-- Filters Card -->
        <Card variant="panel" class="mb-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div>
                    <Label class="mb-1">Select Account or Staff <span class="text-danger">*</span></Label>
                    <Combobox
                        :model-value="selectedTarget"
                        :options="targetOptions"
                        placeholder="Search account or staff name / code…"
                        @update:model-value="(v) => (selectedTarget = v)"
                    />
                </div>
                <div>
                    <Label class="mb-1">Fiscal Year</Label>
                    <Select :model-value="fiscalYearInput" :options="fiscalYearOptions" @update:model-value="onFiscalYearChange" />
                </div>
                <div>
                    <Label class="mb-1">From Date (BS)</Label>
                    <NepaliDateInput v-model="fromInput" />
                </div>
                <div>
                    <Label class="mb-1">To Date (BS)</Label>
                    <NepaliDateInput v-model="toInput" />
                </div>
                <div v-if="!staffIdInput">
                    <Label class="mb-1">Project</Label>
                    <Select v-model="projectInput" :options="projectOptions" />
                </div>
            </div>
            <p v-if="appliedProject && account" class="mt-2 text-[12px] text-text-muted">
                Project: {{ appliedProject.code }} - {{ appliedProject.name }}. Only lines posted to this project are
                included, so the opening balance is the project's own earlier postings.
            </p>
            <div class="mt-3 flex items-center justify-end gap-2 border-t border-border pt-3">
                <Button v-if="hasCustomWindow" variant="secondary" tone="purple" type="button" @click="clearWindow">Clear dates</Button>
                <Button variant="primary" type="button" @click="apply">Filter Ledger</Button>
            </div>
        </Card>

        <!-- Empty State when the company has no fiscal year yet -->
        <Card v-if="fiscalYearId === null" variant="panel" class="py-12 text-center">
            <p class="text-base font-medium text-text-strong">No fiscal year</p>
            <p class="mt-1 text-sm text-text-muted">No fiscal year has been created yet. Create one under Fiscal Years to see ledger activity.</p>
        </Card>

        <!-- Empty State when neither account nor staff chosen -->
        <Card v-else-if="!account && !staff" variant="panel" class="py-12 text-center">
            <p class="text-base font-medium text-text-strong">No account or staff selected</p>
            <p class="mt-1 text-sm text-text-muted">Pick an account or staff member from the search box above to view ledger entries.</p>
        </Card>

        <!-- Staff Member Ledger Details -->
        <StaffLedgerSection
            v-else-if="staff"
            :staff="staff"
            :staff-salary-entries="staffSalaryEntries"
            :staff-advance-entries="staffAdvanceEntries"
            :staff-opening-salary="staffOpeningSalary"
            :staff-opening-advance="staffOpeningAdvance"
            :staff-closing-salary="staffClosingSalary"
            :staff-closing-advance="staffClosingAdvance"
            :from="props.from"
        />

        <!-- Account Ledger Details -->
        <div v-else class="space-y-4">
            <!-- Sub-ledgers Info & Toggle Banner -->
            <Card v-if="subAccounts.length > 0" variant="panel" class="border-primary/20 bg-primary/5">
                <div class="flex flex-col gap-2.5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold uppercase tracking-wider text-primary">Sub-ledgers of {{ account.name }}:</span>
                            <span class="text-xs text-text-muted">({{ subAccounts.length }} child accounts)</span>
                        </div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                            <button
                                v-for="sub in subAccounts"
                                :key="sub.id"
                                type="button"
                                class="inline-flex items-center gap-1 rounded border border-primary/30 bg-white px-2 py-0.5 text-xs font-semibold text-primary shadow-xs transition-colors hover:bg-primary hover:text-white cursor-pointer"
                                @click="onAccountSelect(sub.id)"
                            >
                                <span>{{ sub.name }}</span>
                                <span v-if="sub.code" class="text-[10px] opacity-75">({{ sub.code }})</span>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 pt-1 sm:pt-0">
                        <input
                            id="include-sub-accounts"
                            v-model="includeSubAccountsInput"
                            type="checkbox"
                            class="size-4 rounded border-border text-primary focus:ring-primary cursor-pointer"
                            @change="apply"
                        />
                        <Label for="include-sub-accounts" class="cursor-pointer text-xs font-semibold text-text-strong">
                            Include transactions from sub-ledgers
                        </Label>
                    </div>
                </div>
            </Card>

            <div v-else-if="account.parent_account_id" class="flex items-center gap-2 rounded border border-border bg-bg-subtle px-3 py-2 text-xs text-text-muted">
                <span>↳ This account is a sub-ledger.</span>
                <button
                    type="button"
                    class="font-semibold text-primary hover:underline cursor-pointer"
                    @click="onAccountSelect(account.parent_account_id)"
                >
                    View parent ledger
                </button>
            </div>

            <!-- Account Summary Cards -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Card variant="panel" class="p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Account Name</p>
                    <p class="mt-1 text-sm font-bold text-text-strong truncate" :title="account.name">{{ account.name }}</p>
                    <p class="text-xs text-text-muted">{{ account.code || 'No code' }} &bull; {{ account.type }}</p>
                </Card>
                <Card variant="panel" class="p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Opening Balance</p>
                    <p class="mt-1 text-sm font-bold" :class="sideClass(openingSide)">
                        Rs. {{ absoluteMoney(openingBalance) }}
                        <span v-if="openingSide" class="text-xs font-semibold">{{ openingSide }}</span>
                    </p>
                    <p class="text-xs text-text-muted">At period start</p>
                </Card>
                <Card variant="panel" class="p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Period Debits / Credits</p>
                    <p class="mt-1 text-xs font-bold text-text-strong">
                        Dr: <span class="text-primary font-mono">Rs. {{ formatMoney(periodDebit) }}</span>
                    </p>
                    <p class="text-xs font-bold text-text-strong">
                        Cr: <span class="text-danger font-mono">Rs. {{ formatMoney(periodCredit) }}</span>
                    </p>
                </Card>
                <Card variant="panel" class="p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Closing Balance</p>
                    <p class="mt-1 text-base font-extrabold" :class="sideClass(closingSide)">
                        Rs. {{ absoluteMoney(closingBalance) }}
                        <span v-if="closingSide" class="text-xs font-bold">{{ closingSide }}</span>
                    </p>
                    <p class="text-xs text-text-muted">Current running balance</p>
                </Card>
            </div>

            <!-- Sub-ledger summary: one row per sub-ledger, ties to the ledger below with sub-ledgers included -->
            <Card v-if="subLedgerSummary.rows.length > 0" variant="panel">
                <h2 class="mb-2 text-[13px] font-bold text-text-strong">Sub-ledger summary</h2>
                <SubLedgerSummaryTable :summary="subLedgerSummary" @select="onAccountSelect" />
            </Card>

            <!-- Ledger Transactions Table (opening, lines, total, closing) -->
            <Card variant="panel">
                <LedgerEntriesTable
                    :entries="entries"
                    :opening-balance="openingBalance"
                    :opening-side="openingSide"
                    :closing-balance="closingBalance"
                    :closing-side="closingSide"
                    :period-debit="periodDebit"
                    :period-credit="periodCredit"
                    :from="props.from"
                />
            </Card>
        </div>
    </div>
</template>
