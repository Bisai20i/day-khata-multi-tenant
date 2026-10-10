<script setup>
import { computed, h, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Ban, Download, Plus, Printer, Search, SlidersHorizontal, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Badge from '@/components/ui/Badge.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { formatMoney, isZeroMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import Create from './Create.vue';
import { useOpenFiscalYear } from '@/composables/useOpenFiscalYear';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const { hasOpenFiscalYear } = useOpenFiscalYear();

const props = defineProps({
    returns: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    filters: {
        type: Object,
        default: () => ({ from: null, to: null, supplier_id: null }),
    },
    // Exact SQL sums over the whole filtered set, computed server-side
    // (PurchaseReturnController::filteredTotals()) - never a page's worth of
    // client-side addition (item 8, "totals row").
    totals: {
        type: Object,
        default: () => ({ taxable_amount: '0.00', nontaxable_amount: '0.00', vat_amount: '0.00', total: '0.00' }),
    },
    // One searched, paginated page of returnable purchases - the form used to
    // receive every posted purchase in the tenant with all of their lines.
    searchablePurchases: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, prev_page_url: null, next_page_url: null }),
    },
    purchaseSearch: { type: String, default: null },
    suppliers: { type: Array, default: () => [] },
    refundAccounts: { type: Array, default: () => [] },
    // Asset accounts a cash + bank refund on an unlinked return can land in.
    bankAccounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    // Stockable items for the unlinked return form (item 4).
    items: { type: Array, default: () => [] },
    defaultVatRate: { type: String, default: '13.00' },
});

const supplierOptions = computed(() => props.suppliers.map((supplier) => ({ value: supplier.id, label: supplier.name })));

const filterState = reactive({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    supplier_id: props.filters.supplier_id ?? null,
});
const filtering = ref(false);

function applyFilters() {
    router.get(
        window.location.pathname,
        { from: filterState.from || undefined, to: filterState.to || undefined, supplier_id: filterState.supplier_id || undefined },
        { preserveState: true, preserveScroll: true, onStart: () => (filtering.value = true), onFinish: () => (filtering.value = false) },
    );
}

function clearFilters() {
    filterState.from = '';
    filterState.to = '';
    filterState.supplier_id = null;
    router.get(
        window.location.pathname,
        {},
        { preserveState: true, preserveScroll: true, onStart: () => (filtering.value = true), onFinish: () => (filtering.value = false) },
    );
}

const hasActiveFilters = computed(() => !!(props.filters.from || props.filters.to || props.filters.supplier_id));

const showFilters = ref(false);

const activeFilterChips = computed(() => {
    const chips = [];

    if (props.filters.from) chips.push({ key: 'from', label: `From ${formatBsDate(props.filters.from)}` });
    if (props.filters.to) chips.push({ key: 'to', label: `To ${formatBsDate(props.filters.to)}` });
    if (props.filters.supplier_id) {
        const supplier = props.suppliers.find((s) => s.id === Number(props.filters.supplier_id));
        chips.push({ key: 'supplier_id', label: supplier?.name ?? 'Supplier' });
    }

    return chips;
});

function removeFilter(key) {
    filterState[key] = key === 'supplier_id' ? null : '';
    applyFilters();
}

// The export covers the same filtered set the page is showing, all rows and
// not just this page (item 8, PurchaseReturnController::export()).
const exportUrl = computed(() => {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries({
        from: filterState.from || undefined,
        to: filterState.to || undefined,
        supplier_id: filterState.supplier_id || undefined,
    })) {
        if (value !== undefined && value !== null && value !== '') params.append(key, value);
    }

    const query = params.toString();

    return query ? `/purchase-returns/export?${query}` : '/purchase-returns/export';
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Purchase Returns');

// UI mirrors of routes/tenant-purchase-returns.php; each route still
// enforces its own key. Either create key opens the form (Create.vue picks
// the mode the user may post).
const { can, canAny } = usePermissions();
const canCreateReturn = computed(() => canAny(['purchase_returns.create', 'unlinked_purchase_returns.create']));
const canPrintReturn = computed(() => can('purchase_returns.print'));
const canExportReturns = computed(() => can('purchase_returns.export'));
const canCancelReturn = computed(() => can('purchase_returns.cancel'));

watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);

