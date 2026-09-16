<script setup>
import { computed, h, onMounted, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Ban, Plus, Printer, Search, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Combobox from '@/components/ui/Combobox.vue';
import { useToast } from '@/composables/useToast';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import Create from './Create.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    sales: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    filters: {
        type: Object,
        default: () => ({ from: null, to: null, customer_id: null, search: null, sort: 'date', sort_dir: 'desc' }),
    },
    // Exact SQL sums over the whole filtered set, computed server-side
    // (SaleController::filteredTotals()) - never a page's worth of
    // client-side addition (audit section 4 polish, "totals row").
    totals: {
        type: Object,
        default: () => ({ taxable_amount: '0.00', nontaxable_amount: '0.00', vat_amount: '0.00', total: '0.00' }),
    },
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    tdsAccounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    agents: { type: Array, default: () => [] },
    // Forwarded straight to Create.vue - see its own props doc (audit
    // section 3 "Sales" walk-in customer, section 4 polish "note templates").
    noteTemplates: { type: Array, default: () => [] },
    walkInCustomerId: { type: Number, default: null },
    invoiceSettings: {
        type: Object,
        default: () => ({
            default_vat_rate: '13.00',
            default_store_id: null,
            sale_full_enabled: true,
            sale_abbreviated_enabled: true,
            sale_pan_enabled: true,
        }),
    },
});

const customerOptions = computed(() => props.customers.map((customer) => ({ value: customer.id, label: customer.name })));

const filterState = reactive({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    customer_id: props.filters.customer_id ?? null,
    // Invoice number search (audit section 4 polish) - matched server-side
    // against the stored number (C7).
    search: props.filters.search ?? '',
});
const filtering = ref(false);

/**
 * Sorting is server-side, over the whole filtered set: the table's own
 * header sort would only reorder the 25 rows of the current page, which on a
 * list this long reads as a wrong answer.
 */
const sortColumns = [
    { value: 'date', label: 'Date' },
    { value: 'invoice_number', label: 'Invoice #' },
    { value: 'total', label: 'Total' },
];

const sortState = reactive({
    sort: props.filters.sort ?? 'date',
    sort_dir: props.filters.sort_dir ?? 'desc',
});

function queryParams(overrides = {}) {
    return {
        from: filterState.from || undefined,
        to: filterState.to || undefined,
        customer_id: filterState.customer_id || undefined,
        search: filterState.search || undefined,
        sort: sortState.sort || undefined,
        sort_dir: sortState.sort_dir || undefined,
        ...overrides,
    };
}

function reload() {
    router.get(window.location.pathname, queryParams(), {
        preserveState: true,
        preserveScroll: true,
        onStart: () => (filtering.value = true),
        onFinish: () => (filtering.value = false),
    });
}

function applyFilters() {
    reload();
}

// Clicking the column you are already sorted by flips the direction, the way
// a sortable table header behaves.
function sortBy(column) {
    if (sortState.sort === column) {
        sortState.sort_dir = sortState.sort_dir === 'asc' ? 'desc' : 'asc';
    } else {
        sortState.sort = column;
        sortState.sort_dir = column === 'date' ? 'desc' : 'asc';
    }

    reload();
}

function clearFilters() {
    filterState.from = '';
    filterState.to = '';
    filterState.customer_id = null;
    filterState.search = '';
    sortState.sort = 'date';
    sortState.sort_dir = 'desc';
    router.get(
        window.location.pathname,
        {},
        { preserveState: true, preserveScroll: true, onStart: () => (filtering.value = true), onFinish: () => (filtering.value = false) },
    );
}

const hasActiveFilters = computed(
    () => !!(props.filters.from || props.filters.to || props.filters.customer_id || props.filters.search),
);

// The export covers the same filtered, searched and sorted set the page is
// showing, all rows and not just this page (SaleController::export()).
const exportUrl = computed(() => {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(queryParams())) {
        if (value !== undefined && value !== null && value !== '') params.append(key, value);
    }

    const query = params.toString();

    return query ? `/sales/export?${query}` : '/sales/export';
});

