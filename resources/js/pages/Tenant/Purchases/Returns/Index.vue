<script setup>
import { computed, h, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Search, X } from '@lucide/vue';
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
import { useToast } from '@/composables/useToast';
import { formatMoney, isZeroMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import Create from './Create.vue';
import { useOpenFiscalYear } from '@/composables/useOpenFiscalYear';

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
            h('div', { class: 'flex items-center gap-2' }, [
                h(Tooltip, { label: 'Open a printable copy in a new tab' }, () =>
                    h(
                        Button,
                        {
                            as: 'a',
                            variant: 'secondary',
                            tone: 'purple',
                            href: `/purchase-returns/${row.original.id}/print`,
                            target: '_blank',
                            rel: 'noopener',
                            'aria-label': `Print purchase return ${row.original.debit_note_number ?? row.original.id}`,
                        },
                        () => 'Print',
                    ),
                ),
                row.original.status === 'posted'
                    ? h(Tooltip, { label: 'Cancel this return and reverse its entries' }, () =>
                          h(
                              Button,
                              {
                                  variant: 'secondary',
                                  tone: 'purple',
                                  type: 'button',
                                  'aria-label': `Cancel purchase return ${row.original.debit_note_number ?? row.original.id}`,
                                  onClick: () => openCancel(row.original),
                              },
                              () => 'Cancel',
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
                <Button v-if="hasOpenFiscalYear" variant="primary" tone="purple" @click="showCreateForm = true">
                    <Plus class="size-4" aria-hidden="true" />
                    New return
                </Button>
            </PageHeader>

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
                        <label class="mb-1 block text-xs font-semibold text-text-muted">Supplier</label>
                        <Combobox v-model="filterState.supplier_id" :options="supplierOptions" placeholder="All suppliers" />
                    </div>
                    <Button variant="primary" tone="purple" :loading="filtering" @click="applyFilters">
                        <Search class="size-4" />
                        Apply filters
                    </Button>
                    <Button v-if="hasActiveFilters" variant="secondary" tone="purple" @click="clearFilters">
                        <X class="size-4" />
                        Clear filters
                    </Button>
                    <a :href="exportUrl">
                        <Button variant="secondary" tone="purple" type="button">Export filtered list</Button>
                    </a>
                </div>
            </Card>

            <Card variant="panel">
                <div v-if="returns.data.length === 0" class="py-10 text-center">
                    <template v-if="hasActiveFilters">
                        <p class="text-sm font-semibold text-text-strong">No purchase returns match these filters</p>
                        <p class="mt-1 text-sm text-text-muted">Try a wider date range or a different supplier.</p>
                        <Button class="mt-3" variant="secondary" tone="purple" type="button" @click="clearFilters">Clear filters</Button>
                    </template>
                    <template v-else>
                        <p class="text-sm font-semibold text-text-strong">No purchase returns yet</p>
                        <p class="mt-1 text-sm text-text-muted">Record goods you sent back to a supplier.</p>
                        <Button v-if="hasOpenFiscalYear" class="mt-3" variant="primary" tone="purple" type="button" @click="showCreateForm = true">
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

                <nav v-if="returns.data.length > 0" aria-label="Purchase returns pagination" class="mt-3 flex flex-wrap items-center justify-between gap-3">
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
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input v-model="reasonForm.reason" type="text" maxlength="500" placeholder="Reason for cancellation" required />
                    <p v-if="reasonForm.errors.reason" class="mt-1 text-sm text-danger">{{ reasonForm.errors.reason }}</p>
                </div>
            </div>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="cancelling = null">Keep return</Button>
                <Button variant="primary" tone="purple" type="button" :loading="reasonForm.processing" @click="submitCancel">
                    Cancel this return
                </Button>
            </template>
        </Modal>
    </div>
</template>
