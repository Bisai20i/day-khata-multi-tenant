<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import { compareMoney, formatMoney, sumMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';
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
    openingBalance: { type: String, default: '0.00' },
    closingBalance: { type: String, default: '0.00' },
    entries: { type: Array, default: () => [] },
    // Sub-accounts belonging to the selected account, if any.
    subAccounts: { type: Array, default: () => [] },
    includeSubAccounts: { type: Boolean, default: true },
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

const fiscalYear = ref(props.fiscalYearId);
const accountId = ref(props.accountId);
const staffId = ref(props.staffId);
const from = ref(props.from);
const to = ref(props.to);
const includeSubAccounts = ref(props.includeSubAccounts);

const selectedTarget = computed({
    get: () => {
        if (staffId.value) return `staff-${staffId.value}`;
        if (accountId.value) return `acc-${accountId.value}`;
        return null;
    },
    set: (val) => {
        if (!val) {
            accountId.value = null;
            staffId.value = null;
        } else if (String(val).startsWith('staff-')) {
            accountId.value = null;
            staffId.value = Number(String(val).replace('staff-', ''));
        } else if (String(val).startsWith('acc-')) {
            staffId.value = null;
            accountId.value = Number(String(val).replace('acc-', ''));
        } else {
            staffId.value = null;
            accountId.value = Number(val);
        }
        apply();
    },
});

watch(
    () => props.includeSubAccounts,
    (v) => (includeSubAccounts.value = v),
);

watch(
    () => props.accountId,
    (v) => (accountId.value = v),
);

watch(
    () => props.staffId,
    (v) => (staffId.value = v),
);

const branchFilter = useBranchReportFilter(() => props.branchReport);

// Sum of debits and credits in the period (exact decimal strings, never floats)
const totalPeriodDebit = computed(() => sumMoney(props.entries.map((e) => e.debit || '0.00')));
const totalPeriodCredit = computed(() => sumMoney(props.entries.map((e) => e.credit || '0.00')));

const staffTotalSalaryDebit = computed(() => sumMoney(props.staffSalaryEntries.map((e) => e.debit || '0.00')));
const staffTotalSalaryCredit = computed(() => sumMoney(props.staffSalaryEntries.map((e) => e.credit || '0.00')));
const staffTotalAdvanceDebit = computed(() => sumMoney(props.staffAdvanceEntries.map((e) => e.debit || '0.00')));
const staffTotalAdvanceCredit = computed(() => sumMoney(props.staffAdvanceEntries.map((e) => e.credit || '0.00')));

// Dr/Cr side and unsigned amount of a signed balance, through the money helpers.
const isDebitBalance = (balance) => compareMoney(balance, '0.00') >= 0;
const absoluteMoney = (balance) => formatMoney(balance).replace(/^-/, '');

function apply() {
    router.get(
        '/reports/ledger',
        {
            fiscal_year_id: fiscalYear.value ?? undefined,
            account_id: accountId.value ?? undefined,
            staff_id: staffId.value ?? undefined,
            from: from.value ?? undefined,
            to: to.value ?? undefined,
            include_sub_accounts: includeSubAccounts.value ? '1' : '0',
            ...branchFilter.toQuery(),
        },
        { preserveState: true, preserveScroll: true },
    );
}

function clearWindow() {
    from.value = null;
    to.value = null;
    apply();
}

function onAccountSelect(val) {
    staffId.value = null;
    accountId.value = val;
    apply();
}

watch(fiscalYear, (value) => {
    const chosen = props.fiscalYears.find((year) => year.id === value);
    from.value = chosen?.startDate ?? null;
    to.value = chosen?.endDate ?? null;
    apply();
});

function printUrl() {
    const params = new URLSearchParams({
        fiscal_year_id: fiscalYear.value ?? '',
        account_id: accountId.value ?? '',
        staff_id: staffId.value ?? '',
        from: from.value ?? '',
        to: to.value ?? '',
        include_sub_accounts: includeSubAccounts.value ? '1' : '0',
    });
    branchFilter.appendTo(params);
    return `/reports/ledger/print?${params.toString()}`;
}

function exportUrl() {
    const params = new URLSearchParams({
        fiscal_year_id: fiscalYear.value ?? '',
        account_id: accountId.value ?? '',
        staff_id: staffId.value ?? '',
        from: from.value ?? '',
        to: to.value ?? '',
        include_sub_accounts: includeSubAccounts.value ? '1' : '0',
    });
    branchFilter.appendTo(params);
    return `/reports/ledger/export?${params.toString()}`;
}

const voucherTypeLabels = {
    sale: 'Sale Invoice',
    purchase: 'Purchase Bill',
    'sales-return': 'Sales Return',
    'purchase-return': 'Purchase Return',
    receipt: 'Receipt',
    payment: 'Payment',
    journal: 'Journal Voucher',
    'opening-balance': 'Opening Balance',
};

function voucherLabel(entry) {
    const type = voucherTypeLabels[entry.voucherType] ?? entry.voucherType;
    return `${type} #${entry.voucherNumber}`;
}

const columns = [
    {
        id: 'dateBs',
        header: 'Date (BS)',
        numeric: false,
        cell: ({ row }) => formatBsDate(row.original.date) || '-',
    },
    { accessorKey: 'date', header: 'Date (AD)' },
    {
        id: 'voucher',
        header: 'Voucher',
        numeric: false,
        cell: ({ row }) => voucherLabel(row.original),
    },
    {
        accessorKey: 'narration',
        header: 'Narration / Particulars',
        numeric: false,
        cell: ({ row }) => row.original.narration ?? '-',
    },
    {
        accessorKey: 'debit',
        header: 'Debit (Rs.)',
        cell: ({ row }) => (compareMoney(row.original.debit || '0.00', '0.00') > 0 ? formatMoney(row.original.debit) : '-'),
    },
    {
        accessorKey: 'credit',
        header: 'Credit (Rs.)',
        cell: ({ row }) => (compareMoney(row.original.credit || '0.00', '0.00') > 0 ? formatMoney(row.original.credit) : '-'),
    },
    {
        accessorKey: 'balance',
        header: 'Running Balance (Rs.)',
        cell: ({ row }) => {
            const sign = compareMoney(row.original.balance, '0.00');
            if (sign > 0) return `${formatMoney(row.original.balance)} Dr`;
            if (sign < 0) return `${absoluteMoney(row.original.balance)} Cr`;
            return '0.00';
        },
    },
];

const staffTableHeadClass = 'px-3 py-2 text-[11px] font-bold tracking-wider text-text-muted uppercase whitespace-nowrap bg-bg-subtle';
const staffTableCellClass = 'border-t border-border px-3 py-2.5 text-[12.5px] align-middle';
const staffTableNumClass = `${staffTableCellClass} text-right whitespace-nowrap [font-variant-numeric:tabular-nums]`;
const staffTableTotalClass = 'border-t-2 border-border bg-primary-tint/30 px-3 py-2 text-xs font-bold text-text-strong';
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
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
                    <Select :model-value="fiscalYear" :options="fiscalYearOptions" @update:model-value="(v) => (fiscalYear = v)" />
                </div>
                <div>
                    <Label class="mb-1">From Date (BS)</Label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <Label class="mb-1">To Date (BS)</Label>
                    <NepaliDateInput v-model="to" />
                </div>
            </div>
            <div class="mt-3 flex items-center justify-end gap-2 border-t border-border pt-3">
                <Button v-if="from || to" variant="secondary" tone="purple" type="button" @click="clearWindow">Clear dates</Button>
                <Button variant="primary" type="button" @click="apply">Filter Ledger</Button>
            </div>
        </Card>

        <!-- Empty State when neither account nor staff chosen -->
        <Card v-if="!account && !staff" variant="panel" class="py-12 text-center">
            <p class="text-base font-medium text-text-strong">No account or staff selected</p>
            <p class="mt-1 text-sm text-text-muted">Pick an account or staff member from the search box above to view ledger entries.</p>
        </Card>

        <!-- Staff Member Ledger Details -->
        <div v-else-if="staff" class="space-y-4">
            <!-- Staff Summary Banner -->
            <Card variant="panel" class="border-primary/20 bg-primary/5 p-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-primary px-2 py-0.5 text-xs font-bold text-white uppercase tracking-wider">Staff</span>
                            <h2 class="text-base font-bold text-text-strong">{{ staff.name }}</h2>
                            <span class="text-xs text-text-muted">({{ staff.staff_code }})</span>
                        </div>
                        <p v-if="staff.designation || staff.department" class="mt-1 text-xs text-text-muted">
                            {{ [staff.designation, staff.department].filter(Boolean).join(' • ') }}
                        </p>
                    </div>

                    <div v-if="can('hr_reports.view')" class="flex items-center gap-3">
                        <Button :as="Link" :href="`/hr/staff/${staff.id}/statement`" variant="secondary" tone="purple" class="text-xs">
                            Open Full HR Statement &rarr;
                        </Button>
                    </div>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-3 border-t border-primary/10 pt-3 sm:grid-cols-4">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Salary Payable</p>
                        <p class="mt-0.5 text-sm font-bold text-text-strong">Rs. {{ formatMoney(staffClosingSalary) }}</p>
                        <p class="text-[11px] text-text-muted">Business owes staff</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Staff Advance</p>
                        <p class="mt-0.5 text-sm font-bold text-text-strong">Rs. {{ formatMoney(staffClosingAdvance) }}</p>
                        <p class="text-[11px] text-text-muted">Staff owes business</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Period Salary Paid</p>
                        <p class="mt-0.5 text-xs font-semibold text-primary">Rs. {{ formatMoney(staffTotalSalaryDebit) }}</p>
                        <p class="text-[11px] text-text-muted">Earned: Rs. {{ formatMoney(staffTotalSalaryCredit) }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Period Advances Given</p>
                        <p class="mt-0.5 text-xs font-semibold text-danger">Rs. {{ formatMoney(staffTotalAdvanceDebit) }}</p>
                        <p class="text-[11px] text-text-muted">Recovered: Rs. {{ formatMoney(staffTotalAdvanceCredit) }}</p>
                    </div>
                </div>
            </Card>

            <!-- 1. Salary Payable Ledger -->
            <Card variant="panel" class="overflow-hidden">
                <div class="border-b border-border bg-bg-subtle/50 px-4 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-bold text-text-strong">Salary Payable Ledger</h3>
                            <p class="text-xs text-text-muted">Credit is net salary earned; debit is salary paid. Balance is the amount currently payable.</p>
                        </div>
                        <span class="text-xs font-semibold text-text-muted">
                            Closing Balance: <span class="font-bold text-text-strong">Rs. {{ formatMoney(staffClosingSalary) }}</span>
                        </span>
                    </div>
                </div>

                <div class="w-full overflow-x-auto">
                    <table class="w-full border-collapse text-[12.5px] text-text-base">
                        <thead>
                            <tr>
                                <th scope="col" :class="[staffTableHeadClass, 'text-left']">Date (BS)</th>
                                <th scope="col" :class="[staffTableHeadClass, 'text-left']">Date (AD)</th>
                                <th scope="col" :class="[staffTableHeadClass, 'text-left']">Reference</th>
                                <th scope="col" :class="[staffTableHeadClass, 'text-left']">Particulars / Description</th>
                                <th scope="col" :class="[staffTableHeadClass, 'text-right']">Debit (Rs.)</th>
                                <th scope="col" :class="[staffTableHeadClass, 'text-right']">Credit (Rs.)</th>
                                <th scope="col" :class="[staffTableHeadClass, 'text-right']">Balance (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="from" class="bg-primary-tint/20">
                                <td colspan="6" class="border-t border-border px-3 py-2 text-xs font-semibold text-text-strong">
                                    Opening balance <span class="font-normal text-text-muted">(before {{ formatBsDate(from) }} / {{ from }})</span>
                                </td>
                                <td class="border-t border-border px-3 py-2 text-right text-xs font-bold text-text-strong whitespace-nowrap">
                                    Rs. {{ formatMoney(staffOpeningSalary) }}
                                </td>
                            </tr>
                            <tr v-if="staffSalaryEntries.length === 0">
                                <td colspan="7" class="px-4 py-6 text-center text-xs font-medium text-text-muted">
                                    No salary entries found in this period.
                                </td>
                            </tr>
                            <tr v-for="(entry, index) in staffSalaryEntries" :key="`salary-${index}`" class="hover:bg-bg-subtle/50 transition-colors">
                                <td :class="[staffTableCellClass, 'whitespace-nowrap font-medium text-text-strong']">
                                    {{ entry.date_bs ? formatBsDate(entry.date_bs) : formatBsDate(entry.date) }}
                                </td>
                                <td :class="[staffTableCellClass, 'whitespace-nowrap text-text-muted']">{{ entry.date }}</td>
                                <td :class="[staffTableCellClass, 'whitespace-nowrap font-medium']">{{ entry.reference ?? '-' }}</td>
                                <td :class="staffTableCellClass">{{ entry.description }}</td>
                                <td :class="staffTableNumClass">
                                    {{ compareMoney(entry.debit, '0.00') > 0 ? formatMoney(entry.debit) : '-' }}
                                </td>
                                <td :class="staffTableNumClass">
                                    {{ compareMoney(entry.credit, '0.00') > 0 ? formatMoney(entry.credit) : '-' }}
                                </td>
                                <td :class="[staffTableNumClass, 'font-bold text-text-strong']">
                                    Rs. {{ formatMoney(entry.balance) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" :class="staffTableTotalClass">Closing Balance (Salary Payable)</td>
                                <td :class="[staffTableTotalClass, 'text-right whitespace-nowrap']">
                                    Rs. {{ formatMoney(staffClosingSalary) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </Card>

            <!-- 2. Staff Advance Ledger -->
            <Card variant="panel" class="overflow-hidden">
                <div class="border-b border-border bg-bg-subtle/50 px-4 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-bold text-text-strong">Staff Advance Ledger</h3>
                            <p class="text-xs text-text-muted">Debit is advance given; credit is advance recovered in payroll. Balance is the amount to recover.</p>
                        </div>
                        <span class="text-xs font-semibold text-text-muted">
                            Closing Balance: <span class="font-bold text-text-strong">Rs. {{ formatMoney(staffClosingAdvance) }}</span>
                        </span>
                    </div>
                </div>

                <div class="w-full overflow-x-auto">
                    <table class="w-full border-collapse text-[12.5px] text-text-base">
                        <thead>
                            <tr>
                                <th scope="col" :class="[staffTableHeadClass, 'text-left']">Date (BS)</th>
                                <th scope="col" :class="[staffTableHeadClass, 'text-left']">Date (AD)</th>
                                <th scope="col" :class="[staffTableHeadClass, 'text-left']">Reference</th>
                                <th scope="col" :class="[staffTableHeadClass, 'text-left']">Particulars / Description</th>
                                <th scope="col" :class="[staffTableHeadClass, 'text-right']">Debit (Rs.)</th>
                                <th scope="col" :class="[staffTableHeadClass, 'text-right']">Credit (Rs.)</th>
                                <th scope="col" :class="[staffTableHeadClass, 'text-right']">Balance (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="from" class="bg-primary-tint/20">
                                <td colspan="6" class="border-t border-border px-3 py-2 text-xs font-semibold text-text-strong">
                                    Opening balance <span class="font-normal text-text-muted">(before {{ formatBsDate(from) }} / {{ from }})</span>
                                </td>
                                <td class="border-t border-border px-3 py-2 text-right text-xs font-bold text-text-strong whitespace-nowrap">
                                    Rs. {{ formatMoney(staffOpeningAdvance) }}
                                </td>
                            </tr>
                            <tr v-if="staffAdvanceEntries.length === 0">
                                <td colspan="7" class="px-4 py-6 text-center text-xs font-medium text-text-muted">
                                    No advance entries found in this period.
                                </td>
                            </tr>
                            <tr v-for="(entry, index) in staffAdvanceEntries" :key="`adv-${index}`" class="hover:bg-bg-subtle/50 transition-colors">
                                <td :class="[staffTableCellClass, 'whitespace-nowrap font-medium text-text-strong']">
                                    {{ entry.date_bs ? formatBsDate(entry.date_bs) : formatBsDate(entry.date) }}
                                </td>
                                <td :class="[staffTableCellClass, 'whitespace-nowrap text-text-muted']">{{ entry.date }}</td>
                                <td :class="[staffTableCellClass, 'whitespace-nowrap font-medium']">{{ entry.reference ?? '-' }}</td>
                                <td :class="staffTableCellClass">{{ entry.description }}</td>
                                <td :class="staffTableNumClass">
                                    {{ compareMoney(entry.debit, '0.00') > 0 ? formatMoney(entry.debit) : '-' }}
                                </td>
                                <td :class="staffTableNumClass">
                                    {{ compareMoney(entry.credit, '0.00') > 0 ? formatMoney(entry.credit) : '-' }}
                                </td>
                                <td :class="[staffTableNumClass, 'font-bold text-text-strong']">
                                    Rs. {{ formatMoney(entry.balance) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" :class="staffTableTotalClass">Closing Balance (Staff Advance)</td>
                                <td :class="[staffTableTotalClass, 'text-right whitespace-nowrap']">
                                    Rs. {{ formatMoney(staffClosingAdvance) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </Card>
        </div>

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
                            v-model="includeSubAccounts"
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

            <!-- Account Summary Card -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Card variant="panel" class="p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Account Name</p>
                    <p class="mt-1 text-sm font-bold text-text-strong truncate" :title="account.name">{{ account.name }}</p>
                    <p class="text-xs text-text-muted">{{ account.code || 'No code' }} &bull; {{ account.type }}</p>
                </Card>
                <Card variant="panel" class="p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Opening Balance</p>
                    <p class="mt-1 text-sm font-bold" :class="isDebitBalance(openingBalance) ? 'text-primary' : 'text-danger'">
                        Rs. {{ absoluteMoney(openingBalance) }}
                        <span class="text-xs font-semibold">{{ isDebitBalance(openingBalance) ? 'Dr' : 'Cr' }}</span>
                    </p>
                    <p class="text-xs text-text-muted">At period start</p>
                </Card>
                <Card variant="panel" class="p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Period Debits / Credits</p>
                    <p class="mt-1 text-xs font-bold text-text-strong">
                        Dr: <span class="text-primary font-mono">Rs. {{ formatMoney(totalPeriodDebit) }}</span>
                    </p>
                    <p class="text-xs font-bold text-text-strong">
                        Cr: <span class="text-danger font-mono">Rs. {{ formatMoney(totalPeriodCredit) }}</span>
                    </p>
                </Card>
                <Card variant="panel" class="p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Closing Balance</p>
                    <p class="mt-1 text-base font-extrabold" :class="isDebitBalance(closingBalance) ? 'text-primary' : 'text-danger'">
                        Rs. {{ absoluteMoney(closingBalance) }}
                        <span class="text-xs font-bold">{{ isDebitBalance(closingBalance) ? 'Dr' : 'Cr' }}</span>
                    </p>
                    <p class="text-xs text-text-muted">Current running balance</p>
                </Card>
            </div>

            <!-- Ledger Transactions Table -->
            <Card variant="panel">
                <DataTable :columns="columns" :data="entries" empty-text="No transactions found for this account in the selected date range." />
            </Card>
        </div>
    </div>
</template>
