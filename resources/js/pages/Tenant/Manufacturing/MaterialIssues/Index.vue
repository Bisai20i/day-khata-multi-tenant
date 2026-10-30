<script setup>
import { computed, h, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Printer, Search, SlidersHorizontal, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Select from '@/components/ui/Select.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PaginatorNav from '@/components/ui/PaginatorNav.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { usePermissions } from '@/composables/usePermissions';
import { useOpenFiscalYear } from '@/composables/useOpenFiscalYear';
import { formatMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    notes: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    // Server-side window the list was built from; defaults to the open fiscal year.
    filters: { type: Object, default: () => ({ from: null, to: null, status: null }) },
});

const page = usePage();
const { toast } = useToast();
const { can } = usePermissions();
const { hasOpenFiscalYear } = useOpenFiscalYear();
useLayoutChrome('Material Issues');

watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const filter = ref({ from: props.filters.from ?? '', to: props.filters.to ?? '', status: props.filters.status ?? '' });
const filtering = ref(false);

watch(
    () => props.filters,
    (f) => {
        filter.value = { from: f.from ?? '', to: f.to ?? '', status: f.status ?? '' };
    },
);

const statusOptions = [
    { value: 'all', label: 'All statuses' },
    { value: 'posted', label: 'Posted' },
    { value: 'cancelled', label: 'Cancelled' },
];

function applyFilter() {
    router.get('/material-issues', { ...filter.value }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (filtering.value = true),
        onFinish: () => (filtering.value = false),
    });
}

function clearFilter() {
    filter.value = { from: '', to: '', status: '' };
    applyFilter();
}

const showFilters = ref(false);

const activeFilterChips = computed(() => {
    const chips = [];

    if (props.filters.from) chips.push({ key: 'from', label: `From ${formatBsDate(props.filters.from)}` });
    if (props.filters.to) chips.push({ key: 'to', label: `To ${formatBsDate(props.filters.to)}` });
    if (props.filters.status) chips.push({ key: 'status', label: props.filters.status === 'cancelled' ? 'Cancelled' : 'Posted' });

    return chips;
});

const hasActiveFilters = computed(() => activeFilterChips.value.length > 0);

function removeFilter(key) {
    filter.value = { ...filter.value, [key]: '' };
    applyFilter();
}

const columns = [
    {
        id: 'number',
        header: 'No.',
        numeric: false,
        cell: ({ row }) =>
            h(Link, { href: `/material-issues/${row.original.id}`, class: 'font-semibold text-primary hover:underline' }, () => `MI-${row.original.number}`),
    },
    {
        id: 'date',
        header: 'Date (BS)',
        numeric: false,
        // BS first, the AD date beside it as the cross-reference (contract C8 / C9).
        cell: ({ row }) =>
            h('div', { class: 'whitespace-nowrap' }, [
                formatBsDate(row.original.date),
                h('span', { class: 'ml-1 text-text-faint' }, `(${String(row.original.date ?? '').slice(0, 10)})`),
            ]),
    },
    { id: 'store', header: 'Store', numeric: false, cell: ({ row }) => row.original.store?.name ?? '-' },
    {
        id: 'production_entry',
        header: 'Production entry',
        numeric: false,
        cell: ({ row }) => (row.original.production_entry ? `PE-${row.original.production_entry.number}` : 'Unattached'),
    },
    { id: 'total_cost', header: 'Total cost', numeric: true, cell: ({ row }) => formatMoney(row.original.total_cost) },
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
            can('material_issues.print')
                ? h(Tooltip, { label: 'Print note' }, () =>
                      h(
                          'a',
                          {
                              href: `/material-issues/${row.original.id}/print`,
                              target: '_blank',
                              rel: 'noopener',
                              class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                              'aria-label': `Print material issue MI-${row.original.number}`,
                          },
                          [h(Printer, { class: 'h-[13px] w-[13px]' })],
                      ),
                  )
                : null,
    },
];
</script>

<template>
    <div>
        <PageHeader title="Material Issues" description="Raw material issued from a store into work in process.">
            <Link v-if="hasOpenFiscalYear && can('material_issues.create')" href="/material-issues/create">
                <Button variant="primary" tone="purple" type="button">
                    <Plus class="size-4" />
                    New material issue
                </Button>
            </Link>
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
                    <NepaliDateInput v-model="filter.from" @update:model-value="applyFilter" />
                </div>
                <div class="min-w-[160px]">
                    <Label class="mb-1">To date (BS)</Label>
                    <NepaliDateInput v-model="filter.to" @update:model-value="applyFilter" />
                </div>
                <div class="min-w-[180px]">
                    <Label class="mb-1">Status</Label>
                    <Select :model-value="filter.status || 'all'" :options="statusOptions" @update:model-value="(v) => { filter.status = v === 'all' ? '' : v; applyFilter(); }" />
                </div>
                <Button variant="secondary" tone="neutral" :loading="filtering" @click="applyFilter">
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
                <button type="button" class="cursor-pointer text-xs font-semibold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-primary" @click="clearFilter">
                    Clear all
                </button>
            </div>
        </Card>

        <Card variant="panel" class="bg-white">
            <p v-if="notes.data.length > 0" class="mb-3 text-xs text-text-muted" aria-live="polite">Showing {{ notes.from }}–{{ notes.to }} of {{ notes.total }}</p>

            <div v-if="notes.data.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                <template v-if="hasActiveFilters">
                    <p class="text-sm font-semibold text-text-strong">No material issue notes match these filters</p>
                    <Button variant="secondary" tone="neutral" @click="clearFilter">
                        <X class="size-4" />
                        Clear filters
                    </Button>
                </template>
                <template v-else>
                    <p class="text-sm font-semibold text-text-strong">No material issue notes yet</p>
                    <template v-if="hasOpenFiscalYear && can('material_issues.create')">
                        <p class="text-xs text-text-muted">Issue raw material from a store into work in process and the note will be listed here.</p>
                        <Link href="/material-issues/create">
                            <Button variant="primary" tone="purple" type="button">
                                <Plus class="size-4" aria-hidden="true" />
                                New material issue
                            </Button>
                        </Link>
                    </template>
                </template>
            </div>
            <template v-else>
                <DataTable :columns="columns" :data="notes.data" :page-size="Math.max(notes.data.length, 1)" empty-message="No material issue notes" />
                <PaginatorNav :paginator="notes" label="Material issue pagination" />
            </template>
        </Card>
    </div>
</template>
