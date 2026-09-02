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

const props = defineProps({
    receipts: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    outstandingSales: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

// Store/cancel both redirect back to this same route + component, which
// Inertia re-renders in place without an onMounted re-run - watch flash
// status instead (same pattern as FixedAssets/Index.vue).
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

function openCancel(receipt) {
    cancelling.value = receipt;
    cancelForm.reset();
    cancelForm.clearErrors();
}

function onCancelOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    cancelForm.post(`/receipts/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

const columns = [
    { accessorKey: 'date', header: 'Date' },
    {
        id: 'customer',
        header: 'Customer',
        numeric: false,
        cell: ({ row }) => row.original.customer?.name ?? '—',
    },
    {
        id: 'amount',
        header: 'Amount',
        numeric: true,
        cell: ({ row }) => Number(row.original.amount).toFixed(2),
    },
    {
        id: 'mode',
        header: 'Mode',
        numeric: false,
        cell: ({ row }) => row.original.payment_mode.charAt(0).toUpperCase() + row.original.payment_mode.slice(1),
    },
    {
        id: 'allocated',
        header: 'Allocated',
        numeric: true,
        cell: ({ row }) => (row.original.allocations ?? []).reduce((sum, a) => sum + Number(a.amount), 0).toFixed(2),
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
                ? h(Button, {
                      variant: 'secondary',
                      tone: 'purple',
                      type: 'button',
                      onClick: () => openCancel(row.original),
                  }, () => 'Cancel')
                : '—',
    },
];
</script>

<template>
    <AppLayout title="Receipts" :nav-items="navItems">
        <template v-if="showCreateForm">
            <Create
                :customers="customers"
                :accounts="accounts"
                :outstanding-sales="outstandingSales"
                @cancel="showCreateForm = false"
                @posted="showCreateForm = false"
            />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Receipts</h2>
                <Button variant="primary" tone="purple" @click="showCreateForm = true">New receipt</Button>
            </div>

            <Card variant="panel">
                <DataTable :columns="columns" :data="receipts" :page-size="10" empty-message="No receipts yet" />
            </Card>
        </template>

        <Modal :open="!!cancelling" title="Cancel receipt" size="compact" @update:open="onCancelOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    This posts a reversing entry for this receipt. This cannot be undone.
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
