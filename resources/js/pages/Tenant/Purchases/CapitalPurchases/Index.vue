<script setup>
import { h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { useToast } from '@/composables/useToast';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import Create from './Create.vue';

defineOptions({ layout: AppLayout });

defineProps({
    capitalPurchases: { type: Array, default: () => [] },
    // Exact SQL sum over every capital purchase (item 8, "totals row") -
    // never a page's worth of client-side addition.
    totals: {
        type: Object,
        default: () => ({ total: '0.00' }),
    },
    suppliers: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    defaultVatRate: { type: String, default: '13.00' },
    depreciationCategories: { type: Array, default: () => [] },
    depreciationMethods: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Capital Purchases');

// Store/cancel both redirect back to this same route + component, which
// Inertia re-renders in place without an onMounted re-run - watch flash
// status instead (same pattern as Purchases/Index.vue).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);

const typeLabels = {
    capital: 'Capital',
    service: 'Service',
};

const paymentModeLabels = {
    cash: 'Cash',
    bank: 'Bank',
    partial: 'Partial',
    credit: 'Credit',
};

const cancelling = ref(null);
const reasonForm = useForm({ reason: '' });

function openCancel(capitalPurchase) {
    cancelling.value = capitalPurchase;
    reasonForm.reset();
    reasonForm.clearErrors();
}

function onCancelModalOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    reasonForm.post(`/capital-purchases/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

function lineSummary(capitalPurchase) {
    return capitalPurchase.lines.map((line) => line.account?.name).filter(Boolean).join(', ');
}

const columns = [
    {
        id: 'date',
        header: 'Date (BS)',
        numeric: false,
        cell: ({ row }) => formatBsDate(row.original.date),
    },
    {
        id: 'bill_number',
        header: 'Bill #',
        numeric: false,
        cell: ({ row }) => row.original.bill_number ?? '—',
    },
    {
        id: 'type',
        header: 'Type',
        numeric: false,
        cell: ({ row }) => typeLabels[row.original.type] ?? row.original.type,
    },
    {
        id: 'supplier',
        header: 'Supplier',
        numeric: false,
        cell: ({ row }) => row.original.supplier?.name ?? '—',
    },
    {
        id: 'accounts',
        header: 'Accounts',
        numeric: false,
        cell: ({ row }) => lineSummary(row.original) || '—',
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
        // The stored total, written once by the server's calculator.
        cell: ({ row }) => formatMoney(row.original.total),
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
                : null,
    },
];
</script>

<template>
    <div>
        <template v-if="showCreateForm">
            <Create
                :suppliers="suppliers"
                :accounts="accounts"
                :stores="stores"
                :default-vat-rate="defaultVatRate"
                :depreciation-categories="depreciationCategories"
                :depreciation-methods="depreciationMethods"
                @cancel="showCreateForm = false"
                @posted="showCreateForm = false"
            />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Capital Purchases</h2>
                <div class="flex items-center gap-2">
                    <a href="/capital-purchases/export">
                        <Button variant="secondary" tone="purple" type="button">Export</Button>
                    </a>
                    <Button variant="primary" tone="purple" @click="showCreateForm = true">
                        <Plus class="size-4" />
                        New capital purchase
                    </Button>
                </div>
            </div>

            <Card variant="panel">
                <DataTable :columns="columns" :data="capitalPurchases" :page-size="10" empty-message="No capital purchases yet" />

                <!-- Server-computed SQL sum over every capital purchase
                     (item 8, "totals row"). -->
                <div class="mt-3 border-t-[1.5px] border-border pt-3 text-sm">
                    <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Total</p>
                    <p class="font-bold text-text-strong">{{ formatMoney(totals.total) }}</p>
                </div>
            </Card>
        </template>

        <Modal
            :open="!!cancelling"
            title="Cancel capital purchase"
            size="compact"
            @update:open="onCancelModalOpenChange"
        >
            <div v-if="cancelling" class="flex flex-col gap-4">
                <p class="text-sm text-text-muted">
                    This posts a reversing voucher for the capital purchase of {{ formatMoney(cancelling.total) }}. This cannot be undone.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input v-model="reasonForm.reason" type="text" maxlength="500" placeholder="Reason for cancellation" required />
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
    </div>
</template>
