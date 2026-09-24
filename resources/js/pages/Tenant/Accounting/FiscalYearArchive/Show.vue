<script setup>
import { h } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Archive } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Badge from '@/components/ui/Badge.vue';
import { formatMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    fiscalYear: { type: Object, required: true },
    archive: { type: Object, required: true },
    vouchers: { type: Array, default: () => [] },
});

useLayoutChrome(() => `Archived: ${props.fiscalYear.name}`);

const voucherTypeLabels = {
    opening_balance: 'Opening Balance',
    journal: 'Journal',
    closing_entry: 'Closing Entry',
    roll_forward_adjustment: 'Roll Forward Adjustment',
    sale: 'Sale',
    sale_abbreviated: 'Sale (Abbreviated)',
    sale_return: 'Sale Return',
    purchase: 'Purchase',
    purchase_return: 'Purchase Return',
    fixed_asset_purchase: 'Fixed Asset Purchase',
    depreciation: 'Depreciation',
    asset_disposal: 'Asset Disposal',
    receipt: 'Receipt',
    payment: 'Payment',
};

function voucherTypeLabel(type) {
    return voucherTypeLabels[type] ?? type;
}

function money(value) {
    return formatMoney(value ?? '0.00');
}

function voucherHref(voucherId) {
    return `/fiscal-year-archives/${props.archive.id}/vouchers/${voucherId}`;
}

const columns = [
    { accessorKey: 'date', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    {
        id: 'voucher',
        header: 'Voucher',
        numeric: false,
        cell: ({ row }) =>
            h(
                Link,
                { href: voucherHref(row.original.id), class: 'font-semibold text-primary hover:underline' },
                { default: () => `${voucherTypeLabel(row.original.voucherType)} #${row.original.voucherNumber}` },
            ),
    },
    { accessorKey: 'narration', header: 'Narration' },
    { accessorKey: 'createdByName', header: 'Created by' },
    {
        id: 'totalDebit',
        header: 'Debit',
        numeric: true,
        cell: ({ row }) => money(row.original.totalDebit),
    },
    {
        id: 'totalCredit',
        header: 'Credit',
        numeric: true,
        cell: ({ row }) => money(row.original.totalCredit),
    },
];
</script>

<template>
    <div>
        <PageHeader
            :title="`Archived year: ${fiscalYear.name}`"
            :description="`${fiscalYear.bsLabel} · ${formatBsDate(fiscalYear.startDate)} to ${formatBsDate(fiscalYear.endDate)} (BS). A read-only copy of this closed year's vouchers.`"
            back-href="/fiscal-years"
            back-label="Back to fiscal years"
        >
            <Badge variant="neutral" pill>
                <Archive class="h-3 w-3" aria-hidden="true" />
                Archived snapshot
            </Badge>
        </PageHeader>

        <Card variant="panel" class="mb-4">
            <p class="mb-3 text-[13px] text-text-muted">
                This fiscal year has been copied out to cold storage and is shown here read-only. The live ledger
                for this year is untouched; nothing on this page can be edited, deleted, or re-posted.
            </p>
            <dl class="grid grid-cols-2 gap-4 text-[13px] sm:grid-cols-4">
                <div><dt class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Archived on</dt><dd class="font-semibold">{{ archive.archivedAt }}</dd></div>
                <div><dt class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Archived by</dt><dd class="font-semibold">{{ archive.archivedBy }}</dd></div>
                <div><dt class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Vouchers</dt><dd class="font-semibold">{{ archive.voucherCount }}</dd></div>
                <div><dt class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Voucher lines</dt><dd class="font-semibold">{{ archive.lineCount }}</dd></div>
            </dl>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="vouchers" :page-size="25" empty-message="This archive contains no vouchers." />
        </Card>
    </div>
</template>
