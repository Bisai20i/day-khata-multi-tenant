<script setup>
import { computed, h } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Archive } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Badge from '@/components/ui/Badge.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    fiscalYear: { type: Object, required: true },
    archive: { type: Object, required: true },
    vouchers: { type: Array, default: () => [] },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

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
    return Number(value ?? 0).toFixed(2);
}

function voucherHref(voucherId) {
    return `/fiscal-year-archives/${props.archive.id}/vouchers/${voucherId}`;
}

const columns = [
    { accessorKey: 'date', header: 'Date' },
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
    { accessorKey: 'createdByName', header: 'Created By' },
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
    <AppLayout :title="`Archived: ${fiscalYear.name}`" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h2 class="text-base font-bold text-text-strong">{{ fiscalYear.name }}</h2>
                <p class="text-xs text-text-muted">{{ fiscalYear.bsLabel }} &middot; {{ fiscalYear.startDate }} to {{ fiscalYear.endDate }}</p>
            </div>
            <Badge variant="neutral" pill>
                <Archive class="h-3 w-3" aria-hidden="true" />
                Archived Snapshot
            </Badge>
        </div>

        <Card variant="panel" class="mb-4">
            <p class="mb-3 text-[12.5px] text-text-muted">
                This fiscal year has been copied out to cold storage and is shown here read-only. The live ledger
                for this year is untouched; nothing on this page can be edited, deleted, or re-posted.
            </p>
            <div class="flex flex-wrap gap-x-8 gap-y-2 text-[12.5px]">
                <div><span class="text-text-muted">Archived on:</span> <span class="font-semibold">{{ archive.archivedAt }}</span></div>
                <div><span class="text-text-muted">Archived by:</span> <span class="font-semibold">{{ archive.archivedBy }}</span></div>
                <div><span class="text-text-muted">Vouchers:</span> <span class="font-semibold">{{ archive.voucherCount }}</span></div>
                <div><span class="text-text-muted">Lines:</span> <span class="font-semibold">{{ archive.lineCount }}</span></div>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="vouchers" :page-size="25" empty-message="No vouchers in this archive" />
        </Card>
    </AppLayout>
</template>
