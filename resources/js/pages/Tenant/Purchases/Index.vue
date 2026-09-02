<script setup>
import { computed, h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';
import Create from './Create.vue';

defineProps({
    purchases: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

// Store/cancel both redirect back to this same route + component, which
// Inertia re-renders in place without an onMounted re-run - watch flash
// status instead (same pattern as JournalVouchers/Index.vue).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);

const paymentModeLabels = {
    cash: 'Cash',
    bank: 'Bank',
    partial: 'Partial',
    credit: 'Credit',
};

const cancelling = ref(null);
const reasonForm = useForm({ reason: '' });

function openCancel(purchase) {
    cancelling.value = purchase;
    reasonForm.reset();
    reasonForm.clearErrors();
}

function onCancelModalOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    reasonForm.post(`/purchases/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

function itemSummary(purchase) {
    return purchase.lines.map((line) => line.item?.name).filter(Boolean).join(', ');
}

const columns = [
    { accessorKey: 'date', header: 'Date' },
    {
        id: 'supplier',
        header: 'Supplier',
        numeric: false,
        cell: ({ row }) => row.original.supplier?.name ?? '—',
    },
    {
        id: 'bill_number',
        header: 'Bill #',
        numeric: false,
        cell: ({ row }) => row.original.bill_number ?? '—',
    },
    {
        id: 'items',
        header: 'Items',
        numeric: false,
        cell: ({ row }) => itemSummary(row.original) || '—',
    },
    {
        id: 'payment_mode',
        header: 'Payment',
        numeric: false,
        cell: ({ row }) => paymentModeLabels[row.original.payment_mode] ?? row.original.payment_mode,
    },
    {
        id: 'total',
        header: 'Total',
        numeric: true,
        cell: ({ row }) => Number(row.original.total).toFixed(2),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            row.original.status === 'cancelled'
                ? 'Cancelled'
                : 'Posted',
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-2' }, [
                h(
                    Button,
                    {
                        as: 'a',
                        variant: 'secondary',
                        tone: 'purple',
                        href: `/purchases/${row.original.id}/print`,
                        target: '_blank',
                        rel: 'noopener',
                    },
                    () => 'Print',
                ),
                row.original.status === 'posted'
                    ? h(Button, {
                          variant: 'secondary',
                          tone: 'purple',
                          type: 'button',
                          onClick: () => openCancel(row.original),
                      }, () => 'Cancel')
                    : null,
            ]),
    },
];
</script>

<template>
    <AppLayout title="Purchases" :nav-items="navItems">
        <template v-if="showCreateForm">
            <Create :suppliers="suppliers" :items="items" :accounts="accounts" :stores="stores" @cancel="showCreateForm = false" @posted="showCreateForm = false" />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Purchases</h2>
                <Button variant="primary" tone="purple" @click="showCreateForm = true">New purchase</Button>
            </div>

            <Card variant="panel">
                <DataTable :columns="columns" :data="purchases" :page-size="10" empty-message="No purchases yet" />
            </Card>
        </template>

        <Modal
            :open="!!cancelling"
            title="Cancel purchase"
            size="compact"
            @update:open="onCancelModalOpenChange"
        >
            <div v-if="cancelling" class="flex flex-col gap-4">
                <p class="text-sm text-text-muted">
                    This posts a reversing voucher for purchase from {{ cancelling.supplier?.name }} ({{ Number(cancelling.total).toFixed(2) }}). This cannot be undone.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason</label>
                    <Input v-model="reasonForm.reason" type="text" placeholder="Reason for cancellation" required />
                    <p v-if="reasonForm.errors.reason" class="mt-1 text-sm text-danger">{{ reasonForm.errors.reason }}</p>
                </div>
            </div>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="cancelling = null">Back</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="reasonForm.processing" @click="submitCancel">
                    Confirm cancellation
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
