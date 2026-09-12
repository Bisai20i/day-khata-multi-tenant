<script setup>
import { computed, h, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Ban, Check, Plus, Printer, Search, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Combobox from '@/components/ui/Combobox.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { navGroups } from '@/lib/nav-items.js';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import Create from './Create.vue';

const props = defineProps({
    returns: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    pendingRequests: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ from: null, to: null, customer_id: null }),
    },
    // Server-searched, paginated picker for the "which invoice?" step of
    // the create form (it used to ship every posted sale to the browser).
    sales: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, prev_page_url: null, next_page_url: null }),
    },
    selectedSale: { type: Object, default: null },
    saleSearch: { type: String, default: '' },
    customers: { type: Array, default: () => [] },
    refundAccounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
});

const customerOptions = computed(() => props.customers.map((customer) => ({ value: customer.id, label: customer.name })));

const filterState = reactive({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    customer_id: props.filters.customer_id ?? null,
});
const filtering = ref(false);

function applyFilters() {
    router.get(
        window.location.pathname,
        { from: filterState.from || undefined, to: filterState.to || undefined, customer_id: filterState.customer_id || undefined },
        { preserveState: true, preserveScroll: true, onStart: () => (filtering.value = true), onFinish: () => (filtering.value = false) },
    );
}

function clearFilters() {
    filterState.from = '';
    filterState.to = '';
    filterState.customer_id = null;
    router.get(
        window.location.pathname,
        {},
        { preserveState: true, preserveScroll: true, onStart: () => (filtering.value = true), onFinish: () => (filtering.value = false) },
    );
}

const hasActiveFilters = computed(() => !!(props.filters.from || props.filters.to || props.filters.customer_id));

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

// null hides the Create form; otherwise 'post' (direct one-step return) or
// 'request' (submits pending, needs approval - see Create.vue's own `mode`
// prop and SalesReturn::request()'s docblock).
const createMode = ref(null);
const showCreateForm = computed(() => createMode.value !== null);

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

// Keyed by request id -> true while that row's Approve button is in flight,
// so only the clicked row shows a spinner.
const approving = reactive({});

async function approveRequest(request) {
    const confirmed = await confirm({
        message: `Approve this return request against sale #${request.sale_id}? This posts the credit note (and any refund) immediately and cannot be undone.`,
        confirmLabel: 'Approve',
    });
    if (!confirmed) return;

    approving[request.id] = true;
    router.post(
        `/sales-returns/${request.id}/approve`,
        {},
        { preserveScroll: true, onFinish: () => (approving[request.id] = false) },
    );
}

const rejecting = ref(null);
const rejectForm = useForm({ reason: '' });

function openReject(request) {
    rejecting.value = request;
    rejectForm.reset();
    rejectForm.clearErrors();
}

function onRejectOpenChange(value) {
    if (!value) rejecting.value = null;
}

function submitReject() {
    rejectForm.post(`/sales-returns/${rejecting.value.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => {
            rejecting.value = null;
        },
    });
}

const requestColumns = [
    {
        id: 'date',
        header: 'Date',
        numeric: false,
        cell: ({ row }) => formatBsDate(row.original.date),
    },
    {
        id: 'request',
        header: 'Request',
        numeric: false,
        // A pending or rejected request is never a numbered credit note (C7).
        cell: ({ row }) => `Return request #${row.original.id}`,
    },
    {
        id: 'sale',
        header: 'Against invoice',
        numeric: false,
        cell: ({ row }) => row.original.sale?.invoice_number ?? `#${row.original.sale_id}`,
    },
    {
        id: 'customer',
        header: 'Customer',
        numeric: false,
        cell: ({ row }) => row.original.sale?.customer?.name ?? '—',
    },
    {
        id: 'requested_by',
        header: 'Requested by',
        numeric: false,
        cell: ({ row }) => row.original.creator?.name ?? '—',
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
        cell: ({ row }) => formatMoney(row.original.total),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            row.original.status === 'rejected'
                ? h('div', { class: 'flex flex-col gap-0.5' }, [
                      h(Badge, { variant: 'danger', pill: true }, () => 'Rejected'),
                      row.original.rejection_reason
                          ? h('span', { class: 'text-[11px] text-text-faint' }, row.original.rejection_reason)
                          : null,
                  ])
                : h(Badge, { variant: 'warning', pill: true }, () => 'Pending'),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) => {
            if (row.original.status !== 'pending') {
                return null;
            }

            return h('div', { class: 'flex items-center gap-1' }, [
                h(Tooltip, { label: 'Approve' }, () =>
                    h(
                        Button,
                        {
                            variant: 'icon',
                            tone: 'success',
                            loading: !!approving[row.original.id],
                            'aria-label': 'Approve return request',
                            onClick: () => approveRequest(row.original),
                        },
                        () => h(Check, { class: 'size-[13px]' }),
                    ),
                ),
                h(Tooltip, { label: 'Reject' }, () =>
                    h(
                        Button,
                        {
                            variant: 'icon',
                            tone: 'danger',
                            'aria-label': 'Reject return request',
                            onClick: () => openReject(row.original),
                        },
                        () => h(X, { class: 'size-[13px]' }),
                    ),
                ),
            ]);
        },
    },
];

