<script setup>
import { computed, h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Ban } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';
import Create from './Create.vue';

const props = defineProps({
    returns: { type: Array, default: () => [] },
    sales: { type: Array, default: () => [] },
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

const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });

function openCancel(salesReturn) {
    cancelling.value = salesReturn;
    cancelForm.reset();
    cancelForm.clearErrors();
}

function onCancelOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    cancelForm.post(`/sales-returns/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

const columns = [
    { accessorKey: 'date', header: 'Date' },
    {
        id: 'sale',
        header: 'Original sale #',
        numeric: false,
        cell: ({ row }) => row.original.sale_id,
    },
    {
        id: 'customer',
        header: 'Customer',
        numeric: false,
        cell: ({ row }) => row.original.sale?.customer?.name ?? '—',
    },
    {
        id: 'reason',
        header: 'Reason',
        numeric: false,
        cell: ({ row }) => row.original.reason ?? '—',
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
            h(
                'span',
                {
                    class:
                        row.original.status === 'cancelled'
                            ? 'text-danger font-semibold'
                            : 'text-success font-semibold',
                },
                row.original.status === 'cancelled' ? 'Cancelled' : 'Posted',
            ),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            row.original.status === 'cancelled'
                ? null
                : h(Tooltip, { label: 'Cancel return' }, () =>
                      h(
                          'button',
                          {
                              type: 'button',
                              class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-danger-bg hover:text-danger',
                              'aria-label': 'Cancel return',
                              onClick: () => openCancel(row.original),
                          },
                          [h(Ban, { class: 'h-[13px] w-[13px]' })],
                      ),
                  ),
    },
];
</script>

<template>
    <AppLayout title="Sales Returns" :nav-items="navItems">
        <template v-if="showCreateForm">
            <Create :sales="sales" :accounts="accounts" @cancel="showCreateForm = false" @posted="showCreateForm = false" />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Sales Returns</h2>
                <Button variant="primary" tone="purple" @click="showCreateForm = true">New return</Button>
            </div>

            <Card variant="panel">
                <DataTable :columns="columns" :data="returns" :page-size="10" empty-message="No sales returns yet" />
            </Card>
        </template>

        <Modal :open="!!cancelling" title="Cancel sales return" size="compact" @update:open="onCancelOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    This posts a reversing entry for this return (and its refund settlement, if one was recorded). This cannot be undone.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason</label>
                    <Input v-model="cancelForm.reason" type="text" required />
                    <p v-if="cancelForm.errors.reason" class="mt-1 text-sm text-danger">{{ cancelForm.errors.reason }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="cancelling = null">Back</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="cancelForm.processing" @click="submitCancel">
                    Confirm cancellation
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
