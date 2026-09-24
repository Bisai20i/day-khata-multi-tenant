<script setup>
import { computed, h, onMounted, reactive, ref, watch } from 'vue';
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
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import Create from './Create.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    purchases: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    filters: {
        type: Object,
        default: () => ({ from: null, to: null, supplier_id: null }),
    },
    // Exact SQL sums over the whole filtered set, computed server-side
    // (PurchaseController::filteredTotals()) - never a page's worth of
    // client-side addition (item 8, "totals row").
    totals: {
        type: Object,
        default: () => ({ taxable_amount: '0.00', nontaxable_amount: '0.00', vat_amount: '0.00', total: '0.00' }),
    },
    suppliers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    // For Create.vue's quick add-item modal (item 9).
    itemCategories: { type: Array, default: () => [] },
    bankAccounts: { type: Array, default: () => [] },
    tdsAccounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    correctionFiscalYear: { type: Object, default: null },
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
// not just this page (item 8, PurchaseController::export()).
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

    return query ? `/purchases/export?${query}` : '/purchases/export';
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Purchases');

// Store/cancel both redirect back to this same route + component, which
// Inertia re-renders in place without an onMounted re-run - watch flash
// status instead (same pattern as JournalVouchers/Index.vue).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);

// Restores an in-progress New purchase draft after Create.vue's inline
// "+ New supplier" modal bounces the browser away to /suppliers and back
// here (see Create.vue's submitSupplier()) - this component fully
// unmounts/remounts across that round trip, so the draft is stashed in
// sessionStorage right before the bounce and picked back up here. Mirrors
// Sales/Index.vue's identical bridge exactly.
const DRAFT_KEY = 'purchases-create-draft';
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

const paymentModeLabels = {
    cash: 'Cash',
    bank: 'Bank',
    partial: 'Partial',
    credit: 'Credit',
};

const cancelling = ref(null);
const reasonForm = useForm({ reason: '' });

function openCancel(purchase) {
    cancelling.value = purchase;
    reasonForm.reset();
    reasonForm.clearErrors();
}

function onCancelModalOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    reasonForm.post(`/purchases/${cancelling.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelling.value = null;
        },
    });
}

function itemSummary(purchase) {
    return purchase.lines.map((line) => line.item?.name).filter(Boolean).join(', ');
}

// Dates are shown in Bikram Sambat with the AD date beside them, the way every
// printed document in this app reads. Amounts are the stored server values run
// through the shared Indian-grouping formatter, never a client recomputation
// (CONTRACTS C8).
const columns = [
    {
        id: 'date',
        header: 'Date (BS)',
        numeric: false,
        cell: ({ row }) => `${formatBsDate(row.original.date)} (${String(row.original.date).slice(0, 10)})`,
    },
    {
        id: 'supplier',
        header: 'Supplier',
        numeric: false,
        cell: ({ row }) => row.original.supplier?.name ?? '-',
    },
    {
        id: 'bill_number',
        header: 'Supplier bill no.',
        numeric: false,
        cell: ({ row }) => row.original.bill_number ?? '-',
    },
    {
        id: 'items',
        header: 'Items',
        numeric: false,
        cell: ({ row }) => itemSummary(row.original) || '-',
    },
    {
        id: 'payment_mode',
        header: 'Payment',
        numeric: false,
        cell: ({ row }) => paymentModeLabels[row.original.payment_mode] ?? row.original.payment_mode,
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
                            href: `/purchases/${row.original.id}/print`,
                            target: '_blank',
                            rel: 'noopener',
                            'aria-label': `Print purchase from ${row.original.supplier?.name ?? 'supplier'}`,
                        },
                        () => 'Print',
                    ),
                ),
                row.original.status === 'posted'
                    ? h(Tooltip, { label: 'Cancel this purchase and reverse its entries' }, () =>
                          h(Button, {
                              variant: 'secondary',
                              tone: 'purple',
                              type: 'button',
                              'aria-label': `Cancel purchase from ${row.original.supplier?.name ?? 'supplier'}`,
                              onClick: () => openCancel(row.original),
                          }, () => 'Cancel'),
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
                :suppliers="suppliers"
                :items="items"
                :item-categories="itemCategories"
                :bank-accounts="bankAccounts"
                :tds-accounts="tdsAccounts"
                :stores="stores"
                :settings="settings"
                :correction-fiscal-year="correctionFiscalYear"
                :initial-draft="initialDraft"
                @cancel="closeCreateForm"
                @posted="closeCreateForm"
            />
        </template>

        <template v-else>
            <PageHeader title="Purchases" description="Bills received from suppliers. Filter, print or export them, and cancel a purchase posted in error.">
                <Button variant="primary" tone="purple" @click="openCreateForm">
                    <Plus class="size-4" aria-hidden="true" />
                    New purchase
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
                <div v-if="purchases.data.length === 0" class="py-10 text-center">
                    <template v-if="hasActiveFilters">
                        <p class="text-sm font-semibold text-text-strong">No purchases match these filters</p>
                        <p class="mt-1 text-sm text-text-muted">Try a wider date range or a different supplier.</p>
                        <Button class="mt-3" variant="secondary" tone="purple" type="button" @click="clearFilters">Clear filters</Button>
                    </template>
                    <template v-else>
                        <p class="text-sm font-semibold text-text-strong">No purchases yet</p>
                        <p class="mt-1 text-sm text-text-muted">Record the first bill you received from a supplier.</p>
                        <Button class="mt-3" variant="primary" tone="purple" type="button" @click="openCreateForm">
                            <Plus class="size-4" aria-hidden="true" />
                            New purchase
                        </Button>
                    </template>
                </div>
                <DataTable v-else :columns="columns" :data="purchases.data" :page-size="Math.max(purchases.data.length, 1)" empty-message="No purchases yet" />

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

                <nav v-if="purchases.data.length > 0" aria-label="Purchases pagination" class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-text-muted">Showing {{ purchases.from }}–{{ purchases.to }} of {{ purchases.total }}</p>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="purchases.prev_page_url"
                            :href="purchases.prev_page_url"
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
                        <span class="text-xs text-text-muted">Page {{ purchases.current_page }} of {{ purchases.last_page }}</span>
                        <Link
                            v-if="purchases.next_page_url"
                            :href="purchases.next_page_url"
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
            title="Cancel purchase"
            size="compact"
            @update:open="onCancelModalOpenChange"
        >
            <div v-if="cancelling" class="flex flex-col gap-4">
                <p class="text-sm text-text-muted">
                    Cancelling the purchase from {{ cancelling.supplier?.name }}
                    ({{ formatMoney(cancelling.total) }}) posts a reversing entry and reduces stock. This cannot be undone.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input v-model="reasonForm.reason" type="text" maxlength="500" placeholder="Reason for cancellation" required />
                    <p v-if="reasonForm.errors.reason" class="mt-1 text-sm text-danger">{{ reasonForm.errors.reason }}</p>
                </div>
            </div>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="cancelling = null">Keep purchase</Button>
                <Button variant="primary" tone="purple" type="button" :loading="reasonForm.processing" @click="submitCancel">
                    Cancel this purchase
                </Button>
            </template>
        </Modal>
    </div>
</template>
