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
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import { formatMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const { can } = usePermissions();

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    accounts: { type: Array, default: () => [] },
    accountId: { type: [Number, null], default: null },
    account: { type: [Object, null], default: null },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    openingBalance: { type: String, default: '0.00' },
    closingBalance: { type: String, default: '0.00' },
    entries: { type: Array, default: () => [] },
});

useLayoutChrome('Account Ledger');

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

const accountOptions = computed(() =>
    props.accounts.map((acc) => ({
        value: acc.id,
        label: acc.code ? `${acc.name} (${acc.code})` : acc.name,
        searchValue: acc.code ? `${acc.name} ${acc.code} ${acc.group ?? ''}` : acc.name,
    })),
);

const fiscalYear = ref(props.fiscalYearId);
const accountId = ref(props.accountId);
const from = ref(props.from);
const to = ref(props.to);

// Sum of debits and credits in the period
const totalPeriodDebit = computed(() => {
    return props.entries.reduce((sum, e) => sum + Number(e.debit || 0), 0).toFixed(2);
});

const totalPeriodCredit = computed(() => {
    return props.entries.reduce((sum, e) => sum + Number(e.credit || 0), 0).toFixed(2);
});

function apply() {
    router.get(
        '/reports/ledger',
        {
            fiscal_year_id: fiscalYear.value ?? undefined,
            account_id: accountId.value ?? undefined,
            from: from.value ?? undefined,
            to: to.value ?? undefined,
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
        from: from.value ?? '',
        to: to.value ?? '',
    });
    return `/reports/ledger/print?${params.toString()}`;
}

function exportUrl() {
    const params = new URLSearchParams({
        fiscal_year_id: fiscalYear.value ?? '',
        account_id: accountId.value ?? '',
        from: from.value ?? '',
        to: to.value ?? '',
    });
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
        cell: ({ row }) => (Number(row.original.debit) > 0 ? formatMoney(row.original.debit) : '-'),
    },
    {
        accessorKey: 'credit',
        header: 'Credit (Rs.)',
        cell: ({ row }) => (Number(row.original.credit) > 0 ? formatMoney(row.original.credit) : '-'),
    },
    {
        accessorKey: 'balance',
        header: 'Running Balance (Rs.)',
        cell: ({ row }) => {
            const num = Number(row.original.balance);
            if (num > 0) return `${formatMoney(row.original.balance)} Dr`;
            if (num < 0) return `${formatMoney(Math.abs(num).toFixed(2))} Cr`;
            return '0.00';
        },
    },
];
</script>

<template>
    <div>
        <PageHeader
            title="General Ledger"
            description="View sequential transaction history, debits, credits, and running balance for any General Ledger account."
        >
            <template v-if="account && fiscalYearId !== null">
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
                    <Label class="mb-1">Select Account <span class="text-danger">*</span></Label>
                    <Combobox
                        :model-value="accountId"
                        :options="accountOptions"
                        placeholder="Search account name or code…"
                        @update:model-value="onAccountSelect"
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

        <!-- Empty State when no account chosen -->
        <Card v-if="!account" variant="panel" class="py-12 text-center">
            <p class="text-base font-medium text-text-strong">No account selected</p>
            <p class="mt-1 text-sm text-text-muted">Pick an account from the dropdown above to view its general ledger entries.</p>
        </Card>

        <!-- Account Ledger Details -->
        <div v-else class="space-y-4">
            <!-- Account Summary Card -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <Card variant="panel" class="p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Account Name</p>
                    <p class="mt-1 text-sm font-bold text-text-strong truncate" :title="account.name">{{ account.name }}</p>
                    <p class="text-xs text-text-muted">{{ account.code || 'No code' }} &bull; {{ account.type }}</p>
                </Card>
                <Card variant="panel" class="p-3">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-text-muted">Opening Balance</p>
                    <p class="mt-1 text-sm font-bold" :class="Number(openingBalance) >= 0 ? 'text-primary' : 'text-danger'">
                        Rs. {{ formatMoney(Math.abs(Number(openingBalance)).toFixed(2)) }}
                        <span class="text-xs font-semibold">{{ Number(openingBalance) >= 0 ? 'Dr' : 'Cr' }}</span>
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
                    <p class="mt-1 text-base font-extrabold" :class="Number(closingBalance) >= 0 ? 'text-primary' : 'text-danger'">
                        Rs. {{ formatMoney(Math.abs(Number(closingBalance)).toFixed(2)) }}
                        <span class="text-xs font-bold">{{ Number(closingBalance) >= 0 ? 'Dr' : 'Cr' }}</span>
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
