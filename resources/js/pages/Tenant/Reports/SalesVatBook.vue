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
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ taxable_amount: 0, vat_amount: 0, nontaxable_amount: 0, total: 0 }) },
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

const columns = [
    { accessorKey: 'sn', header: 'SN', numeric: true },
    { accessorKey: 'date', header: 'Date' },
    { id: 'voucher_number', header: 'Invoice #', numeric: false, cell: ({ row }) => row.original.voucher_number ?? '—' },
    { id: 'customer', header: 'Customer', numeric: false, cell: ({ row }) => row.original.customer ?? '—' },
    { id: 'taxable_amount', header: 'Taxable', numeric: true, cell: ({ row }) => row.original.taxable_amount.toFixed(2) },
    { id: 'vat_amount', header: 'VAT', numeric: true, cell: ({ row }) => row.original.vat_amount.toFixed(2) },
    { id: 'nontaxable_amount', header: 'Non-taxable', numeric: true, cell: ({ row }) => row.original.nontaxable_amount.toFixed(2) },
    { id: 'total', header: 'Total', numeric: true, cell: ({ row }) => row.original.total.toFixed(2) },
];
</script>

<template>
    <AppLayout title="Sales VAT Book" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Sales VAT Book</h2>
        </div>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Posted invoices only — this is a tax-filing document, cancelled invoices are excluded.
        </p>

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

        <Card variant="panel">
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No posted sales in this range" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Taxable:</span> <span class="font-semibold">{{ totals.taxable_amount.toFixed(2) }}</span></div>
                <div><span class="text-text-muted">VAT:</span> <span class="font-semibold">{{ totals.vat_amount.toFixed(2) }}</span></div>
                <div><span class="text-text-muted">Non-taxable:</span> <span class="font-semibold">{{ totals.nontaxable_amount.toFixed(2) }}</span></div>
                <div><span class="text-text-muted">Total:</span> <span class="font-semibold">{{ totals.total.toFixed(2) }}</span></div>
            </div>
        </Card>
    </AppLayout>
</template>
