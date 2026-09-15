<script setup>
import { computed, h, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sales: { type: Array, default: () => [] },
    totals: {
        type: Object,
        default: () => ({ taxable_amount: '0.00', nontaxable_amount: '0.00', vat_amount: '0.00', total: '0.00' }),
    },
    customers: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    customerId: { type: [Number, null], default: null },
    storeId: { type: [Number, null], default: null },
    paymentMode: { type: [String, null], default: null },
});

useLayoutChrome('Sales Register');

const from = ref(props.from);
const to = ref(props.to);
const customerId = ref(props.customerId);
const storeId = ref(props.storeId);
const paymentMode = ref(props.paymentMode);

const customerOptions = computed(() => [
    { value: null, label: 'All customers' },
    ...props.customers.map((customer) => ({ value: customer.id, label: customer.name })),
]);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const paymentModeOptions = [
    { value: null, label: 'All payment modes' },
    { value: 'cash', label: 'Cash' },
    { value: 'bank', label: 'Bank' },
    { value: 'partial', label: 'Partial' },
    { value: 'credit', label: 'Credit' },
];

// BS first, AD beside it - the same order every printed document uses.
const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            from: from.value,
            to: to.value,
            customer_id: customerId.value ?? undefined,
            store_id: storeId.value ?? undefined,
            payment_mode: paymentMode.value ?? undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

const invoiceTypeLabels = { full: 'Full', abbreviated: 'Abbreviated', pan: 'PAN' };
const paymentModeLabels = { cash: 'Cash', bank: 'Bank', partial: 'Partial', credit: 'Credit' };

const columns = [
    { id: 'date', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { accessorKey: 'date', header: 'Date (AD)' },
    // The stored invoice number (C7), never rebuilt from the current prefix.
    { id: 'invoice_number', header: 'Invoice #', numeric: false, cell: ({ row }) => row.original.invoice_number ?? '—' },
    {
        id: 'invoice_type',
        header: 'Type',
        numeric: false,
        cell: ({ row }) => invoiceTypeLabels[row.original.invoice_type] ?? row.original.invoice_type,
    },
    { id: 'customer', header: 'Customer', numeric: false, cell: ({ row }) => row.original.customer ?? '—' },
    { id: 'taxable_amount', header: 'Taxable', numeric: true, cell: ({ row }) => formatMoney(row.original.taxable_amount) },
    { id: 'nontaxable_amount', header: 'Non-taxable', numeric: true, cell: ({ row }) => formatMoney(row.original.nontaxable_amount) },
    { id: 'vat_amount', header: 'VAT', numeric: true, cell: ({ row }) => formatMoney(row.original.vat_amount) },
    { id: 'total', header: 'Total', numeric: true, cell: ({ row }) => formatMoney(row.original.total) },
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
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Sales Register</h2>
        </div>

        <p class="mb-4 text-[12.5px] text-text-muted">{{ rangeLabel }}</p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Customer</label>
                    <Select v-model="customerId" :options="customerOptions" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <div class="w-48">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Payment mode</label>
                    <Select v-model="paymentMode" :options="paymentModeOptions" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="sales" :page-size="25" empty-message="No sales in this range" />

            <div class="mt-3 flex flex-wrap justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[12.5px]">
                <div><span class="text-text-muted">Taxable:</span> <span class="font-semibold">{{ formatMoney(totals.taxable_amount) }}</span></div>
                <div><span class="text-text-muted">Non-taxable:</span> <span class="font-semibold">{{ formatMoney(totals.nontaxable_amount) }}</span></div>
                <div><span class="text-text-muted">VAT:</span> <span class="font-semibold">{{ formatMoney(totals.vat_amount) }}</span></div>
                <div><span class="text-text-muted">Total:</span> <span class="font-semibold">{{ formatMoney(totals.total) }}</span></div>
            </div>

            <p class="mt-3 text-[11.5px] text-text-faint">
                Totals cover posted invoices only; cancelled rows are listed for the audit trail but never
                counted.
            </p>
        </Card>
    </div>
</template>
