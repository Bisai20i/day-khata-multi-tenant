<script setup>
import { computed, h, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { useToast } from '@/composables/useToast';
import { formatMoney, sumMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import Create from './Create.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    receipts: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    customers: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    outstandingSales: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Receipts');

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

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');

const columns = [
    {
        id: 'date',
        header: 'Date',
        numeric: false,
        cell: ({ row }) => formatBsDate(row.original.date),
    },
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
        cell: ({ row }) => formatMoney(row.original.amount),
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
        cell: ({ row }) => formatMoney(sumMoney((row.original.allocations ?? []).map((allocation) => allocation.amount))),
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
            // Cancelling reverses money already collected, so the route is
            // admin-only (C5) - do not offer a button that would 403.
            row.original.status === 'posted' && isAdmin.value
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
                <Button variant="primary" tone="purple" @click="showCreateForm = true">
                    <Plus class="size-4" />
                    New receipt
                </Button>
            </div>

            <Card variant="panel">
                <DataTable
                    :columns="columns"
                    :data="receipts.data"
                    :page-size="Math.max(receipts.data.length, 1)"
                    empty-message="No receipts yet"
                />

                <div v-if="receipts.data.length > 0" class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-text-muted">Showing {{ receipts.from }}-{{ receipts.to }} of {{ receipts.total }}</p>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="receipts.prev_page_url"
                            :href="receipts.prev_page_url"
                            preserve-state
                            preserve-scroll
                            class="inline-flex items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary"
                        >
                            Previous
                        </Link>
                        <span class="text-xs text-text-muted">Page {{ receipts.current_page }} of {{ receipts.last_page }}</span>
                        <Link
                            v-if="receipts.next_page_url"
                            :href="receipts.next_page_url"
                            preserve-state
                            preserve-scroll
                            class="inline-flex items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary"
                        >
                            Next
                        </Link>
                    </div>
                </div>
            </Card>
        </template>

        <Modal :open="!!cancelling" title="Cancel receipt" size="compact" @update:open="onCancelOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    This posts a reversing entry for this receipt. This cannot be undone.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input v-model="cancelForm.reason" type="text" placeholder="e.g. Entered wrong amount" maxlength="500" required />
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
