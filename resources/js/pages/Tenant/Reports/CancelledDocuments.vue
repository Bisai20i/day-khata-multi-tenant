<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import { formatBsDate } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    rows: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    from: { type: String, default: null },
    to: { type: String, default: null },
    customerId: { type: [Number, null], default: null },
    supplierId: { type: [Number, null], default: null },
});

useLayoutChrome('Cancelled Documents');

// Audit T15-14: legacy's two narrower cancelled-document views had a date
// range and a party filter with server-side pagination - this unified page
// still unions everything with no filters by default, but now supports the
// same optional narrowing.
const from = ref(props.from ?? '');
const to = ref(props.to ?? '');
const customerId = ref(props.customerId);
const supplierId = ref(props.supplierId);

const customerOptions = computed(() => [
    { value: null, label: 'All customers' },
    ...props.customers.map((customer) => ({ value: customer.id, label: customer.name })),
]);

const supplierOptions = computed(() => [
    { value: null, label: 'All suppliers' },
    ...props.suppliers.map((supplier) => ({ value: supplier.id, label: supplier.name })),
]);

const isLoading = ref(false);

const hasActiveFilter = computed(
    () => from.value !== '' || to.value !== '' || customerId.value !== null || supplierId.value !== null,
);

function resetFilters() {
    from.value = '';
    to.value = '';
    customerId.value = null;
    supplierId.value = null;
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            from: from.value || undefined,
            to: to.value || undefined,
            customer_id: customerId.value ?? undefined,
            supplier_id: supplierId.value ?? undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

// The cancelled-at timestamp is a full datetime string ("Y-m-d H:i:s") for a
// module document, but a plain date string for a standalone journal/cash-bank
// voucher's reversal date - both slice cleanly to the calendar day.
function cancelledDateBs(value) {
    return value ? formatBsDate(value.slice(0, 10)) : '-';
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
    { accessorKey: 'party', header: 'Party', cell: ({ row }) => row.original.party ?? '-' },
    {
        id: 'cancelledAtBs',
        header: 'Cancelled (BS)',
        numeric: false,
        cell: ({ row }) => cancelledDateBs(row.original.cancelledAt),
    },
    { accessorKey: 'cancelledBy', header: 'Cancelled By', cell: ({ row }) => row.original.cancelledBy ?? '-' },
    { accessorKey: 'reason', header: 'Reason', cell: ({ row }) => row.original.reason ?? '-' },
];
</script>

<template>
    <div>
        <PageHeader
            title="Cancelled Documents"
            description="Invoices, bills and vouchers that were cancelled, with who cancelled them and why."
        >
            <a href="/reports/cancelled-documents/export"><Button variant="secondary" tone="purple">Export</Button></a>
        </PageHeader>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From date (BS)</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To date (BS)</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Customer</label>
                    <Select v-model="customerId" :options="customerOptions" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Supplier</label>
                    <Select v-model="supplierId" :options="supplierOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No cancelled documents for this period or filter." />
        </Card>
    </div>
</template>