// An unlinked return's line names its item directly; a linked one reads it off
// the original purchase line (item 4).
function itemSummary(purchaseReturn) {
    return purchaseReturn.lines
        .map((line) => line.purchase_line?.item?.name ?? line.item?.name)
        .filter(Boolean)
        .join(', ');
}

/**
 * An unlinked return settles at posting time into cash, bank or both, so it
 * has no single refund account to name (item 4).
 */
function unlinkedRefundSummary(purchaseReturn) {
    if (!purchaseReturn.is_unlinked) {
        return '-';
    }

    const parts = [];

    if (purchaseReturn.cash_amount && !isZeroMoney(purchaseReturn.cash_amount)) {
        parts.push(`Cash ${formatMoney(purchaseReturn.cash_amount)}`);
    }

    if (purchaseReturn.bank_amount && !isZeroMoney(purchaseReturn.bank_amount)) {
        parts.push(`Bank ${formatMoney(purchaseReturn.bank_amount)}`);
    }

    return parts.length > 0 ? parts.join(' + ') : '-';
}

/** "#12 - Supplier" for a linked return, "No bill - Supplier" for an unlinked one. */
function purchaseSummary(purchaseReturn) {
    const supplier = purchaseReturn.purchase?.supplier?.name ?? purchaseReturn.supplier?.name ?? '-';

    return purchaseReturn.purchase?.id ? `#${purchaseReturn.purchase.id} - ${supplier}` : `No bill - ${supplier}`;
}

const cancelling = ref(null);
const reasonForm = useForm({ reason: '' });

function openCancel(purchaseReturn) {
    cancelling.value = purchaseReturn;
    reasonForm.reset();
    reasonForm.clearErrors();
}

function onCancelModalOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    reasonForm.post(`/purchase-returns/${cancelling.value.id}/cancel`, {
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
        id: 'debit_note_number',
        header: 'Debit note no.',
        numeric: false,
        cell: ({ row }) => row.original.debit_note_number ?? '-',
    },
    {
        id: 'purchase',
        header: 'Original purchase',
        numeric: false,
        cell: ({ row }) => purchaseSummary(row.original),
    },
    {
        id: 'items',
        header: 'Items',
        numeric: false,
        cell: ({ row }) => itemSummary(row.original) || '-',
    },
    {
        id: 'reason',
        header: 'Reason',
        numeric: false,
        cell: ({ row }) => row.original.reason ?? '-',
    },
    {
        id: 'refund',
        header: 'Refund via',
        numeric: false,
        cell: ({ row }) => row.original.refund_account?.name ?? unlinkedRefundSummary(row.original),
    },
    {
        id: 'total',
        header: 'Total (Rs.)',
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
                { pill: true, variant: row.original.status === 'cancelled' ? 'danger' : 'success' },
                () => (row.original.status === 'cancelled' ? 'Cancelled' : 'Posted'),
            ),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-1' }, [
                canPrintReturn.value
                    ? h(Tooltip, { label: 'Open a printable copy in a new tab' }, () =>
                          h(
                              'a',
                              {
                                  href: `/purchase-returns/${row.original.id}/print`,
                                  target: '_blank',
                                  rel: 'noopener',
                                  class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                                  'aria-label': `Print purchase return ${row.original.debit_note_number ?? row.original.id}`,
                              },
                              [h(Printer, { class: 'h-[13px] w-[13px]' })],
                          ),
                      )
                    : null,
                row.original.status === 'posted' && canCancelReturn.value
                    ? h(Tooltip, { label: 'Cancel this return and reverse its entries' }, () =>
                          h(
                              'button',
                              {
                                  type: 'button',
                                  class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-danger-bg hover:text-danger',
                                  'aria-label': `Cancel purchase return ${row.original.debit_note_number ?? row.original.id}`,
                                  onClick: () => openCancel(row.original),
                              },
                              [h(Ban, { class: 'h-[13px] w-[13px]' })],
                          ),
                      )
                    : null,
            ]),
    },
];
</script>

