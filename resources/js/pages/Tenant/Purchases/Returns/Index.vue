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
    returns: { type: Array, default: () => [] },
    purchases: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);

function itemSummary(purchaseReturn) {
    return purchaseReturn.lines
        .map((line) => line.purchase_line?.item?.name)
        .filter(Boolean)
        .join(', ');
}

const cancelling = ref(null);
const reasonForm = useForm({ reason: '' });

function openCancel(purchaseReturn) {
    cancelling.value = purchaseReturn;
    reasonForm.reset();
    reasonForm.clearErrors();
}

function onCancelModalOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    reasonForm.post(`/purchase-returns/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

const columns = [
    { accessorKey: 'date', header: 'Date' },
    {
        id: 'purchase',
        header: 'Purchase',
        numeric: false,
        cell: ({ row }) => `#${row.original.purchase?.id} — ${row.original.purchase?.supplier?.name ?? '—'}`,
    },
    {
        id: 'items',
        header: 'Items',
        numeric: false,
        cell: ({ row }) => itemSummary(row.original) || '—',
    },
    {
        id: 'reason',
        header: 'Reason',
        numeric: false,
        cell: ({ row }) => row.original.reason ?? '—',
    },
    {
        id: 'refund',
        header: 'Refund via',
        numeric: false,
        cell: ({ row }) => row.original.refund_account?.name ?? '—',
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
        cell: ({ row }) => (row.original.status === 'cancelled' ? 'Cancelled' : 'Posted'),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            row.original.status === 'posted'
                ? h(
                      Button,
                      {
                          variant: 'secondary',
                          tone: 'purple',
                          type: 'button',
                          onClick: () => openCancel(row.original),
                      },
                      () => 'Cancel',
                  )
                : '—',
    },
];
</script>

<template>
    <AppLayout title="Purchase Returns" :nav-items="navItems">
        <template v-if="showCreateForm">
            <Create :purchases="purchases" :accounts="accounts" @cancel="showCreateForm = false" @posted="showCreateForm = false" />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Purchase Returns</h2>
                <Button variant="primary" tone="purple" @click="showCreateForm = true">New return</Button>
            </div>

            <Card variant="panel">
                <DataTable :columns="columns" :data="returns" :page-size="10" empty-message="No purchase returns yet" />
            </Card>
        </template>

        <Modal
            :open="!!cancelling"
            title="Cancel purchase return"
            size="compact"
            @update:open="onCancelModalOpenChange"
        >
            <div v-if="cancelling" class="flex flex-col gap-4">
                <p class="text-sm text-text-muted">
                    This posts a reversing voucher for the return against purchase #{{ cancelling.purchase?.id }}
                    ({{ Number(cancelling.total).toFixed(2) }}). This cannot be undone.
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
