<script setup>
import { h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { useToast } from '@/composables/useToast';
import { formatMoney, sumMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import Create from './Create.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    payments: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    outstandingPurchases: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Payments');

// Posting/cancelling redirects back to this same route + component, which
// Inertia re-renders in place without an onMounted re-run - watch the flash
// prop instead (same pattern as every other module in this app).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);

const statusVariants = {
    posted: 'success',
    cancelled: 'danger',
};

const statusLabels = {
    posted: 'Posted',
    cancelled: 'Cancelled',
};

const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });

function openCancel(payment) {
    cancelling.value = payment;
    cancelForm.reset();
    cancelForm.clearErrors();
}

function onCancelOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    cancelForm.post(`/payments/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

const columns = [
    {
        id: 'date',
        header: 'Date (BS)',
        numeric: false,
        cell: ({ row }) => `${formatBsDate(row.original.date)} (${String(row.original.date).slice(0, 10)})`,
    },
    {
        id: 'supplier',
        header: 'Supplier',
        numeric: false,
        cell: ({ row }) => row.original.supplier?.name ?? '—',
    },
    {
        id: 'amount',
        header: 'Amount',
        numeric: true,
        cell: ({ row }) => formatMoney(row.original.amount),
    },
    {
        id: 'payment_mode',
        header: 'Mode',
        numeric: false,
        cell: ({ row }) => (row.original.payment_mode === 'bank' ? 'Bank' : 'Cash'),
    },
    {
        id: 'allocated',
        header: 'Allocated',
        numeric: true,
        // Exact addition of the stored 2dp strings, not a float reduce.
        cell: ({ row }) => formatMoney(sumMoney(row.original.allocations.map((a) => a.amount))),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(Badge, { variant: statusVariants[row.original.status] ?? 'neutral' }, () => statusLabels[row.original.status] ?? row.original.status),
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
    <div>
        <template v-if="showCreateForm">
            <Create
                :suppliers="suppliers"
                :bank-accounts="bankAccounts"
                :outstanding-purchases="outstandingPurchases"
                @cancel="showCreateForm = false"
                @posted="showCreateForm = false"
            />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Payments</h2>
                <Button variant="primary" tone="purple" @click="showCreateForm = true">
                    <Plus class="size-4" />
                    New payment
                </Button>
            </div>

            <Card variant="panel">
                <DataTable :columns="columns" :data="payments" :page-size="10" empty-message="No payments yet" />
            </Card>
        </template>

        <Modal :open="!!cancelling" title="Cancel payment" size="compact" @update:open="onCancelOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    This posts a reversing entry for this payment. This cannot be undone.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input v-model="cancelForm.reason" type="text" maxlength="500" placeholder="e.g. Entered wrong amount" required />
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
    </div>
</template>
