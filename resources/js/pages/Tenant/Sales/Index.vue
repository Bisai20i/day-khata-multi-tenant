<script setup>
import { computed, h, onMounted, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Ban, ChevronDown, ChevronUp, ChevronsUpDown, Download, Plus, Printer, Search, SlidersHorizontal, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Badge from '@/components/ui/Badge.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Combobox from '@/components/ui/Combobox.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue';
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
            active_invoice_type: 'full',
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
const sortableColumns = { date: 'date', voucher: 'invoice_number', total: 'total' };

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

/**
 * Column header for a server-sorted column: a button, so the sort is reachable
 * by keyboard, with the arrow only lit on the column currently sorted by.
 */
function sortableHeader(columnId, label) {
    const sortKey = sortableColumns[columnId];

    return () => {
        const active = sortState.sort === sortKey;
        const Icon = !active ? ChevronsUpDown : sortState.sort_dir === 'asc' ? ChevronUp : ChevronDown;

        return h(
            'button',
            {
                type: 'button',
                class: `inline-flex cursor-pointer items-center gap-1 uppercase transition-colors duration-150 hover:text-text-strong focus-visible:outline-2 focus-visible:outline-primary ${active ? 'text-text-strong' : ''}`,
                'aria-label': `Sort by ${label}`,
                onClick: () => sortBy(sortKey),
            },
            [label, h(Icon, { class: `h-3 w-3 shrink-0 ${active ? 'text-primary' : 'text-text-faint'}` })],
        );
    };
}

const showFilters = ref(false);

const activeFilterChips = computed(() => {
    const chips = [];

    if (props.filters.from) chips.push({ key: 'from', label: `From ${formatBsDate(props.filters.from)}` });
    if (props.filters.to) chips.push({ key: 'to', label: `To ${formatBsDate(props.filters.to)}` });
    if (props.filters.customer_id) {
        const customer = props.customers.find((c) => c.id === Number(props.filters.customer_id));
        chips.push({ key: 'customer_id', label: customer?.name ?? 'Customer' });
    }
    if (props.filters.search) chips.push({ key: 'search', label: `Invoice "${props.filters.search}"` });

    return chips;
});

function removeFilter(key) {
    filterState[key] = key === 'customer_id' ? null : '';
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
function exportUrl(format) {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(queryParams())) {
        if (value !== undefined && value !== null && value !== '') params.append(key, value);
    }
    params.append('format', format);

    return `/sales/export?${params.toString()}`;
}

function printUrl(sale) {
    return `/sales/${sale.id}/print`;
}

// Prints the currently visible list via the browser's own print dialog -
// per-sale copies (Actions column) stay a separate concern.
function printList() {
    window.print();
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
    return sale.invoice_number ?? '-';
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
        header: sortableHeader('date', 'Date (BS)'),
        numeric: false,
        cell: ({ row }) => formatBsDate(row.original.date),
    },
    {
        id: 'voucher',
        header: sortableHeader('voucher', 'Invoice #'),
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
        cell: ({ row }) => row.original.customer?.name ?? '-',
    },
    {
        id: 'agent',
        header: 'Agent',
        numeric: false,
        cell: ({ row }) => row.original.agent?.name ?? '-',
    },
    {
        id: 'payment_mode',
        header: 'Payment',
        numeric: false,
        cell: ({ row }) => paymentModeLabels[row.original.payment_mode] ?? row.original.payment_mode,
    },
    {
        id: 'total',
        header: sortableHeader('total', 'Total'),
        numeric: true,
        cell: ({ row }) => formatMoney(row.original.total),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(
                Badge,
                { variant: row.original.status === 'cancelled' ? 'danger' : 'success' },
                () => (row.original.status === 'cancelled' ? 'Cancelled' : 'Posted'),
            ),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-1' }, [
                h(Tooltip, { label: 'Print sale' }, () =>
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
                    : h(Tooltip, { label: 'Cancel sale (posts reversing entry)' }, () =>
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
            <PageHeader title="Sales" description="All sales invoices. Filter, print or export them, and cancel a sale posted in error.">
                <Button variant="primary" tone="purple" @click="openCreateForm">
                    <Plus class="size-4" />
                    New sale
                </Button>
            </PageHeader>

            <Card variant="panel" class="mb-4 bg-white">
                <div class="flex items-center justify-between gap-2 md:hidden">
                    <Button variant="secondary" tone="neutral" type="button" :aria-expanded="showFilters" @click="showFilters = !showFilters">
                        <SlidersHorizontal class="size-4" />
                        Filters
                        <span v-if="activeFilterChips.length" class="bg-bg-muted px-1.5 text-[11px] text-text-strong">{{ activeFilterChips.length }}</span>
                    </Button>
                </div>
                <div :class="[showFilters ? 'mt-3 flex' : 'hidden', 'flex-wrap items-end gap-3 md:mt-0 md:flex']">
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
                        <Combobox v-model="filterState.customer_id" @update:model-value="applyFilters" :options="customerOptions" placeholder="All customers" />
                    </div>
                    <div class="min-w-[200px] flex-1">
                        <label class="mb-1 block text-xs font-semibold text-text-muted">Invoice #</label>
                        <div class="relative">
                            <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-text-faint" />
                            <Input v-model="filterState.search" type="text" placeholder="Search invoice number" class="pl-8" @keydown.enter.prevent="applyFilters" />
                        </div>
                    </div>
                    <Button variant="secondary" tone="neutral" :loading="filtering" @click="applyFilters">
                        <Search class="size-4" />
                        Filter
                    </Button>
                </div>
                <div v-if="activeFilterChips.length" class="mt-3 flex flex-wrap items-center gap-2 border-t border-border pt-3">
                    <button
                        v-for="chip in activeFilterChips"
                        :key="chip.key"
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1 border-[1.5px] border-border bg-bg-subtle px-2 py-1 text-xs font-semibold text-text-base transition-colors duration-150 hover:bg-bg-muted focus-visible:outline-2 focus-visible:outline-primary"
                        :aria-label="`Remove filter: ${chip.label}`"
                        @click="removeFilter(chip.key)"
                    >
                        {{ chip.label }}
                        <X class="size-3 text-text-muted" />
                    </button>
                    <button type="button" class="cursor-pointer text-xs font-semibold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-primary" @click="clearFilters">
                        Clear all
                    </button>
                </div>
            </Card>

            <Card variant="panel" class="bg-white">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="ml-auto flex items-center gap-2">
                        <Button variant="secondary" tone="neutral" type="button" @click="printList">
                            <Printer class="size-4" />
                            Print
                        </Button>
                        <DropdownMenu align="end">
                            <template #trigger>
                                <Button variant="secondary" tone="neutral" type="button">
                                    <Download class="size-4" />
                                    Export
                                    <ChevronDown class="size-3.5" />
                                </Button>
                            </template>
                            <DropdownMenuItem as="a" :href="exportUrl('csv')">CSV</DropdownMenuItem>
                            <DropdownMenuItem as="a" :href="exportUrl('xlsx')">Excel</DropdownMenuItem>
                        </DropdownMenu>
                    </div>
                </div>

                <div v-if="sales.data.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                    <template v-if="hasActiveFilters">
                        <p class="text-sm font-semibold text-text-strong">No sales match these filters</p>
                        <Button variant="secondary" tone="neutral" @click="clearFilters">
                            <X class="size-4" />
                            Clear filters
                        </Button>
                    </template>
                    <template v-else>
                        <p class="text-sm font-semibold text-text-strong">No sales yet</p>
                        <p class="text-xs text-text-muted">Create your first sale and it will be listed here.</p>
                        <Button variant="primary" tone="purple" @click="openCreateForm">
                            <Plus class="size-4" />
                            New sale
                        </Button>
                    </template>
                </div>
                <DataTable v-else :columns="columns" :data="sales.data" :page-size="Math.max(sales.data.length, 1)" empty-message="No sales" />

                <!-- Server-computed SQL sums for the whole filtered set, not
                     just this page (audit section 4 polish). -->
                <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4 border-t-[1.5px] border-border pt-3 text-sm">
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

                <p v-if="sales.data.length > 0" class="mt-3 text-xs text-text-muted" aria-live="polite">Showing {{ sales.from }}–{{ sales.to }} of {{ sales.total }}</p>
                <nav v-if="sales.data.length > 0 && sales.last_page > 1" aria-label="Sales pagination" class="mt-3 flex items-center justify-end gap-2">
                    <Link
                        v-if="sales.prev_page_url"
                        :href="sales.prev_page_url"
                        preserve-state
                        preserve-scroll
                        aria-label="Previous page"
                        class="inline-flex items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary"
                    >
                        Previous
                    </Link>
                    <span v-else aria-disabled="true" class="inline-flex cursor-not-allowed items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-faint opacity-40">Previous</span>
                    <span class="text-xs text-text-muted" aria-current="page">Page {{ sales.current_page }} of {{ sales.last_page }}</span>
                    <Link
                        v-if="sales.next_page_url"
                        :href="sales.next_page_url"
                        preserve-state
                        preserve-scroll
                        aria-label="Next page"
                        class="inline-flex items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary"
                    >
                        Next
                    </Link>
                    <span v-else aria-disabled="true" class="inline-flex cursor-not-allowed items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-faint opacity-40">Next</span>
                </nav>
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
                <Button variant="secondary" tone="neutral" type="button" @click="cancelling = null">Back</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="cancelForm.processing" @click="submitCancel">
                    Confirm cancellation
                </Button>
            </template>
        </Modal>
    </div>
</template>
