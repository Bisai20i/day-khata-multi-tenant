<script setup>
import { computed, h, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Ban, ChevronDown, ChevronUp, ChevronsUpDown, Download, Eye, Plus, Printer, Search, SlidersHorizontal, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Combobox from '@/components/ui/Combobox.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue';
import { useToast } from '@/composables/useToast';
import { formatMoney, sumMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import Create from './Create.vue';
import CashBankCreate from './CashBankCreate.vue';
import { useOpenFiscalYear } from '@/composables/useOpenFiscalYear';
import { usePermissions } from '@/composables/usePermissions';

// A manually posted Journal voucher and the five plain cash/bank vouchers
// (Cash Receipt, Cash Payment, Bank Receipt, Bank Payment, Contra - T14)
// are the only voucher types with no owning module record, so both are
// cancellable from this generic screen (JournalVoucher::
// manuallyCancellableTypes()). Every other type belongs to a module (Sale,
// Purchase, Receipt, ...) and must be cancelled from its own record.
const MANUALLY_CANCELLABLE_TYPES = ['journal', 'cash_receipt', 'cash_payment', 'bank_receipt', 'bank_payment', 'contra'];

defineOptions({ layout: AppLayout });

const { hasOpenFiscalYear } = useOpenFiscalYear();

const props = defineProps({
    // Server-paginated (JournalVoucherController::index()), 25 per page.
    journalVouchers: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    filters: {
        type: Object,
        default: () => ({ from: null, to: null, voucher_type: null, search: null, sort: 'date', sort_dir: 'desc' }),
    },
    accounts: {
        type: Array,
        default: () => [],
    },
    correctionFiscalYear: {
        type: Object,
        default: null,
    },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Journal Vouchers');

// UI mirrors of the route gates (routes/tenant-ledger.php); the server
// still refuses each action on its own.
const { can } = usePermissions();
const canCreateJournal = computed(() => can('journal_vouchers.create'));
const canCreateCashBank = computed(() => can('cash_bank_vouchers.create'));
const canCancelVoucher = computed(() => can('journal_vouchers.cancel'));
const canPrintVoucher = computed(() => can('journal_vouchers.print'));
const canExport = computed(() => can('journal_vouchers.export'));

// Flash status is watched (not just read on mount) because posting a voucher
// redirects back to this same route + component, which Inertia re-renders
// in place without an onMounted re-run.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

// There is no GET /journal-vouchers/create route on the backend (only index +
// store exist, and route files are off-limits for this task), so the create
// form is not a separate page. Instead it's toggled in-place on this same
// /journal-vouchers route: clicking "New journal voucher" swaps the list view
// for the <Create> component, and posting redirects back to this same index
// route, which Inertia re-renders with fresh props before we flip back to the
// list.
const showCreateForm = ref(false);
const showCashBankForm = ref(false);

// Every App\Enums\VoucherType case - vouchers posted by other modules
// (sales, purchases, receipts, ...) are listed here too.
const voucherTypeLabels = {
    opening_balance: 'Opening Balance',
    journal: 'Journal',
    closing_entry: 'Closing Entry',
    roll_forward_adjustment: 'Roll Forward Adjustment',
    reversal: 'Reversal',
    sale: 'Sale',
    sale_abbreviated: 'Sale (Abbreviated)',
    sale_pan: 'Sale (PAN)',
    sale_return: 'Sales Return',
    purchase: 'Purchase',
    purchase_return: 'Purchase Return',
    capital_purchase: 'Capital Purchase',
    capital_sale: 'Capital Sale',
    fixed_asset_purchase: 'Fixed Asset Purchase',
    depreciation: 'Depreciation',
    asset_disposal: 'Asset Disposal',
    receipt: 'Receipt',
    payment: 'Payment',
    cash_receipt: 'Cash Receipt',
    cash_payment: 'Cash Payment',
    bank_receipt: 'Bank Receipt',
    bank_payment: 'Bank Payment',
    contra: 'Contra',
};

const voucherTypeOptions = Object.entries(voucherTypeLabels).map(([value, label]) => ({ value, label }));

// --- Filters, search and sort ---------------------------------------------
// Server-side over the whole ledger, same as Sales/Index.vue - the old
// client-side table only ever sorted/paged whatever had been loaded.
const filterState = reactive({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    voucher_type: props.filters.voucher_type ?? null,
    // Voucher number ("JV-12" or "12") or any part of the narration.
    search: props.filters.search ?? '',
});
const filtering = ref(false);

const sortableColumns = { dateBs: 'date', voucher_number: 'voucher_number' };

const sortState = reactive({
    sort: props.filters.sort ?? 'date',
    sort_dir: props.filters.sort_dir ?? 'desc',
});

function queryParams() {
    return {
        from: filterState.from || undefined,
        to: filterState.to || undefined,
        voucher_type: filterState.voucher_type || undefined,
        search: filterState.search || undefined,
        sort: sortState.sort || undefined,
        sort_dir: sortState.sort_dir || undefined,
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

// Clicking the column you are already sorted by flips the direction.
function sortBy(column) {
    if (sortState.sort === column) {
        sortState.sort_dir = sortState.sort_dir === 'asc' ? 'desc' : 'asc';
    } else {
        sortState.sort = column;
        sortState.sort_dir = column === 'date' ? 'desc' : 'asc';
    }

    reload();
}

/** Column header for a server-sorted column - see Sales/Index.vue. */
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
    if (props.filters.voucher_type) chips.push({ key: 'voucher_type', label: voucherTypeLabel(props.filters.voucher_type) });
    if (props.filters.search) chips.push({ key: 'search', label: `"${props.filters.search}"` });

    return chips;
});

function removeFilter(key) {
    filterState[key] = key === 'voucher_type' ? null : '';
    reload();
}

function clearFilters() {
    filterState.from = '';
    filterState.to = '';
    filterState.voucher_type = null;
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
    () => !!(props.filters.from || props.filters.to || props.filters.voucher_type || props.filters.search),
);

// The export covers the same filtered, searched and sorted set the page is
// showing, all rows and not just this page (JournalVoucherController::export()).
function exportUrl(format) {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(queryParams())) {
        if (value !== undefined && value !== null && value !== '') params.append(key, value);
    }
    params.append('format', format);

    return `/journal-vouchers/export?${params.toString()}`;
}

function printList() {
    window.print();
}

// Ledger-side labels only. These are NOT the printed document numbers: a
// sale's printed invoice number comes from its own stored invoice_number
// (CONTRACTS C7), and this screen is the raw voucher ledger.
const voucherTypePrefixes = {
    opening_balance: 'OB',
    journal: 'JV',
    closing_entry: 'CL',
    roll_forward_adjustment: 'RFA',
    reversal: 'REV',
    cash_receipt: 'CR',
    cash_payment: 'CP',
    bank_receipt: 'BR',
    bank_payment: 'BP',
    contra: 'CTR',
};

// The voucher model is serialised whole, so `date` arrives as a full ISO
// timestamp; the calendar day is all this screen shows.
function adDate(value) {
    return typeof value === 'string' ? value.slice(0, 10) : '';
}

function voucherTypeLabel(type) {
    return voucherTypeLabels[type] ?? type;
}

function voucherLabel(voucher) {
    const prefix = voucherTypePrefixes[voucher.voucher_type] ?? 'JV';
    return `${prefix}-${voucher.voucher_number}`;
}

// Exact string arithmetic, never Number(): the lines arrive as 2dp decimal
// strings and a float sum down a long voucher drifts a paisa.
function voucherAmount(voucher) {
    return sumMoney(voucher.lines.map((line) => line.debit ?? '0.00'));
}

const selectedVoucher = ref(null);

function viewVoucher(voucher) {
    selectedVoucher.value = voucher;
}

function onDetailOpenChange(value) {
    if (!value) selectedVoucher.value = null;
}

const statusVariants = {
    posted: 'success',
    cancelled: 'danger',
};

const statusLabels = {
    posted: 'Posted',
    cancelled: 'Cancelled',
};

// Cancel is only offered for a manually-posted 'journal' voucher or one of
// the five cash/bank voucher types - every other voucher_type is generated
// by another module's own record (Sale, Purchase, Payment, ...) or by
// FiscalYear's own bookkeeping (opening balance/closing entry/roll-forward)
// and must be cancelled from there instead. This mirrors the guard in
// JournalVoucher::cancel() itself (the backend still rejects it either way;
// this just avoids offering a button that would only fail).
const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });

function openCancel(voucher) {
    cancelling.value = voucher;
    cancelForm.reset();
    cancelForm.clearErrors();
}

function onCancelOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    cancelForm.post(`/journal-vouchers/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

const columns = [
    {
        id: 'dateBs',
        header: sortableHeader('dateBs', 'Date (BS)'),
        numeric: false,
        cell: ({ row }) => formatBsDate(row.original.date) || '-',
    },
    {
        id: 'voucher_number',
        header: sortableHeader('voucher_number', 'Voucher #'),
        numeric: false,
        cell: ({ row }) => voucherLabel(row.original),
    },
    {
        id: 'type',
        header: 'Type',
        numeric: false,
        cell: ({ row }) => voucherTypeLabel(row.original.voucher_type),
    },
    {
        id: 'narration',
        header: 'Narration',
        numeric: false,
        cell: ({ row }) => h('span', { class: 'block max-w-[280px] truncate', title: row.original.narration }, row.original.narration || '-'),
    },
    {
        id: 'fiscal_year',
        header: 'Fiscal year',
        numeric: false,
        cell: ({ row }) => row.original.fiscal_year?.name ?? '-',
    },
    {
        id: 'amount',
        header: 'Total amount',
        numeric: true,
        cell: ({ row }) => formatMoney(voucherAmount(row.original)),
    },
    {
        id: 'created_by',
        header: 'Created by',
        numeric: false,
        cell: ({ row }) => row.original.creator?.name ?? '-',
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
            h('div', { class: 'flex items-center gap-1' }, [
                iconAction({ label: 'View lines', ariaLabel: `View lines of ${voucherLabel(row.original)}`, icon: Eye, run: () => viewVoucher(row.original) }),
                ...secondaryActionsFor(row.original).map(iconAction),
            ]),
    },
];

/** Print and cancel for a voucher, filtered by permission and by whether the voucher can still be cancelled. */
function secondaryActionsFor(voucher) {
    return [
        canPrintVoucher.value && {
            label: 'Print voucher',
            ariaLabel: `Print ${voucherLabel(voucher)}`,
            icon: Printer,
            link: { href: `/journal-vouchers/${voucher.id}/print`, target: '_blank', rel: 'noopener' },
        },
        canCancelVoucher.value && voucher.status === 'posted' && MANUALLY_CANCELLABLE_TYPES.includes(voucher.voucher_type) && {
            label: 'Cancel voucher (posts reversing entry)',
            ariaLabel: `Cancel ${voucherLabel(voucher)}`,
            icon: Ban,
            danger: true,
            run: () => openCancel(voucher),
        },
    ].filter(Boolean);
}

/** One row action as an inline icon button, or a real link when the action opens a file. */
function iconAction(action) {
    return h(Tooltip, { label: action.label }, () =>
        h(
            action.link ? 'a' : 'button',
            {
                ...(action.link ?? { type: 'button', onClick: action.run }),
                class: [
                    'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150',
                    action.danger ? 'hover:bg-danger-bg hover:text-danger' : 'hover:bg-primary-tint hover:text-primary',
                ],
                'aria-label': action.ariaLabel,
            },
            [h(action.icon, { class: 'h-[13px] w-[13px]' })],
        ),
    );
}
</script>

<template>
    <div>
        <template v-if="showCreateForm">
            <Create
                :accounts="accounts"
                :correction-fiscal-year="correctionFiscalYear"
                @cancel="showCreateForm = false"
                @posted="showCreateForm = false"
            />
        </template>

        <template v-else-if="showCashBankForm">
            <CashBankCreate
                :accounts="accounts"
                @cancel="showCashBankForm = false"
                @posted="showCashBankForm = false"
            />
        </template>

        <template v-else>
            <PageHeader title="Journal Vouchers" description="The record of every accounting entry. Post a journal voucher for general adjustments, or a cash/bank voucher for simple money in and out. Entries from sales and purchases also appear here.">
                <template v-if="hasOpenFiscalYear">
                    <Button v-if="canCreateCashBank" variant="secondary" tone="purple" @click="showCashBankForm = true">
                        <Plus class="size-4" />
                        New cash/bank voucher
                    </Button>
                    <Button v-if="canCreateJournal" variant="primary" tone="purple" @click="showCreateForm = true">
                        <Plus class="size-4" />
                        New journal voucher
                    </Button>
                </template>
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
                    <div class="min-w-[200px]">
                        <label class="mb-1 block text-xs font-semibold text-text-muted">Voucher type</label>
                        <Combobox v-model="filterState.voucher_type" @update:model-value="applyFilters" :options="voucherTypeOptions" placeholder="All types" />
                    </div>
                    <div class="min-w-[200px] flex-1">
                        <label class="mb-1 block text-xs font-semibold text-text-muted">Voucher # or narration</label>
                        <div class="relative">
                            <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-text-faint" />
                            <Input v-model="filterState.search" type="text" placeholder="e.g. JV-12 or rent" class="pl-8" @keydown.enter.prevent="applyFilters" />
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
                        <DropdownMenu v-if="canExport" align="end">
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

                <div v-if="journalVouchers.data.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                    <template v-if="hasActiveFilters">
                        <p class="text-sm font-semibold text-text-strong">No vouchers match these filters</p>
                        <Button variant="secondary" tone="neutral" @click="clearFilters">
                            <X class="size-4" />
                            Clear filters
                        </Button>
                    </template>
                    <template v-else>
                        <p class="text-sm font-semibold text-text-strong">No vouchers yet</p>
                        <p v-if="canCreateJournal && hasOpenFiscalYear" class="text-xs text-text-muted">Post your first entry and it will be listed here.</p>
                        <Button v-if="canCreateJournal && hasOpenFiscalYear" variant="primary" tone="purple" @click="showCreateForm = true">
                            <Plus class="size-4" />
                            New journal voucher
                        </Button>
                    </template>
                </div>
                <DataTable v-else :columns="columns" :data="journalVouchers.data" :page-size="Math.max(journalVouchers.data.length, 1)" empty-message="No vouchers" />

                <p v-if="journalVouchers.data.length > 0" class="mt-3 text-xs text-text-muted" aria-live="polite">Showing {{ journalVouchers.from }}–{{ journalVouchers.to }} of {{ journalVouchers.total }}</p>
                <nav v-if="journalVouchers.data.length > 0 && journalVouchers.last_page > 1" aria-label="Journal vouchers pagination" class="mt-3 flex items-center justify-end gap-2">
                    <Link
                        v-if="journalVouchers.prev_page_url"
                        :href="journalVouchers.prev_page_url"
                        preserve-state
                        preserve-scroll
                        aria-label="Previous page"
                        class="inline-flex items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary"
                    >
                        Previous
                    </Link>
                    <span v-else aria-disabled="true" class="inline-flex cursor-not-allowed items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-faint opacity-40">Previous</span>
                    <span class="text-xs text-text-muted" aria-current="page">Page {{ journalVouchers.current_page }} of {{ journalVouchers.last_page }}</span>
                    <Link
                        v-if="journalVouchers.next_page_url"
                        :href="journalVouchers.next_page_url"
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

        <Modal
            :open="!!selectedVoucher"
            :title="selectedVoucher ? `${voucherLabel(selectedVoucher)} · ${voucherTypeLabel(selectedVoucher.voucher_type)}` : ''"
            @update:open="onDetailOpenChange"
        >
            <div v-if="selectedVoucher" class="flex flex-col gap-4">
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Date</p>
                        <p class="text-text-base">{{ formatBsDate(selectedVoucher.date) || '-' }} (BS) · {{ adDate(selectedVoucher.date) }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Fiscal Year</p>
                        <p class="text-text-base">{{ selectedVoucher.fiscal_year?.name ?? '-' }}</p>
                    </div>
                    <div class="col-span-2">
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Narration</p>
                        <p class="text-text-base">{{ selectedVoucher.narration }}</p>
                    </div>
                    <div v-if="selectedVoucher.reason" class="col-span-2">
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Reason</p>
                        <p class="text-text-base">{{ selectedVoucher.reason }}</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Created By</p>
                        <p class="text-text-base">{{ selectedVoucher.creator?.name ?? '-' }}</p>
                    </div>
                </div>

                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-soft text-left text-[11px] font-bold tracking-[.6px] text-text-muted uppercase">
                            <th class="pb-2 font-bold">Account</th>
                            <th class="pb-2 text-right font-bold">Debit</th>
                            <th class="pb-2 text-right font-bold">Credit</th>
                            <th class="pb-2 font-bold">Narration</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in selectedVoucher.lines" :key="line.id" class="border-b border-border-soft last:border-0">
                            <td class="py-2 text-text-base">
                                {{ line.account?.code ? `${line.account.code} - ${line.account.name}` : line.account?.name }}
                            </td>
                            <td class="py-2 text-right [font-variant-numeric:tabular-nums]">{{ formatMoney(line.debit) }}</td>
                            <td class="py-2 text-right [font-variant-numeric:tabular-nums]">{{ formatMoney(line.credit) }}</td>
                            <td class="py-2 text-text-muted">{{ line.narration ?? '-' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Modal>

        <Modal :open="!!cancelling" title="Cancel journal voucher" size="compact" @update:open="onCancelOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    Cancelling {{ cancelling ? voucherLabel(cancelling) : 'this voucher' }} posts a reversing entry that cancels out its effect on the ledger. The original stays on record and this cannot be undone.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input v-model="cancelForm.reason" type="text" placeholder="e.g. Entered wrong account" required />
                    <p v-if="cancelForm.errors.reason" class="mt-1 text-sm text-danger">{{ cancelForm.errors.reason }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="neutral" type="button" @click="cancelling = null">Keep voucher</Button>
                <Button variant="primary" tone="purple" type="button" :loading="cancelForm.processing" :disabled="cancelForm.processing" @click="submitCancel">
                    Confirm cancellation
                </Button>
            </template>
        </Modal>
    </div>
</template>
