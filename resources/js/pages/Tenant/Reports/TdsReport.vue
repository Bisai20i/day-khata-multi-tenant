<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    sales: { type: Array, default: () => [] },
    purchases: { type: Array, default: () => [] },
    salesTotal: { type: Number, default: 0 },
    purchasesTotal: { type: Number, default: 0 },
    grandTotal: { type: Number, default: 0 },
    from: { type: String, required: true },
    to: { type: String, required: true },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const from = ref(props.from);
const to = ref(props.to);

function applyFilter() {
    router.get(window.location.pathname, { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true });
}

const salesColumns = [
    { accessorKey: 'date', header: 'Date' },
    { id: 'voucher_number', header: 'Invoice #', numeric: false, cell: ({ row }) => row.original.voucher_number ?? '—' },
    { id: 'party', header: 'Customer', numeric: false, cell: ({ row }) => row.original.party ?? '—' },
    { id: 'total', header: 'Gross Total', numeric: true, cell: ({ row }) => row.original.total.toFixed(2) },
    { id: 'net_tds_amount', header: 'Net TDS', numeric: true, cell: ({ row }) => row.original.net_tds_amount.toFixed(2) },
    { id: 'tds_account', header: 'TDS Account', numeric: false, cell: ({ row }) => row.original.tds_account ?? '—' },
];

const purchaseColumns = [
    { accessorKey: 'date', header: 'Date' },
    { id: 'voucher_number', header: 'Voucher #', numeric: false, cell: ({ row }) => row.original.voucher_number ?? '—' },
    { id: 'party', header: 'Supplier', numeric: false, cell: ({ row }) => row.original.party ?? '—' },
    { id: 'total', header: 'Gross Total', numeric: true, cell: ({ row }) => row.original.total.toFixed(2) },
    { id: 'net_tds_amount', header: 'Net TDS', numeric: true, cell: ({ row }) => row.original.net_tds_amount.toFixed(2) },
    { id: 'tds_account', header: 'TDS Account', numeric: false, cell: ({ row }) => row.original.tds_account ?? '—' },
];
</script>

<template>
    <AppLayout title="TDS Report" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">TDS Report</h2>
        </div>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                    <Input v-model="from" type="date" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                    <Input v-model="to" type="date" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel" title="TDS on Sales (claimable credit)" class="mb-4">
            <DataTable :columns="salesColumns" :data="sales" :page-size="25" empty-message="No TDS withheld on sales in this range" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Total TDS on Sales:</span> <span class="font-semibold">{{ salesTotal.toFixed(2) }}</span></div>
            </div>
        </Card>

        <Card variant="panel" title="TDS on Purchases (liability to remit)" class="mb-4">
            <DataTable :columns="purchaseColumns" :data="purchases" :page-size="25" empty-message="No TDS withheld on purchases in this range" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Total TDS on Purchases:</span> <span class="font-semibold">{{ purchasesTotal.toFixed(2) }}</span></div>
            </div>
        </Card>

        <Card variant="panel">
            <div class="flex items-center px-1 py-1 text-[14px] font-bold text-text-strong">
                <div class="flex-1">Combined Grand Total (Sales TDS + Purchases TDS)</div>
                <div class="w-32 text-right">{{ grandTotal.toFixed(2) }}</div>
            </div>
        </Card>
    </AppLayout>
</template>
