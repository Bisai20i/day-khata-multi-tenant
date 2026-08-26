<script setup>
import { computed, h, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    sales: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ taxable_amount: 0, nontaxable_amount: 0, vat_amount: 0, total: 0 }) },
    customers: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    customerId: { type: [Number, null], default: null },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

const from = ref(props.from);
const to = ref(props.to);
const customerId = ref(props.customerId);

const customerOptions = computed(() => [
    { value: null, label: 'All customers' },
    ...props.customers.map((customer) => ({ value: customer.id, label: customer.name })),
]);

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: from.value, to: to.value, customer_id: customerId.value ?? undefined },
        { preserveState: true, preserveScroll: true },
    );
}

const invoiceTypeLabels = { full: 'Full', abbreviated: 'Abbreviated' };
const paymentModeLabels = { cash: 'Cash', bank: 'Bank', partial: 'Partial', credit: 'Credit' };

const columns = [
    { accessorKey: 'date', header: 'Date' },
    { id: 'voucher_number', header: 'Invoice #', numeric: false, cell: ({ row }) => row.original.voucher_number ?? '—' },
    {
        id: 'invoice_type',
        header: 'Type',
        numeric: false,
        cell: ({ row }) => invoiceTypeLabels[row.original.invoice_type] ?? row.original.invoice_type,
    },
    { id: 'customer', header: 'Customer', numeric: false, cell: ({ row }) => row.original.customer ?? '—' },
    { id: 'taxable_amount', header: 'Taxable', numeric: true, cell: ({ row }) => row.original.taxable_amount.toFixed(2) },
    { id: 'nontaxable_amount', header: 'Non-taxable', numeric: true, cell: ({ row }) => row.original.nontaxable_amount.toFixed(2) },
    { id: 'vat_amount', header: 'VAT', numeric: true, cell: ({ row }) => row.original.vat_amount.toFixed(2) },
    { id: 'total', header: 'Total', numeric: true, cell: ({ row }) => row.original.total.toFixed(2) },
    {
        id: 'payment_mode',
        header: 'Payment',
        numeric: false,
        cell: ({ row }) => paymentModeLabels[row.original.payment_mode] ?? row.original.payment_mode,
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(
                'span',
                { class: row.original.status === 'cancelled' ? 'text-danger font-semibold' : 'text-success font-semibold' },
                row.original.status === 'cancelled' ? 'Cancelled' : 'Posted',
            ),
    },
];
</script>

<template>
    <AppLayout title="Sales Register" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Sales Register</h2>
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
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Customer</label>
                    <Select v-model="customerId" :options="customerOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="sales" :page-size="25" empty-message="No sales in this range" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Taxable:</span> <span class="font-semibold">{{ totals.taxable_amount.toFixed(2) }}</span></div>
                <div><span class="text-text-muted">Non-taxable:</span> <span class="font-semibold">{{ totals.nontaxable_amount.toFixed(2) }}</span></div>
                <div><span class="text-text-muted">VAT:</span> <span class="font-semibold">{{ totals.vat_amount.toFixed(2) }}</span></div>
                <div><span class="text-text-muted">Total:</span> <span class="font-semibold">{{ totals.total.toFixed(2) }}</span></div>
            </div>
        </Card>
    </AppLayout>
</template>
