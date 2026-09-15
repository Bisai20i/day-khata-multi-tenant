<script setup>
import { h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Plus, Printer } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import { useToast } from '@/composables/useToast';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import Create from './Create.vue';

defineOptions({ layout: AppLayout });

defineProps({
    capitalSales: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    defaultVatRate: { type: String, default: '13.00' },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Capital Sales');

// Store/cancel both redirect back to this same route + component, which
// Inertia re-renders in place without an onMounted re-run - watch flash
// status instead (same pattern as Sales/Index.vue).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

// C11: the controller flashes the document it just created, so the invoice
// opens for that exact capital sale instead of the page guessing the newest
// id out of the list.
watch(
    () => page.props.flash?.created,
    (created) => {
        if (created?.type === 'capital-sale' && created.print_url) {
            window.open(created.print_url, '_blank', 'noopener');
        }
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

function openCancel(capitalSale) {
    cancelling.value = capitalSale;
    reasonForm.reset();
    reasonForm.clearErrors();
}

function onCancelModalOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    reasonForm.post(`/capital-sales/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

function lineSummary(capitalSale) {
    return capitalSale.lines.map((line) => line.account?.name).filter(Boolean).join(', ');
}

const columns = [
    {
        id: 'date',
        header: 'Date (BS)',
        numeric: false,
        cell: ({ row }) => formatBsDate(row.original.date),
    },
    {
        id: 'invoice_number',
        header: 'Invoice #',
        numeric: false,
        cell: ({ row }) => row.original.invoice_number ?? '—',
    },
    {
        id: 'customer',
        header: 'Customer',
        numeric: false,
        cell: ({ row }) => row.original.customer?.name ?? '—',
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
        cell: ({ row }) => {
            const capitalSale = row.original;

            const printBtn = h(Tooltip, { label: 'Print invoice' }, () =>
                h(
                    'a',
                    {
                        href: `/capital-sales/${capitalSale.id}/print`,
                        target: '_blank',
                        rel: 'noopener',
                        class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                        'aria-label': 'Print capital sale invoice',
                    },
                    [h(Printer, { class: 'h-[13px] w-[13px]' })],
                ),
            );

            if (capitalSale.status !== 'posted') {
                return h('div', { class: 'flex items-center gap-1' }, [printBtn]);
            }

            return h('div', { class: 'flex items-center gap-1' }, [
                printBtn,
                h(Button, {
                    variant: 'secondary',
                    tone: 'purple',
                    type: 'button',
                    onClick: () => openCancel(capitalSale),
                }, () => 'Cancel'),
            ]);
        },
    },
];
</script>

<template>
    <div>
        <template v-if="showCreateForm">
            <Create
                :customers="customers"
                :accounts="accounts"
                :stores="stores"
                :default-vat-rate="defaultVatRate"
                @cancel="showCreateForm = false"
                @posted="showCreateForm = false"
            />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Capital Sales</h2>
                <Button variant="primary" tone="purple" @click="showCreateForm = true">
                    <Plus class="size-4" />
                    New capital sale
                </Button>
            </div>

            <Card variant="panel">
                <DataTable :columns="columns" :data="capitalSales" :page-size="10" empty-message="No capital sales yet" />
            </Card>
        </template>

        <Modal
            :open="!!cancelling"
            title="Cancel capital sale"
            size="compact"
            @update:open="onCancelModalOpenChange"
        >
            <div v-if="cancelling" class="flex flex-col gap-4">
                <p class="text-sm text-text-muted">
                    This posts a reversing voucher for the capital sale of {{ formatMoney(cancelling.total) }}. This cannot be undone.
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