const columns = [
    {
        id: 'date',
        header: 'Date',
        numeric: false,
        cell: ({ row }) => formatBsDate(row.original.date),
    },
    {
        id: 'credit_note',
        header: 'Credit note #',
        numeric: false,
        // The stored number, never re-derived from settings at display time (C7).
        cell: ({ row }) => row.original.credit_note_number ?? '-',
    },
    {
        id: 'sale',
        header: 'Against invoice',
        numeric: false,
        cell: ({ row }) => row.original.sale?.invoice_number ?? `#${row.original.sale_id}`,
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
        cell: ({ row }) => formatMoney(row.original.total),
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
            h('div', { class: 'flex items-center gap-1' }, [
                h(Tooltip, { label: 'Print' }, () =>
                    h(
                        'a',
                        {
                            href: `/sales-returns/${row.original.id}/print`,
                            target: '_blank',
                            rel: 'noopener',
                            class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                            'aria-label': 'Print return',
                        },
                        [h(Printer, { class: 'h-[13px] w-[13px]' })],
                    ),
                ),
                // Cancelling reverses real money, so the route is admin-only
                // (C5) - do not offer a button that would 403.
                row.original.status === 'cancelled' || !isAdmin.value
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
            ]),
    },
];
</script>

<template>
    <AppLayout title="Sales Returns" :nav-items="navItems">
        <template v-if="showCreateForm">
            <Create
                :sales="sales"
                :selected-sale="selectedSale"
                :sale-search="saleSearch"
                :refund-accounts="refundAccounts"
                :stores="stores"
                :mode="createMode"
                @cancel="createMode = null"
                @posted="createMode = null"
            />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Sales Returns</h2>
                <div class="flex items-center gap-2">
                    <Button variant="secondary" tone="purple" @click="createMode = 'request'">
                        <Plus class="size-4" />
                        Request return
                    </Button>
                    <Button variant="primary" tone="purple" @click="createMode = 'post'">
                        <Plus class="size-4" />
                        New return
                    </Button>
                </div>
            </div>

            <Card v-if="pendingRequests.length > 0" variant="panel" class="mb-4">
                <h3 class="mb-3 text-sm font-bold text-text-strong">Pending requests</h3>
                <DataTable
                    :columns="requestColumns"
                    :data="pendingRequests"
                    :page-size="Math.max(pendingRequests.length, 1)"
                    empty-message="No pending return requests"
                />
            </Card>

            <Card variant="panel" class="mb-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-[160px]">
                        <label class="mb-1 block text-xs font-semibold text-text-muted">From</label>
                        <NepaliDateInput v-model="filterState.from" />
                    </div>
                    <div class="min-w-[160px]">
                        <label class="mb-1 block text-xs font-semibold text-text-muted">To</label>
                        <NepaliDateInput v-model="filterState.to" />
                    </div>
                    <div class="min-w-[220px]">
                        <label class="mb-1 block text-xs font-semibold text-text-muted">Customer</label>
                        <Combobox v-model="filterState.customer_id" :options="customerOptions" placeholder="All customers" />
                    </div>
                    <Button variant="primary" tone="purple" :loading="filtering" @click="applyFilters">
                        <Search class="size-4" />
                        Filter
                    </Button>
                    <Button v-if="hasActiveFilters" variant="secondary" tone="purple" @click="clearFilters">
                        <X class="size-4" />
                        Clear
                    </Button>
                </div>
            </Card>

            <Card variant="panel">
                <DataTable :columns="columns" :data="returns.data" :page-size="Math.max(returns.data.length, 1)" empty-message="No sales returns yet" />

                <div v-if="returns.data.length > 0" class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-text-muted">Showing {{ returns.from }}–{{ returns.to }} of {{ returns.total }}</p>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="returns.prev_page_url"
                            :href="returns.prev_page_url"
                            preserve-state
                            preserve-scroll
                            class="inline-flex items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary"
                        >
                            Previous
                        </Link>
                        <span
                            v-else
                            class="inline-flex cursor-not-allowed items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-faint opacity-40"
                        >
                            Previous
                        </span>
                        <span class="text-xs text-text-muted">Page {{ returns.current_page }} of {{ returns.last_page }}</span>
                        <Link
                            v-if="returns.next_page_url"
                            :href="returns.next_page_url"
                            preserve-state
                            preserve-scroll
                            class="inline-flex items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary"
                        >
                            Next
                        </Link>
                        <span
                            v-else
                            class="inline-flex cursor-not-allowed items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-faint opacity-40"
                        >
                            Next
                        </span>
                    </div>
                </div>
            </Card>
        </template>

        <Modal :open="!!cancelling" title="Cancel sales return" size="compact" @update:open="onCancelOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    This posts a reversing entry for this return (and its refund settlement, if one was recorded). This cannot be undone.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input v-model="cancelForm.reason" type="text" placeholder="Reason for cancellation" maxlength="500" required />
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

        <Modal :open="!!rejecting" title="Reject return request" size="compact" @update:open="onRejectOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitReject">
                <p class="text-sm text-text-muted">
                    Nothing was posted for this request, so rejecting it has no ledger/stock effect - it just records why.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input v-model="rejectForm.reason" type="text" placeholder="Reason for rejection" maxlength="500" required />
                    <p v-if="rejectForm.errors.reason" class="mt-1 text-sm text-danger">{{ rejectForm.errors.reason }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="rejecting = null">Back</Button>
                <Button variant="primary" tone="danger" type="button" :disabled="rejectForm.processing" @click="submitReject">
                    Confirm rejection
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
