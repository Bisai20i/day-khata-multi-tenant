<script setup>
import { computed, h, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Ban, Check, Plus, Printer, Search, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Combobox from '@/components/ui/Combobox.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import Create from './Create.vue';

defineOptions({ layout: AppLayout });

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
    // Forwarded to Create.vue's unlinked mode only - see its own props doc
    // (audit section 3 "Sales", "returns without a bill").
    items: { type: Array, default: () => [] },
    walkInCustomerId: { type: Number, default: null },
    invoiceSettings: { type: Object, default: () => ({ default_vat_rate: '13.00' }) },
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
useLayoutChrome('Sales Returns');

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');

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
        message: `Approve this return request against sale #${request.sale_id}? This posts the credit note (and any refund) immediately, adds the returned stock back, and cannot be undone.`,
        confirmLabel: 'Approve and post',
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
        cell: ({ row }) => row.original.sale?.customer?.name ?? '-',
    },
    {
        id: 'requested_by',
        header: 'Requested by',
        numeric: false,
        cell: ({ row }) => row.original.creator?.name ?? '-',
    },
    {
        id: 'reason',
        header: 'Reason',
        numeric: false,
        cell: ({ row }) => row.original.reason ?? '-',
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
                h(Tooltip, { label: 'Approve and post return' }, () =>
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
        // An unlinked note points at no invoice at all (C7 "returns without
        // a bill"), so it says so rather than inventing a "#null".
        cell: ({ row }) =>
            row.original.is_unlinked
                ? 'No original bill'
                : (row.original.sale?.invoice_number ?? `#${row.original.sale_id}`),
    },
    {
        id: 'customer',
        header: 'Customer',
        numeric: false,
        // Linked notes read the customer off the sale; an unlinked one
        // carries its own.
        cell: ({ row }) => row.original.sale?.customer?.name ?? row.original.customer?.name ?? '-',
    },
    {
        id: 'reason',
        header: 'Reason',
        numeric: false,
        cell: ({ row }) => row.original.reason ?? '-',
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
            h(Badge, { variant: row.original.status === 'cancelled' ? 'neutral' : 'success', pill: true }, () =>
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
                    : h(Tooltip, { label: 'Cancel return (posts a reversing entry)' }, () =>
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
    <div>
        <template v-if="showCreateForm">
            <Create
                :sales="sales"
                :selected-sale="selectedSale"
                :sale-search="saleSearch"
                :refund-accounts="refundAccounts"
                :stores="stores"
                :items="items"
                :customers="customers"
                :walk-in-customer-id="walkInCustomerId"
                :invoice-settings="invoiceSettings"
                :mode="createMode"
                @cancel="createMode = null"
                @posted="createMode = null"
            />
        </template>

        <template v-else>
            <PageHeader
                title="Sales returns"
                description="Goods a customer sends back. A return issues a credit note, adds the stock back and reduces what the customer owes you."
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Button variant="secondary" tone="purple" @click="createMode = 'request'">
                        <Plus class="size-4" aria-hidden="true" />
                        Request return for approval
                    </Button>
                    <!-- Goods back with no bill this system ever issued
                         (audit section 3 "Sales") - posts immediately, so it
                         has no request/approve counterpart. -->
                    <Button variant="secondary" tone="purple" @click="createMode = 'unlinked'">
                        <Plus class="size-4" />
                        Return without a bill
                    </Button>
                    <Button variant="primary" tone="purple" @click="createMode = 'post'">
                        <Plus class="size-4" />
                        New sales return
                    </Button>
                </div>
            </PageHeader>

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
                        <label class="mb-1 block text-xs font-semibold text-text-muted">From date (BS)</label>
                        <NepaliDateInput v-model="filterState.from" />
                    </div>
                    <div class="min-w-[160px]">
                        <label class="mb-1 block text-xs font-semibold text-text-muted">To date (BS)</label>
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
                        Clear filters
                    </Button>
                </div>
            </Card>

            <Card variant="panel">
                <div v-if="returns.data.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                    <template v-if="hasActiveFilters">
                        <p class="text-sm font-semibold text-text-strong">No sales returns match these filters</p>
                        <Button variant="secondary" tone="purple" @click="clearFilters">
                            <X class="size-4" aria-hidden="true" />
                            Clear filters
                        </Button>
                    </template>
                    <template v-else>
                        <p class="text-sm font-semibold text-text-strong">No sales returns yet</p>
                        <p class="text-xs text-text-muted">When a customer sends goods back, record a return and it will be listed here.</p>
                        <Button variant="primary" tone="purple" @click="createMode = 'post'">
                            <Plus class="size-4" aria-hidden="true" />
                            New sales return
                        </Button>
                    </template>
                </div>
                <DataTable v-else :columns="columns" :data="returns.data" :page-size="Math.max(returns.data.length, 1)" empty-message="No sales returns" />

                <p v-if="returns.data.length > 0" class="mt-3 text-xs text-text-muted" aria-live="polite">Showing {{ returns.from }}–{{ returns.to }} of {{ returns.total }}</p>
                <nav v-if="returns.data.length > 0 && returns.last_page > 1" aria-label="Sales returns pagination" class="mt-3 flex items-center justify-end gap-2">
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="returns.prev_page_url"
                            :href="returns.prev_page_url"
                            preserve-state
                            preserve-scroll
                            aria-label="Previous page"
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
                        <span class="text-xs text-text-muted" aria-current="page">Page {{ returns.current_page }} of {{ returns.last_page }}</span>
                        <Link
                            v-if="returns.next_page_url"
                            :href="returns.next_page_url"
                            preserve-state
                            preserve-scroll
                            aria-label="Next page"
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
                </nav>
            </Card>
        </template>

        <Modal :open="!!cancelling" title="Cancel sales return" size="compact" @update:open="onCancelOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    This posts a reversing entry: the credit note is voided, returned stock is taken out again, and the customer owes you the amount once more (any refund settlement is reversed too). This cannot be undone.
                </p>
                <div>
                    <label for="return-cancel-reason" class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input id="return-cancel-reason" v-model="cancelForm.reason" type="text" placeholder="Reason for cancellation" maxlength="500" required />
                    <p v-if="cancelForm.errors.reason" class="mt-1 text-sm text-danger" role="alert">{{ cancelForm.errors.reason }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="cancelling = null">Keep return</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="cancelForm.processing" :loading="cancelForm.processing" @click="submitCancel">
                    Cancel return
                </Button>
            </template>
        </Modal>

        <Modal :open="!!rejecting" title="Reject return request" size="compact" @update:open="onRejectOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitReject">
                <p class="text-sm text-text-muted">
                    Nothing was posted for this request, so rejecting it has no ledger/stock effect - it just records why.
                </p>
                <div>
                    <label for="return-reject-reason" class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input id="return-reject-reason" v-model="rejectForm.reason" type="text" placeholder="Reason for rejection" maxlength="500" required />
                    <p v-if="rejectForm.errors.reason" class="mt-1 text-sm text-danger" role="alert">{{ rejectForm.errors.reason }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="rejecting = null">Keep request</Button>
                <Button variant="primary" tone="danger" type="button" :disabled="rejectForm.processing" :loading="rejectForm.processing" @click="submitReject">
                    Reject request
                </Button>
            </template>
        </Modal>
    </div>
</template>
