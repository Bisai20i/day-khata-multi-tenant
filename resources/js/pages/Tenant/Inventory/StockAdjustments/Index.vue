<script setup>
import { computed, h, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Ban, Plus, Printer, Search, SlidersHorizontal, Upload, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney, formatQuantity } from '@/lib/money.js';
import { formatBsDate, todayInKathmandu } from '@/lib/format.js';
import Create from './Create.vue';
import { useOpenFiscalYear } from '@/composables/useOpenFiscalYear';

defineOptions({ layout: AppLayout });

const { hasOpenFiscalYear } = useOpenFiscalYear();
const { can } = usePermissions();

const props = defineProps({
    stockAdjustments: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    correctionFiscalYear: { type: Object, default: null },
    // Server-side date window the list was built from; defaults to the open
    // fiscal year so the page never loads a tenant's whole history.
    filters: { type: Object, default: () => ({ from: null, to: null }) },
    // True once an opening-stock CSV has been imported, so the import modal
    // can warn that a second one replaces the first.
    hasOpeningStockImport: { type: Boolean, default: false },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Stock Adjustments');

// Posting/cancelling redirects back to this same route + component, which
// Inertia re-renders in place without an onMounted re-run - watch the flash
// prop instead (same pattern as every other module in this app).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);

// The list is filtered on the server (the payload would otherwise grow
// without bound), so changing a date reloads the page rather than filtering
// in place.
const dateFilter = ref({ from: props.filters.from ?? '', to: props.filters.to ?? '' });

watch(
    () => props.filters,
    (filters) => {
        dateFilter.value = { from: filters.from ?? '', to: filters.to ?? '' };
    },
);

const filtering = ref(false);

function applyDateFilter() {
    router.get('/stock-adjustments', { from: dateFilter.value.from, to: dateFilter.value.to }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (filtering.value = true),
        onFinish: () => (filtering.value = false),
    });
}

const showFilters = ref(false);

const activeFilterChips = computed(() => {
    const chips = [];

    if (props.filters.from) chips.push({ key: 'from', label: `From ${formatBsDate(props.filters.from)}` });
    if (props.filters.to) chips.push({ key: 'to', label: `To ${formatBsDate(props.filters.to)}` });

    return chips;
});

const hasActiveFilters = computed(() => !!(props.filters.from || props.filters.to));

function removeFilter(key) {
    dateFilter.value = { ...dateFilter.value, [key]: '' };
    applyDateFilter();
}

function clearFilters() {
    dateFilter.value = { from: '', to: '' };
    applyDateFilter();
}

const storeOptions = computed(() => props.stores.map((s) => ({ value: s.id, label: s.name })));

const importModalOpen = ref(false);
// Today in Kathmandu, not the UTC day (contract C8).
const importForm = useForm({ file: null, date: todayInKathmandu(), store_id: null });
const importResult = ref(null);

// Same flash-watch reasoning as flash.status above: the import submit
// redirects back to this same route + component instead of navigating away.
watch(
    () => page.props.flash?.importResult,
    (result) => {
        if (result) importResult.value = result;
    },
);

function openImport() {
    importForm.reset();
    importForm.date = todayInKathmandu();
    importForm.clearErrors();
    importResult.value = null;
    importModalOpen.value = true;
}

function closeImportModal() {
    importModalOpen.value = false;
    importForm.reset();
    importForm.date = todayInKathmandu();
    importForm.clearErrors();
    importResult.value = null;
}

function onImportModalOpenChange(value) {
    if (value) {
        importModalOpen.value = true;
    } else {
        closeImportModal();
    }
}

function onImportFileChange(event) {
    importForm.file = event.target.files[0] ?? null;
}

function submitImport() {
    importForm.post('/stock-adjustments/opening-stock/import', { forceFormData: true });
}

const reasonLabels = {
    damage: 'Damage',
    lost: 'Lost',
    correction: 'Correction',
    found: 'Found',
    opening: 'Opening',
    other: 'Other',
};

function linesSummary(adjustment) {
    if (!adjustment.lines?.length) return '-';
    return adjustment.lines
        .map((line) => {
            const sign = line.direction === 'in' ? '+' : '-';
            const reason = reasonLabels[line.reason_type] ?? line.reason_type;
            // Shows the unit the line was entered in (item 7) - the alternate
            // unit when one was picked, otherwise the item's own base unit -
            // so "2" never reads ambiguously against a base-unit quantity.
            const unit = line.item_unit?.name ?? line.item?.unit ?? '';
            return `${line.item?.name ?? '-'} (${sign}${formatQuantity(line.quantity)} ${unit} ${reason})`;
        })
        .join(', ');
}

const cancelling = ref(null);
const cancelForm = useForm({ reason: '' });

function openCancel(adjustment) {
    cancelling.value = adjustment;
    cancelForm.reset();
    cancelForm.clearErrors();
}

function onCancelOpenChange(value) {
    if (!value) cancelling.value = null;
}

function submitCancel() {
    cancelForm.post(`/stock-adjustments/${cancelling.value.id}/cancel`, {
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
        // Bikram Sambat first: it is the date a Nepali user works in, and the
        // one the IRD reads. The AD date stays beside it as the
        // cross-reference (contract C8 / C9).
        cell: ({ row }) =>
            h('div', { class: 'whitespace-nowrap' }, [
                formatBsDate(row.original.date),
                h('span', { class: 'ml-1 text-text-faint' }, `(${String(row.original.date ?? '').slice(0, 10)})`),
            ]),
    },
    {
        id: 'note',
        header: 'Note',
        numeric: false,
        cell: ({ row }) => row.original.note ?? '-',
    },
    {
        id: 'lines',
        header: 'Lines',
        numeric: false,
        cell: ({ row }) => linesSummary(row.original),
    },
    {
        id: 'total_value',
        header: 'Total value',
        numeric: true,
        cell: ({ row }) => formatMoney(row.original.total_value),
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
                can('stock_adjustments.print')
                    ? h(Tooltip, { label: 'Print adjustment' }, () =>
                        h(
                            'a',
                            {
                                href: `/stock-adjustments/${row.original.id}/print`,
                                target: '_blank',
                                rel: 'noopener',
                                class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                                'aria-label': 'Print adjustment',
                            },
                            [h(Printer, { class: 'h-[13px] w-[13px]' })],
                        ),
                    )
                    : null,
                row.original.status === 'cancelled' || !can('stock_adjustments.cancel')
                    ? null
                    : h(Tooltip, { label: 'Cancel adjustment' }, () =>
                          h(
                              'button',
                              {
                                  type: 'button',
                                  class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-danger-bg hover:text-danger',
                                  'aria-label': 'Cancel adjustment',
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
                :items="items"
                :stores="stores"
                :correction-fiscal-year="correctionFiscalYear"
                @cancel="showCreateForm = false"
                @posted="showCreateForm = false"
            />
        </template>

        <template v-else>
            <PageHeader title="Stock Adjustments" description="Stock adjustments: correct stock after a count, damage or loss.">
                <Button v-if="hasOpenFiscalYear && can('stock_adjustments.create')" variant="primary" tone="purple" @click="showCreateForm = true">
                    <Plus class="size-4" />
                    New adjustment
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
                        <NepaliDateInput v-model="dateFilter.from" @update:model-value="applyDateFilter" />
                    </div>
                    <div class="min-w-[160px]">
                        <Label class="mb-1">To date (BS)</Label>
                        <NepaliDateInput v-model="dateFilter.to" @update:model-value="applyDateFilter" />
                    </div>
                    <Button variant="secondary" tone="neutral" :loading="filtering" @click="applyDateFilter">
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
                <div v-if="stockAdjustments.length > 0 || (hasOpenFiscalYear && can('opening_stock.import'))" class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p v-if="stockAdjustments.length > 0" class="text-xs text-text-muted" aria-live="polite">Showing {{ stockAdjustments.length }} adjustment(s) in this date range.</p>
                    <div v-if="hasOpenFiscalYear && can('opening_stock.import')" class="ml-auto flex items-center gap-2">
                        <Button variant="secondary" tone="neutral" type="button" @click="openImport">
                            <Upload class="size-4" />
                            Import opening stock (CSV)
                        </Button>
                    </div>
                </div>

                <div v-if="stockAdjustments.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                    <template v-if="hasActiveFilters">
                        <p class="text-sm font-semibold text-text-strong">No stock adjustments in this date range</p>
                        <Button variant="secondary" tone="neutral" @click="clearFilters">
                            <X class="size-4" />
                            Clear filters
                        </Button>
                    </template>
                    <template v-else>
                        <p class="text-sm font-semibold text-text-strong">No stock adjustments yet</p>
                        <p v-if="hasOpenFiscalYear && can('stock_adjustments.create')" class="text-xs text-text-muted">Record your first adjustment and it will be listed here.</p>
                        <Button v-if="hasOpenFiscalYear && can('stock_adjustments.create')" variant="primary" tone="purple" @click="showCreateForm = true">
                            <Plus class="size-4" />
                            New adjustment
                        </Button>
                    </template>
                </div>
                <DataTable v-else :columns="columns" :data="stockAdjustments" :page-size="10" empty-message="No stock adjustments" />
            </Card>
        </template>

        <Modal :open="!!cancelling" title="Cancel stock adjustment" size="compact" @update:open="onCancelOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitCancel">
                <p class="text-sm text-text-muted">
                    This reverts the quantity impact of this adjustment. This cannot be undone.
                </p>
                <div>
                    <Label class="mb-1">Reason <span class="text-danger">*</span></Label>
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

        <Modal :open="importModalOpen" title="Import opening stock" @update:open="onImportModalOpenChange">
            <div v-if="!importResult" class="flex flex-col gap-4">
                <p class="text-[13px] text-text-muted">
                    Download the template, fill in one item per row (items are matched by exact name), then
                    upload the completed CSV file. Every row is posted as an "opening" stock adjustment line on
                    the date and store below, and the total is posted to the ledger as Opening Stock. Rows with
                    an unknown item, a non-stockable item, an invalid quantity, or a repeated item are skipped
                    and reported after import.
                </p>
                <p
                    v-if="hasOpeningStockImport"
                    class="border-[1.5px] border-warning-text bg-warning-bg px-3 py-2 text-[13px] text-warning-text"
                >
                    Opening stock has already been imported. Importing again <strong>replaces</strong> it: the
                    previous opening batch is cancelled, its quantities and its ledger entry reversed, and this
                    file becomes the opening stock. Nothing is added on top.
                </p>
                <a
                    href="/stock-adjustments/opening-stock/template"
                    class="inline-flex w-fit items-center gap-1.5 text-[13px] font-bold text-primary hover:underline"
                >
                    Download CSV template
                </a>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <Label class="mb-1">Date <span class="text-danger">*</span></Label>
                        <NepaliDateInput v-model="importForm.date" required />
                        <p v-if="importForm.errors.date" class="mt-1 text-sm text-danger">{{ importForm.errors.date }}</p>
                    </div>
                    <div>
                        <div class="mb-1 flex items-center gap-1">
                            <Label>Store</Label>
                            <InfoTip text="Opening stock is the quantity you already had when you started using the system." />
                        </div>
                        <Combobox
                            :model-value="importForm.store_id"
                            :options="storeOptions"
                            placeholder="Default store"
                            @update:model-value="(v) => (importForm.store_id = v)"
                        />
                        <p v-if="importForm.errors.store_id" class="mt-1 text-sm text-danger">{{ importForm.errors.store_id }}</p>
                    </div>
                </div>

                <div>
                    <Label for="opening-stock-import-file" class="mb-1">
                        CSV file <span class="text-danger">*</span>
                    </Label>
                    <input
                        id="opening-stock-import-file"
                        type="file"
                        accept=".csv,text/csv"
                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base outline-none file:mr-3 file:cursor-pointer file:border-0 file:bg-primary-tint file:px-3 file:py-1.5 file:text-[12px] file:font-bold file:text-primary"
                        @change="onImportFileChange"
                    />
                    <p v-if="importForm.errors.file" class="mt-1 text-sm text-danger">{{ importForm.errors.file }}</p>
                    <p v-if="importForm.errors.lines" class="mt-1 text-sm text-danger">{{ importForm.errors.lines }}</p>
                </div>
            </div>

            <div v-else class="flex flex-col gap-4">
                <p class="text-[13px] font-semibold text-text-base">
                    Imported opening stock for {{ importResult.imported }} of
                    {{ importResult.imported + importResult.skipped.length }} row(s).
                </p>
                <p v-if="importResult.replaced" class="text-[13px] text-text-muted">
                    The previous opening stock import (#{{ importResult.replaced }}) was cancelled and replaced.
                </p>
                <div v-if="importResult.skipped.length" class="max-h-64 overflow-auto border-[1.5px] border-border">
                    <table class="w-full text-left text-[12px]">
                        <thead class="bg-bg-subtle">
                            <tr>
                                <th class="px-2 py-1.5">Row</th>
                                <th class="px-2 py-1.5">Item</th>
                                <th class="px-2 py-1.5">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in importResult.skipped" :key="item.row" class="border-t border-border">
                                <td class="px-2 py-1.5">{{ item.row }}</td>
                                <td class="px-2 py-1.5">{{ item.name || '-' }}</td>
                                <td class="px-2 py-1.5">{{ item.reason }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <template #footer>
                <template v-if="!importResult">
                    <Button variant="secondary" tone="neutral" type="button" @click="closeImportModal">Cancel</Button>
                    <Button
                        variant="primary"
                        tone="purple"
                        type="button"
                        :loading="importForm.processing"
                        :disabled="importForm.processing || !importForm.file || !importForm.date"
                        @click="submitImport"
                    >
                        Import opening stock
                    </Button>
                </template>
                <template v-else>
                    <Button variant="primary" tone="purple" type="button" @click="closeImportModal">Done</Button>
                </template>
            </template>
        </Modal>
    </div>
</template>
