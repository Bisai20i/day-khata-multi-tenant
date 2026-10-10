<script setup>
import { computed, h, reactive, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRightCircle, Ban, Plus, Printer, Search, SlidersHorizontal, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import RowActions from '@/components/ui/RowActions.vue';
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import Create from './Create.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    quotations: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    filters: {
        type: Object,
        default: () => ({ from: null, to: null, customer_id: null }),
    },
    customers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
useLayoutChrome('Quotations');

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

const showFilters = ref(false);

const activeFilterChips = computed(() => {
    const chips = [];

    if (props.filters.from) chips.push({ key: 'from', label: `From ${formatBsDate(props.filters.from)}` });
    if (props.filters.to) chips.push({ key: 'to', label: `To ${formatBsDate(props.filters.to)}` });
    if (props.filters.customer_id) {
        const customer = props.customers.find((c) => c.id === Number(props.filters.customer_id));
        chips.push({ key: 'customer_id', label: customer?.name ?? 'Customer' });
    }

    return chips;
});

function removeFilter(key) {
    filterState[key] = key === 'customer_id' ? null : '';
    applyFilters();
}

// Posting/editing/converting redirects back to this same route + component,
// which Inertia re-renders in place without an onMounted re-run - watch the
// flash prop instead (same pattern as every other module in this app).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

// UI gating only (each route's can: middleware is the authority).
// Converting needs sales.create as well as quotations.edit, because it posts
// a sale (QuotationController::convertToSale() checks both).
const { can } = usePermissions();
const canConvert = computed(() => can('quotations.edit') && can('sales.create'));
const printKeyByType = { quotation: 'quotations.print', sale: 'sales.print' };

// C11: the controller flashes the document it just created, so the quotation
// opens for that exact row instead of the page guessing the newest id. The
// print view is only opened for a user who may read it.
watch(
    () => page.props.flash?.created,
    (created) => {
        if (created?.print_url && printKeyByType[created.type] && can(printKeyByType[created.type])) {
            window.open(created.print_url, '_blank', 'noopener');
        }
    },
    { immediate: true },
);

const showCreateForm = ref(false);
const editingQuotation = ref(null);

const statusVariants = {
    draft: 'neutral',
    converted: 'success',
    cancelled: 'danger',
};

const statusLabels = {
    draft: 'Draft',
    converted: 'Converted',
    cancelled: 'Cancelled',
};

function edit(quotation) {
    editingQuotation.value = quotation;
}

function closeForms() {
    showCreateForm.value = false;
    editingQuotation.value = null;
}

async function destroy(quotation) {
    const confirmed = await confirm({
        message: `Delete quotation #${quotation.id}? This cannot be undone.`,
        tone: 'danger',
        confirmLabel: 'Delete',
    });
    if (!confirmed) return;
    router.delete(`/quotations/${quotation.id}`, { preserveScroll: true });
}

async function cancelQuotation(quotation) {
    const confirmed = await confirm({ message: `Cancel quotation #${quotation.id}? It will be marked cancelled and can no longer be converted to a sale.`, tone: 'danger', confirmLabel: 'Cancel quotation' });
    if (!confirmed) return;
    router.post(`/quotations/${quotation.id}/cancel`, {}, { preserveScroll: true });
}

// Converting posts a real sale, so a second click while the first request is
// still in flight used to post a second one (audit P0-16). The server locks the
// row and refuses the duplicate; this keeps the button from asking for it.
const converting = ref(null);

async function convertToSale(quotation) {
    if (converting.value !== null) return;

    const confirmed = await confirm({
        message: `Convert quotation #${quotation.id} to a real sale? This posts to the ledger and cannot be undone.`,
        confirmLabel: 'Convert',
    });
    if (!confirmed) return;

    converting.value = quotation.id;
    router.post(
        `/quotations/${quotation.id}/convert-to-sale`,
        {},
        { preserveScroll: true, onFinish: () => (converting.value = null) },
    );
}