<template>
    <div>
        <template v-if="showCreateForm">
            <Create
                :searchable-purchases="searchablePurchases"
                :purchase-search="purchaseSearch"
                :refund-accounts="refundAccounts"
                :bank-accounts="bankAccounts"
                :stores="stores"
                :suppliers="suppliers"
                :items="items"
                :default-vat-rate="defaultVatRate"
                @cancel="showCreateForm = false"
                @posted="showCreateForm = false"
            />
        </template>

        <template v-else>
            <PageHeader title="Purchase returns" description="Goods sent back to suppliers. Each return issues a debit note; cancel one entered in error.">
                <Button v-if="hasOpenFiscalYear && canCreateReturn" variant="primary" tone="purple" @click="showCreateForm = true">
                    <Plus class="size-4" aria-hidden="true" />
                    New return
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
                        <Label class="mb-1">From date (BS)</Label>
                        <NepaliDateInput v-model="filterState.from" />
                    </div>
                    <div class="min-w-[160px]">
                        <Label class="mb-1">To date (BS)</Label>
                        <NepaliDateInput v-model="filterState.to" />
                    </div>
                    <div class="min-w-[220px]">
                        <Label class="mb-1">Supplier</Label>
                        <Combobox v-model="filterState.supplier_id" @update:model-value="applyFilters" :options="supplierOptions" placeholder="All suppliers" />
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
                <div v-if="returns.data.length > 0 || canExportReturns" class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p v-if="returns.data.length > 0" class="text-xs text-text-muted" aria-live="polite">Showing {{ returns.from }}–{{ returns.to }} of {{ returns.total }}</p>
                    <div v-if="canExportReturns" class="ml-auto flex items-center gap-2">
                        <a :href="exportUrl">
                            <Button variant="secondary" tone="neutral" type="button">
                                <Download class="size-4" />
                                Export
                            </Button>
                        </a>
                    </div>
                </div>

                <div v-if="returns.data.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                    <template v-if="hasActiveFilters">
                        <p class="text-sm font-semibold text-text-strong">No purchase returns match these filters</p>
                        <Button variant="secondary" tone="neutral" @click="clearFilters">
                            <X class="size-4" />
                            Clear filters
                        </Button>
                    </template>
                    <template v-else>
                        <p class="text-sm font-semibold text-text-strong">No purchase returns yet</p>
                        <p v-if="hasOpenFiscalYear && canCreateReturn" class="text-xs text-text-muted">Record goods you sent back to a supplier and they will be listed here.</p>
                        <Button v-if="hasOpenFiscalYear && canCreateReturn" variant="primary" tone="purple" @click="showCreateForm = true">
                            <Plus class="size-4" aria-hidden="true" />
                            New return
                        </Button>
                    </template>
                </div>
                <DataTable v-else :columns="columns" :data="returns.data" :page-size="Math.max(returns.data.length, 1)" empty-message="No purchase returns yet" />

                <!-- Server-computed SQL sums for the whole filtered set, not
                     just this page (item 8, "totals row"). -->
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

                <nav v-if="returns.data.length > 0 && returns.last_page > 1" aria-label="Purchase returns pagination" class="mt-3 flex items-center justify-end gap-2">
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
                    <span v-else aria-disabled="true" class="inline-flex cursor-not-allowed items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-faint opacity-40">Previous</span>
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
                    <span v-else aria-disabled="true" class="inline-flex cursor-not-allowed items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-faint opacity-40">Next</span>
                </nav>
            </Card>
        </template>

        <Modal
            :open="!!cancelling"
            title="Cancel purchase return"
            size="compact"
            @update:open="onCancelModalOpenChange"
        >
            <div v-if="cancelling" class="flex flex-col gap-4">
                <p class="text-sm text-text-muted">
                    Cancelling {{ purchaseSummary(cancelling) }}
                    ({{ formatMoney(cancelling.total) }}) posts a reversing entry and puts the returned stock back. This cannot be undone.
                </p>
                <div>
                    <Label class="mb-1">Reason <span class="text-danger">*</span></Label>
                    <Input v-model="reasonForm.reason" type="text" maxlength="500" placeholder="Reason for cancellation" required />
                    <p v-if="reasonForm.errors.reason" class="mt-1 text-sm text-danger">{{ reasonForm.errors.reason }}</p>
                </div>
            </div>

            <template #footer>
                <Button variant="secondary" tone="neutral" type="button" @click="cancelling = null">Keep return</Button>
                <Button variant="primary" tone="purple" type="button" :loading="reasonForm.processing" @click="submitCancel">
                    Cancel this return
                </Button>
            </template>
        </Modal>
    </div>
</template>
