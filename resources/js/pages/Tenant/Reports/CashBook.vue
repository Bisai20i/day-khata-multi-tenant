<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { navGroups } from '@/lib/nav-items.js';
import { formatMoney, isZeroMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    account: { type: Object, required: true },
    entries: { type: Array, default: () => [] },
    openingBalance: { type: String, default: '0.00' },
    closingBalance: { type: String, default: '0.00' },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

const fiscalYear = ref(props.fiscalYearId);
const from = ref(props.from);
const to = ref(props.to);

// A cash book is only ever read inside one fiscal year: summing across a
// boundary counted the new year's Opening Balance voucher on top of the old
// year's own lines and doubled the opening balance (audit P0-18).
watch(fiscalYear, (value) => {
    const chosen = props.fiscalYears.find((year) => year.id === value);
    from.value = chosen?.startDate ?? null;
    to.value = chosen?.endDate ?? null;
    applyFilter();
});

function applyFilter() {
    router.get(
        window.location.pathname,
        { fiscal_year_id: fiscalYear.value ?? undefined, from: from.value ?? undefined, to: to.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

// Kept per page rather than shared, matching this app's existing
// per-page-file convention (mem.md gotcha #5).
const voucherTypeLabels = {
    opening_balance: 'Opening Balance',
    journal: 'Journal',
    closing_entry: 'Closing Entry',
    roll_forward_adjustment: 'Roll Forward Adjustment',
    reversal: 'Reversal',
    sale: 'Sale',
    sale_abbreviated: 'Sale (Abbreviated)',
    sale_pan: 'Sale (PAN)',
    sale_return: 'Sale Return',
    purchase: 'Purchase',
    purchase_return: 'Purchase Return',
    capital_sale: 'Capital Sale',
    capital_purchase: 'Capital Purchase',
    fixed_asset_purchase: 'Fixed Asset Purchase',
    depreciation: 'Depreciation',
    asset_disposal: 'Asset Disposal',
    receipt: 'Receipt',
    payment: 'Payment',
};

function voucherLabel(entry) {
    return `${voucherTypeLabels[entry.voucherType] ?? entry.voucherType} #${entry.voucherNumber}`;
}

const columns = [
    { accessorKey: 'date', header: 'Date', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { id: 'voucher', header: 'Voucher', numeric: false, cell: ({ row }) => voucherLabel(row.original) },
    { accessorKey: 'narration', header: 'Narration', numeric: false, cell: ({ row }) => row.original.narration ?? '—' },
    { accessorKey: 'debit', header: 'Debit', cell: ({ row }) => (isZeroMoney(row.original.debit) ? '—' : formatMoney(row.original.debit)) },
    { accessorKey: 'credit', header: 'Credit', cell: ({ row }) => (isZeroMoney(row.original.credit) ? '—' : formatMoney(row.original.credit)) },
    { accessorKey: 'balance', header: 'Balance', cell: ({ row }) => formatMoney(row.original.balance) },
];
</script>

<template>
    <AppLayout title="Cash Book" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">
                Cash Book <span class="font-normal text-text-muted">· {{ account.name }} ({{ account.code ?? '—' }})</span>
            </h2>
        </div>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Fiscal Year</label>
                    <Select v-model="fiscalYear" :options="fiscalYearOptions" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel">
            <p v-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No fiscal year has been created yet.
            </p>

            <template v-else>
                <div class="mb-3 flex items-center justify-between border-b-[1.5px] border-border pb-3 text-[12.5px]">
                    <div><span class="text-text-muted">Opening Balance:</span> <span class="font-semibold">{{ formatMoney(openingBalance) }}</span></div>
                    <div><span class="text-text-muted">Closing Balance:</span> <span class="font-semibold">{{ formatMoney(closingBalance) }}</span></div>
                </div>

                <DataTable :columns="columns" :data="entries" :page-size="25" empty-message="No cash activity in this range" />
            </template>
        </Card>
    </AppLayout>
</template>