const columns = [
    {
        id: 'date',
        header: 'Date (BS)',
        numeric: false,
        cell: ({ row }) => formatBsDate(row.original.date),
    },
    {
        id: 'reference_number',
        header: 'Reference #',
        numeric: false,
        cell: ({ row }) => row.original.reference_number ?? '-',
    },
    {
        id: 'customer',
        header: 'Customer',
        numeric: false,
        cell: ({ row }) => row.original.customer?.name ?? '-',
    },
    {
        id: 'total',
        header: 'Total',
        numeric: true,
        // The stored total, written by the server's one calculator. This cell
        // used to add the quotation up again in the browser with a third
        // formula that matched neither the PDF nor the sale (audit P0-9).
        cell: ({ row }) => formatMoney(row.original.total),
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
        cell: ({ row }) => {
            const quotation = row.original;

            const printBtn = can('quotations.print')
                ? h(Tooltip, { label: 'Print quotation' }, () =>
                      h(
                          'a',
                          {
                              href: `/quotations/${quotation.id}/print`,
                              target: '_blank',
                              rel: 'noopener',
                              class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                              'aria-label': 'Print quotation',
                          },
                          [h(Printer, { class: 'h-[13px] w-[13px]' })],
                      ),
                  )
                : null;

            if (quotation.status !== 'draft') {
                return h('div', { class: 'flex items-center gap-1' }, [printBtn]);
            }

            const moreActions = moreActionsFor(quotation);

            return h('div', { class: 'flex items-center gap-2' }, [
                printBtn,
                h(
                    RowActions,
                    {
                        canEdit: can('quotations.edit'),
                        canDelete: can('quotations.delete'),
                        editLabel: 'Edit quotation',
                        deleteLabel: 'Delete quotation',
                        onEdit: () => edit(quotation),
                        onDelete: () => destroy(quotation),
                    },
                    moreActions.length > 0
                        ? {
                              more: () =>
                                  moreActions.map((action) =>
                                      h(DropdownMenuItem, { key: action.label, ...action.attrs, onSelect: action.run }, () => [
                                          h(action.icon, { class: 'size-3.5', 'aria-hidden': 'true' }),
                                          action.label,
                                      ]),
                                  ),
                          }
                        : {},
                ),
            ]);
        },
    },
];

/** The secondary actions of a draft quotation shown under the "More" button, filtered by permission. */
function moreActionsFor(quotation) {
    return [
        canConvert.value && {
            label: 'Convert to sale',
            icon: ArrowRightCircle,
            attrs: { title: 'Convert to sale (posts to ledger)', disabled: converting.value !== null },
            run: () => convertToSale(quotation),
        },
        can('quotations.cancel') && { label: 'Cancel quotation', icon: Ban, run: () => cancelQuotation(quotation) },
    ].filter(Boolean);
}
</script>

<template>
    <div>
        <template v-if="showCreateForm">
            <Create :customers="customers" :items="items" @cancel="closeForms" @saved="closeForms" />
        </template>

        <template v-else-if="editingQuotation">
            <Create :customers="customers" :items="items" :quotation="editingQuotation" @cancel="closeForms" @saved="closeForms" />
        </template>

        <template v-else>
            <PageHeader title="Quotations" description="Price offers you send to customers. Convert a draft into a sale once it is accepted.">
                <Button v-if="can('quotations.create')" variant="primary" tone="purple" @click="showCreateForm = true">
                    <Plus class="size-4" />
                    New quotation
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
                        <Label class="mb-1">Customer</Label>
                        <Combobox v-model="filterState.customer_id" @update:model-value="applyFilters" :options="customerOptions" placeholder="All customers" />
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
                <div v-if="quotations.data.length > 0" class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-text-muted" aria-live="polite">Showing {{ quotations.from }}–{{ quotations.to }} of {{ quotations.total }}</p>
                </div>

                <div v-if="quotations.data.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                    <template v-if="hasActiveFilters">
                        <p class="text-sm font-semibold text-text-strong">No quotations match these filters</p>
                        <Button variant="secondary" tone="neutral" @click="clearFilters">
                            <X class="size-4" />
                            Clear filters
                        </Button>
                    </template>
                    <template v-else>
                        <p class="text-sm font-semibold text-text-strong">No quotations yet</p>
                        <p class="text-xs text-text-muted">Create your first quotation and it will be listed here.</p>
                        <Button v-if="can('quotations.create')" variant="primary" tone="purple" @click="showCreateForm = true">
                            <Plus class="size-4" />
                            New quotation
                        </Button>
                    </template>
                </div>
                <DataTable v-else :columns="columns" :data="quotations.data" :page-size="Math.max(quotations.data.length, 1)" empty-message="No quotations" />

                <nav v-if="quotations.data.length > 0 && quotations.last_page > 1" aria-label="Quotations pagination" class="mt-3 flex items-center justify-end gap-2">
                    <Link
                        v-if="quotations.prev_page_url"
                        :href="quotations.prev_page_url"
                        preserve-state
                        preserve-scroll
                        aria-label="Previous page"
                        class="inline-flex items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:border-primary hover:text-primary"
                    >
                        Previous
                    </Link>
                    <span v-else aria-disabled="true" class="inline-flex cursor-not-allowed items-center border-[1.5px] border-border bg-white px-3 py-1.5 text-xs font-semibold text-text-faint opacity-40">Previous</span>
                    <span class="text-xs text-text-muted" aria-current="page">Page {{ quotations.current_page }} of {{ quotations.last_page }}</span>
                    <Link
                        v-if="quotations.next_page_url"
                        :href="quotations.next_page_url"
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
    </div>
</template>
