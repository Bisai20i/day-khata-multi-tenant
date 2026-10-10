<script setup>
import { computed, h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Ban, Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PaginatorNav from '@/components/ui/PaginatorNav.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { formatMoney, sumMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import Create from './Create.vue';
import { useOpenFiscalYear } from '@/composables/useOpenFiscalYear';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const { hasOpenFiscalYear } = useOpenFiscalYear();

const props = defineProps({
    // A LengthAwarePaginator page (flags G-17).
    payments: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    suppliers: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    outstandingPurchases: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Payments');

// UI mirrors of routes/tenant-payments.php; each route still enforces its key.
const { can } = usePermissions();
const canCreatePayment = computed(() => can('payments.create'));
const canCancelPayment = computed(() => can('payments.cancel'));

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
        id: 'payment_number',
        header: 'Payment #',
        numeric: false,
        cell: ({ row }) => row.original.payment_number ?? '-',
    },
    {
        id: 'supplier',
        header: 'Supplier',
        numeric: false,
        cell: ({ row }) => row.original.supplier?.name ?? '-',
    },
    {
        id: 'amount',
        header: 'Amount paid (Rs.)',
        numeric: true,
        cell: ({ row }) => formatMoney(row.original.amount),
    },
    {
        id: 'payment_mode',
        header: 'Paid by',
        numeric: false,
        cell: ({ row }) => (row.original.payment_mode === 'bank' ? 'Bank' : 'Cash'),
    },
    {
        id: 'allocated',
        header: 'Applied to bills (Rs.)',
        numeric: true,
        // Exact addition of the stored 2dp strings, not a float reduce.
        cell: ({ row }) => formatMoney(sumMoney(row.original.allocations.map((a) => a.amount))),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(Badge, { pill: true, variant: statusVariants[row.original.status] ?? 'neutral' }, () => statusLabels[row.original.status] ?? row.original.status),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            row.original.status === 'posted' && canCancelPayment.value
                ? h(Tooltip, { label: 'Cancel this payment and reverse its entries' }, () =>
                      h(
                          'button',
                          {
                              type: 'button',
                              class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-danger-bg hover:text-danger',
                              'aria-label': `Cancel payment to ${row.original.supplier?.name ?? 'supplier'}`,
                              onClick: () => openCancel(row.original),
                          },
                          [h(Ban, { class: 'h-[13px] w-[13px]' })],
                      ),
                  )
                : '-',
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
            <PageHeader title="Supplier payments" description="Money paid to suppliers against their bills. Cancel a payment entered in error.">
                <Button v-if="hasOpenFiscalYear && canCreatePayment" variant="primary" tone="purple" @click="showCreateForm = true">
                    <Plus class="size-4" aria-hidden="true" />
                    New payment
                </Button>
            </PageHeader>

            <Card variant="panel" class="bg-white">
                <div v-if="payments.data.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                    <p class="text-sm font-semibold text-text-strong">No payments yet</p>
                    <p v-if="hasOpenFiscalYear && canCreatePayment" class="text-xs text-text-muted">Record the first payment you made to a supplier and it will be listed here.</p>
                    <Button v-if="hasOpenFiscalYear && canCreatePayment" variant="primary" tone="purple" @click="showCreateForm = true">
                        <Plus class="size-4" aria-hidden="true" />
                        New payment
                    </Button>
                </div>
                <template v-else>
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-text-muted" aria-live="polite">Showing {{ payments.from }}–{{ payments.to }} of {{ payments.total }}</p>
                    </div>
                    <DataTable :columns="columns" :data="payments.data" :page-size="Math.max(payments.data.length, 1)" empty-message="No payments yet" />
                    <PaginatorNav :paginator="payments" label="Payments pagination" />
                </template>
            </Card>
        </template>

        <Modal :open="!!cancelling" title="Cancel payment" size="compact" @update:open="onCancelOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    Cancelling this payment posts a reversing entry and reopens the supplier bills it was applied to. This cannot be undone.
                </p>
                <div>
                    <Label class="mb-1">Reason <span class="text-danger">*</span></Label>
                    <Input v-model="cancelForm.reason" type="text" maxlength="500" placeholder="e.g. Entered wrong amount" required />
                    <p v-if="cancelForm.errors.reason" class="mt-1 text-sm text-danger">{{ cancelForm.errors.reason }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="neutral" type="button" @click="cancelling = null">Keep payment</Button>
                <Button variant="primary" tone="purple" type="button" :loading="cancelForm.processing" @click="submitCancel">
                    Cancel this payment
                </Button>
            </template>
        </Modal>
    </div>
</template>
