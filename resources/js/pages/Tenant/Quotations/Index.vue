<script setup>
import { computed, h, reactive, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRightCircle, Pencil, Plus, Printer, Search, Trash2, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
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

// C11: the controller flashes the document it just created, so the quotation
// opens for that exact row instead of the page guessing the newest id.
watch(
    () => page.props.flash?.created,
    (created) => {
        if (created?.print_url && (created.type === 'quotation' || created.type === 'sale')) {
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
    const confirmed = await confirm({ message: `Cancel quotation #${quotation.id}?`, tone: 'danger', confirmLabel: 'Cancel quotation' });
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
        cell: ({ row }) => row.original.reference_number ?? '—',
    },
    {
        id: 'customer',
        header: 'Customer',
        numeric: false,
        cell: ({ row }) => row.original.customer?.name ?? '—',
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

            const printBtn = h(Tooltip, { label: 'Print' }, () =>
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
            );

            if (quotation.status !== 'draft') {
                return h('div', { class: 'flex items-center gap-1' }, [printBtn]);
            }

            const convertBtn = h(Tooltip, { label: 'Convert to sale' }, () =>
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-40',
                        'aria-label': 'Convert to sale',
                        disabled: converting.value !== null,
                        onClick: () => convertToSale(quotation),
                    },
                    [h(ArrowRightCircle, { class: 'h-[13px] w-[13px]' })],
                ),
            );

            const editBtn = h(Tooltip, { label: 'Edit' }, () =>
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                        'aria-label': 'Edit quotation',
                        onClick: () => edit(quotation),
                    },
                    [h(Pencil, { class: 'h-[13px] w-[13px]' })],
                ),
            );

            const deleteBtn = h(Tooltip, { label: 'Delete' }, () =>
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-danger-bg hover:text-danger',
                        'aria-label': 'Delete quotation',
                        onClick: () => destroy(quotation),
                    },
                    [h(Trash2, { class: 'h-[13px] w-[13px]' })],
                ),
            );

            return h('div', { class: 'flex items-center gap-1' }, [printBtn, convertBtn, editBtn, deleteBtn]);
        },
    },
];
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
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Quotations</h2>
                <Button variant="primary" tone="purple" @click="showCreateForm = true">
                    <Plus class="size-4" />
                    New quotation
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
                <DataTable :columns="columns" :data="quotations.data" :page-size="Math.max(quotations.data.length, 1)" empty-message="No quotations yet" />

                <div v-if="quotations.data.length > 0" class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-text-muted">Showing {{ quotations.from }}–{{ quotations.to }} of {{ quotations.total }}</p>
                    <div class="flex items-center gap-2">
                        <Link
                            v-if="quotations.prev_page_url"
                            :href="quotations.prev_page_url"
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
                        <span class="text-xs text-text-muted">Page {{ quotations.current_page }} of {{ quotations.last_page }}</span>
                        <Link
                            v-if="quotations.next_page_url"
                            :href="quotations.next_page_url"
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
    </div>
</template>
