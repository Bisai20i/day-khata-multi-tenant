<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { formatBsDate } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    rows: { type: Array, default: () => [] },
});

useLayoutChrome('Cancelled Documents');

// The cancelled-at timestamp is a full datetime string ("Y-m-d H:i:s") for a
// module document, but a plain date string for a standalone journal/cash-bank
// voucher's reversal date - both slice cleanly to the calendar day.
function cancelledDateBs(value) {
    return value ? formatBsDate(value.slice(0, 10)) : '—';
}

const columns = [
    { accessorKey: 'type', header: 'Type' },
    { accessorKey: 'number', header: 'Number' },
    {
        id: 'dateBs',
        header: 'Date (BS)',
        numeric: false,
        cell: ({ row }) => formatBsDate(row.original.date) || row.original.date,
    },
    {
        id: 'cancelledAtBs',
        header: 'Cancelled (BS)',
        numeric: false,
        cell: ({ row }) => cancelledDateBs(row.original.cancelledAt),
    },
    { accessorKey: 'cancelledBy', header: 'Cancelled By', cell: ({ row }) => row.original.cancelledBy ?? '—' },
    { accessorKey: 'reason', header: 'Reason', cell: ({ row }) => row.original.reason ?? '—' },
];
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Cancelled Documents</h2>
            <a href="/reports/cancelled-documents/export"><Button variant="secondary" tone="purple">Export</Button></a>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No cancelled documents" />
        </Card>
    </div>
</template>
