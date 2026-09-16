<script setup>
import { computed, h, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    account: {
        type: Object,
        required: true,
    },
    fiscalYears: {
        type: Array,
        default: () => [],
    },
    fiscalYearId: {
        type: [Number, null],
        default: null,
    },
    from: {
        type: [String, null],
        default: null,
    },
    to: {
        type: [String, null],
        default: null,
    },
    openingBalance: {
        type: String,
        default: '0.00',
    },
    closingBalance: {
        type: String,
        default: '0.00',
    },
    entries: {
        type: Array,
        default: () => [],
    },
});

useLayoutChrome(() => `Ledger — ${props.account.name}`);

// An arbitrary date window inside the chosen fiscal year (T14 task 3) - the
// backend clamps whatever is sent here to the year's own start/end and
// recomputes the opening balance for the window (never just resets it to
// zero), so a mid-year "From" still opens at the right running total.
const from = ref(props.from);
const to = ref(props.to);

function applyWindow() {
    router.get(
        window.location.pathname,
        { fiscal_year_id: props.fiscalYearId, from: from.value ?? undefined, to: to.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

function printUrl() {
    const params = new URLSearchParams({
        fiscal_year_id: props.fiscalYearId ?? '',
        from: from.value ?? '',
        to: to.value ?? '',
    });
    return `${window.location.pathname}/print?${params.toString()}`;
}

function exportUrl() {
    const params = new URLSearchParams({
        fiscal_year_id: props.fiscalYearId ?? '',
        from: from.value ?? '',
        to: to.value ?? '',
    });
    return `${window.location.pathname}/export?${params.toString()}`;
}

// Only the types a manual ledger reader is likely to meet need a friendly
// label; anything else falls back to its own value, so a new VoucherType never
// renders as a blank cell.
const voucherTypeLabels = {
    opening_balance: 'Opening Balance',
    journal: 'Journal',
    closing_entry: 'Closing Entry',
    roll_forward_adjustment: 'Roll Forward Adjustment',
    reversal: 'Reversal',
    sale: 'Sale',
    sale_abbreviated: 'Sale (Abbreviated)',
    sale_pan: 'Sale (PAN)',
    sale_return: 'Sales Return',
    purchase: 'Purchase',
    purchase_return: 'Purchase Return',
    receipt: 'Receipt',
    payment: 'Payment',
};

function voucherLabel(entry) {
    const type = voucherTypeLabels[entry.voucherType] ?? entry.voucherType;
    return `${type} #${entry.voucherNumber}`;
}

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

function onFiscalYearChange(value) {
    // A new fiscal year has its own start/end, so any window chosen for the
    // previous one is dropped rather than sent along and silently clamped.
    from.value = null;
    to.value = null;
    router.get(
        window.location.pathname,
        { fiscal_year_id: value },
        { preserveState: true, preserveScroll: true },
    );
}

// Amounts arrive from the server as exact decimal strings (the running
// balance is accumulated with App\Support\Money\Money there, so it never
// drifts and never renders "-0.00"); the page only formats them.
const columns = [
    {
        id: 'dateBs',
        header: 'Date (BS)',
        numeric: false,
        cell: ({ row }) => formatBsDate(row.original.date) || '—',
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
        header: 'Narration',
        numeric: false,
        cell: ({ row }) => row.original.narration ?? '—',
    },
    {
        accessorKey: 'debit',
        header: 'Debit',
        cell: ({ row }) => formatMoney(row.original.debit),
    },
    {
        accessorKey: 'credit',
        header: 'Credit',
        cell: ({ row }) => formatMoney(row.original.credit),
    },
    {
        accessorKey: 'balance',
        header: 'Running Balance',
        cell: ({ row }) => formatMoney(row.original.balance),
    },
    {
        id: 'document',
        header: 'Source Document',
        numeric: false,
        cell: ({ row }) =>
            row.original.document
                ? row.original.document.url
                    ? h('a', { href: row.original.document.url, target: '_blank', rel: 'noopener', class: 'text-primary underline' }, row.original.document.label)
                    : row.original.document.label
                : '—',
    },
];
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-bold text-text-strong">
                    {{ account.name }} <span class="font-normal text-text-muted">· {{ account.code ?? '—' }}</span>
                </h2>
            </div>
            <div v-if="fiscalYearId !== null" class="flex flex-wrap items-end gap-2">
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Fiscal Year</label>
                    <Select :model-value="fiscalYearId" :options="fiscalYearOptions" @update:model-value="onFiscalYearChange" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <Button variant="primary" tone="purple" @click="applyWindow">Apply</Button>
                <a :href="printUrl()" target="_blank" rel="noopener"><Button variant="secondary" tone="purple">Print</Button></a>
                <a :href="exportUrl()"><Button variant="secondary" tone="purple">Export</Button></a>
            </div>
        </div>

        <Card v-if="fiscalYearId !== null" variant="panel" class="mb-4">
            <div class="flex flex-wrap justify-end gap-6 text-[12.5px]">
                <div><span class="text-text-muted">Opening Balance:</span> <span class="font-semibold">{{ formatMoney(openingBalance) }}</span></div>
                <div><span class="text-text-muted">Closing Balance:</span> <span class="font-semibold">{{ formatMoney(closingBalance) }}</span></div>
            </div>
        </Card>

        <Card variant="panel">
            <p v-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No fiscal year has been created yet.
            </p>
            <DataTable v-else :columns="columns" :data="entries" :page-size="25" empty-message="No activity in this fiscal year" />
        </Card>
    </div>
</template>
