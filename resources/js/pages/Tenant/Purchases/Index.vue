<script setup>
import { computed, h, onMounted, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Search, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Combobox from '@/components/ui/Combobox.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';
import Create from './Create.vue';

const props = defineProps({
    purchases: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    filters: {
        type: Object,
        default: () => ({ from: null, to: null, supplier_id: null }),
    },
    suppliers: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
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

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

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

const columns = [
    { accessorKey: 'date', header: 'Date' },
    {
        id: 'supplier',
        header: 'Supplier',
        numeric: false,
        cell: ({ row }) => row.original.supplier?.name ?? '—',
    },
    {
        id: 'bill_number',
        header: 'Bill #',
        numeric: false,
        cell: ({ row }) => row.original.bill_number ?? '—',
    },
    {
        id: 'items',
        header: 'Items',
        numeric: false,
        cell: ({ row }) => itemSummary(row.original) || '—',
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
        cell: ({ row }) => Number(row.original.total).toFixed(2),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            row.original.status === 'cancelled'
                ? 'Cancelled'
                : 'Posted',
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-2' }, [
                h(
                    Button,
                    {
                        as: 'a',
                        variant: 'secondary',
                        tone: 'purple',
                        href: `/purchases/${row.original.id}/print`,
                        target: '_blank',
                        rel: 'noopener',
                    },
                    () => 'Print',
                ),
                row.original.status === 'posted'
                    ? h(Button, {
                          variant: 'secondary',
                          tone: 'purple',
                          type: 'button',
                          onClick: () => openCancel(row.original),
                      }, () => 'Cancel')
                    : null,
            ]),
    },
];
</script>

<template>
    <AppLayout title="Purchases" :nav-items="navItems">
        <template v-if="showCreateForm">
            <Create
                :suppliers="suppliers"
                :items="items"
                :accounts="accounts"
                :stores="stores"
                :correction-fiscal-year="correctionFiscalYear"
                :initial-draft="initialDraft"
                @cancel="closeCreateForm"
                @posted="closeCreateForm"
            />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Purchases</h2>
                <Button variant="primary" tone="purple" @click="openCreateForm">
                    <Plus class="size-4" />
                    New purchase
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
                        <label class="mb-1 block text-xs font-semibold text-text-muted">Supplier</label>
                        <Combobox v-model="filterState.supplier_id" :options="supplierOptions" placeholder="All suppliers" />
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
                <DataTable :columns="columns" :data="purchases.data" :page-size="Math.max(purchases.data.length, 1)" empty-message="No purchases yet" />

                <div v-if="purchases.data.length > 0" class="mt-3 flex flex-wrap items-center justify-between gap-3">
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
                </div>
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
                    This posts a reversing voucher for purchase from {{ cancelling.supplier?.name }} ({{ Number(cancelling.total).toFixed(2) }}). This cannot be undone.
                </p>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Reason <span class="text-danger">*</span></label>
                    <Input v-model="reasonForm.reason" type="text" placeholder="Reason for cancellation" required />
                    <p v-if="reasonForm.errors.reason" class="mt-1 text-sm text-danger">{{ reasonForm.errors.reason }}</p>
                </div>
            </div>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="cancelling = null">Back</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="reasonForm.processing" @click="submitCancel">
                    Confirm cancellation
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