/**
 * "Save & Print N copies" (audit section 4 polish): the print action carries
 * how many copies to produce, and the server records one print-log row per
 * copy, so copy 1 prints as the Original and 2..N as "Copy of Original"
 * (C9).
 */
const printCopyOptions = [1, 2, 3, 4, 5];
const printCopies = ref(1);

function printUrl(sale) {
    return printCopies.value > 1 ? `/sales/${sale.id}/print?copies=${printCopies.value}` : `/sales/${sale.id}/print`;
}

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Sales');

// Posting/cancelling a sale redirects back to this same route + component,
// which Inertia re-renders in place without an onMounted re-run - watch the
// flash prop instead (same pattern as every other module in this app).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);

// Restores an in-progress New sale draft after Create.vue's inline
// "+ New customer" modal bounces the browser away to /customers and back
// here (see Create.vue's submitCustomer()) - this component fully
// unmounts/remounts across that round trip, so the draft is stashed in
// sessionStorage right before the bounce and picked back up here.
const DRAFT_KEY = 'sales-create-draft';
const initialDraft = ref(null);

onMounted(() => {
    let raw;
    try {
        raw = sessionStorage.getItem(DRAFT_KEY);
    } catch {
        return;
    }
    if (!raw) return;

    try {
        sessionStorage.removeItem(DRAFT_KEY);
        initialDraft.value = JSON.parse(raw);
        showCreateForm.value = true;
    } catch {
        // malformed sessionStorage payload - nothing to recover, ignore.
    }
});

function openCreateForm() {
    initialDraft.value = null;
    showCreateForm.value = true;
}

function closeCreateForm() {
    initialDraft.value = null;
    showCreateForm.value = false;
}

const invoiceTypeLabels = {
    full: 'Full',
    abbreviated: 'Abbreviated',
    pan: 'PAN',
};

const paymentModeLabels = {
    cash: 'Cash',
    bank: 'Bank',
    partial: 'Partial',
    credit: 'Credit',
};

// The invoice number is whatever was stored on the row when the bill was
// issued (CONTRACTS C7). This page used to rebuild it from a hardcoded
// SL/SLA map, so a PAN bill showed SL-n on screen and SLP-n on paper, and
// changing a prefix in Settings silently renumbered every past invoice.
function invoiceLabel(sale) {
    return sale.invoice_number ?? '—';
}

const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });

function openCancel(sale) {
    cancelling.value = sale;
    cancelForm.reset();
    cancelForm.clearErrors();
}

function onCancelOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    cancelForm.post(`/sales/${cancelling.value.id}/cancel`, {
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
        cell: ({ row }) => formatBsDate(row.original.date),
    },
    {
        id: 'voucher',
        header: 'Invoice #',
        numeric: false,
        cell: ({ row }) => invoiceLabel(row.original),
    },
    {
        id: 'invoice_type',
        header: 'Type',
        numeric: false,
        cell: ({ row }) => invoiceTypeLabels[row.original.invoice_type] ?? row.original.invoice_type,
    },
    {
        id: 'customer',
        header: 'Customer',
        numeric: false,
        cell: ({ row }) => row.original.customer?.name ?? '—',
    },
    {
        id: 'agent',
        header: 'Agent',
        numeric: false,
        cell: ({ row }) => row.original.agent?.name ?? '—',
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
                            href: printUrl(row.original),
                            target: '_blank',
                            rel: 'noopener',
                            class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                            'aria-label': 'Print sale',
                        },
                        [h(Printer, { class: 'h-[13px] w-[13px]' })],
                    ),
                ),
                row.original.status === 'cancelled'
                    ? null
                    : h(Tooltip, { label: 'Cancel sale' }, () =>
                          h(
                              'button',
                              {
                                  type: 'button',
                                  class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-danger-bg hover:text-danger',
                                  'aria-label': 'Cancel sale',
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
                :customers="customers"
                :items="items"
                :bank-accounts="bankAccounts"
                :tds-accounts="tdsAccounts"
                :stores="stores"
                :agents="agents"
                :note-templates="noteTemplates"
                :walk-in-customer-id="walkInCustomerId"
                :invoice-settings="invoiceSettings"
                :initial-draft="initialDraft"
                @cancel="closeCreateForm"
                @posted="closeCreateForm"
            />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Sales</h2>
                <Button variant="primary" tone="purple" @click="openCreateForm">
                    <Plus class="size-4" />
                    New sale
                </Button>
            </div>

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
                    <div class="min-w-[200px]">
                        <label class="mb-1 block text-xs font-semibold text-text-muted">Invoice #</label>
                        <Input v-model="filterState.search" type="text" placeholder="Search invoice number" @keydown.enter.prevent="applyFilters" />
                    </div>
                    <Button variant="primary" tone="purple" :loading="filtering" @click="applyFilters">
                        <Search class="size-4" />
                        Filter
                    </Button>
                    <Button v-if="hasActiveFilters" variant="secondary" tone="purple" @click="clearFilters">
                        <X class="size-4" />
                        Clear
                    </Button>
                    <a :href="exportUrl">
                        <Button variant="secondary" tone="purple" type="button">Export</Button>
                    </a>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2 border-t-[1.5px] border-border pt-3">
                    <span class="text-xs font-semibold text-text-muted">Sort by</span>
                    <Button
                        v-for="column in sortColumns"
                        :key="column.value"
                        :variant="filters.sort === column.value ? 'primary' : 'secondary'"
                        tone="purple"
                        type="button"
                        @click="sortBy(column.value)"
                    >
                        {{ column.label }}
                        <span v-if="filters.sort === column.value">{{ filters.sort_dir === 'asc' ? '↑' : '↓' }}</span>
                    </Button>
                </div>
            </Card>

            <Card variant="panel">
                <div class="mb-3 flex flex-wrap items-center justify-end gap-2">
                    <label class="text-xs font-semibold text-text-muted" for="print-copies">Print copies</label>
                    <select
                        id="print-copies"
                        v-model="printCopies"
                        class="border-[1.5px] border-border bg-white px-2 py-1 text-xs font-semibold text-text-base"
                    >
                        <option v-for="option in printCopyOptions" :key="option" :value="option">{{ option }}</option>
                    </select>
                    <span class="text-xs text-text-faint">Copy 1 prints as the original, the rest as copies.</span>
                </div>

                <DataTable :columns="columns" :data="sales.data" :page-size="Math.max(sales.data.length, 1)" empty-message="No sales yet" />

                <!-- Server-computed SQL sums for the whole filtered set, not
                     just this page (audit section 4 polish). -->
                <div class="mt-3 grid grid-cols-4 gap-3 border-t-[1.5px] border-border pt-3 text-sm">
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Taxable (filtered)</p>
                        <p class="font-bold text-text-strong">{{ formatMoney(totals.taxable_amount) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Non-taxable (filtered)</p>
                        <p class="font-bold text-text-strong">{{ formatMoney(totals.nontaxable_amount) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">VAT (filtered)</p>
                        <p class="font-bold text-text-strong">{{ formatMoney(totals.vat_amount) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Total (filtered)</p>
                        <p class="font-bold text-text-strong">{{ formatMoney(totals.total) }}</p>
                    </div>
                </div>

                <div v-if="sales.data.length > 0" class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-text-muted">Showing {{ sales.from }}–{{ sales.to }} of {{ sales.total }}</p>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="sales.prev_page_url"
                            :href="sales.prev_page_url"
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
                        <span class="text-xs text-text-muted">Page {{ sales.current_page }} of {{ sales.last_page }}</span>
                        <Link
                            v-if="sales.next_page_url"
                            :href="sales.next_page_url"
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

        <Modal :open="!!cancelling" title="Cancel sale" size="compact" @update:open="onCancelOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    This posts a reversing entry for this sale. This cannot be undone.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input v-model="cancelForm.reason" type="text" placeholder="Reason for cancellation" required />
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
