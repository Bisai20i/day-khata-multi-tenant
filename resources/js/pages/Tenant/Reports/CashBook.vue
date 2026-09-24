<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { formatMoney, isZeroMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

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

useLayoutChrome('Cash Book');

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
    cash_receipt: 'Cash Receipt',
    cash_payment: 'Cash Payment',
    bank_receipt: 'Bank Receipt',
    bank_payment: 'Bank Payment',
    contra: 'Contra',
};

function voucherLabel(entry) {
    return `${voucherTypeLabels[entry.voucherType] ?? entry.voucherType} #${entry.voucherNumber}`;
}

function printUrl() {
    const params = new URLSearchParams({ fiscal_year_id: fiscalYear.value ?? '', from: from.value ?? '', to: to.value ?? '' });
    return `/reports/cash-book/print?${params.toString()}`;
}

function exportUrl() {
    const params = new URLSearchParams({ fiscal_year_id: fiscalYear.value ?? '', from: from.value ?? '', to: to.value ?? '' });
    return `/reports/cash-book/export?${params.toString()}`;
}

const columns = [
    { accessorKey: 'date', header: 'Date', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { id: 'voucher', header: 'Voucher', numeric: false, cell: ({ row }) => voucherLabel(row.original) },
    { accessorKey: 'narration', header: 'Narration', numeric: false, cell: ({ row }) => row.original.narration ?? '-' },
    { accessorKey: 'debit', header: 'Debit (Dr)', cell: ({ row }) => (isZeroMoney(row.original.debit) ? '-' : formatMoney(row.original.debit)) },
    { accessorKey: 'credit', header: 'Credit (Cr)', cell: ({ row }) => (isZeroMoney(row.original.credit) ? '-' : formatMoney(row.original.credit)) },
    { accessorKey: 'balance', header: 'Balance', cell: ({ row }) => formatMoney(row.original.balance) },
];
</script>

<template>
    <div>
        <PageHeader
            :title="`Cash Book - ${account.name} (${account.code ?? '-'})`"
            description="Every cash receipt and payment in the period, with the running cash balance."
        >
            <a :href="printUrl()" target="_blank" rel="noopener"><Button variant="secondary" tone="purple">Print</Button></a>
            <a :href="exportUrl()"><Button variant="secondary" tone="purple">Export</Button></a>
        </PageHeader>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Fiscal year</label>
                    <Select v-model="fiscalYear" :options="fiscalYearOptions" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From date (BS)</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To date (BS)</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Generate report</Button>
            </div>
            <p v-if="from && to" class="mt-2 text-[12px] text-text-muted">
                Showing report for {{ formatBsDate(from) }} to {{ formatBsDate(to) }} BS
            </p>
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

                <DataTable :columns="columns" :data="entries" :page-size="25" empty-message="No transactions in this period. Try widening the date range." />
            </template>
        </Card>
    </div>
</template>
