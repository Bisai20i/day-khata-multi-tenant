<script setup>
import { computed, h, reactive, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Printer, Search, SlidersHorizontal, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Select from '@/components/ui/Select.vue';
import Label from '@/components/ui/Label.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import PaginatorNav from '@/components/ui/PaginatorNav.vue';
import ProductionStatusBadge from '@/components/manufacturing/ProductionStatusBadge.vue';
import { useToast } from '@/composables/useToast';
import { usePermissions } from '@/composables/usePermissions';
import { useOpenFiscalYear } from '@/composables/useOpenFiscalYear';
import { formatQuantity } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    entries: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    filters: { type: Object, default: () => ({ status: null, from: null, to: null }) },
    statuses: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();
const { can } = usePermissions();
const { hasOpenFiscalYear } = useOpenFiscalYear();
useLayoutChrome('Production');

// Create redirects back to this route, which Inertia re-renders in place, so
// the flash is watched rather than read on mount (same as Purchases/Index.vue).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const filterState = reactive({
    status: props.filters.status ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
const filtering = ref(false);
const showFilters = ref(false);

function statusLabel(value) {
    return value.replace('_', ' ').replace(/^./, (c) => c.toUpperCase());
}

const statusOptions = computed(() => [
    { value: 'all', label: 'All statuses' },
    ...props.statuses.map((value) => ({ value, label: statusLabel(value) })),
]);

function queryParams() {
    return {
        status: filterState.status && filterState.status !== 'all' ? filterState.status : undefined,
        from: filterState.from || undefined,
        to: filterState.to || undefined,
    };
}

function reload(params = queryParams()) {
    router.get('/production-entries', params, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => (filtering.value = true),
        onFinish: () => (filtering.value = false),
    });
}

const activeFilterChips = computed(() => {
    const chips = [];

    if (props.filters.status) chips.push({ key: 'status', label: statusLabel(props.filters.status) });
    if (props.filters.from) chips.push({ key: 'from', label: `From ${formatBsDate(props.filters.from)}` });
    if (props.filters.to) chips.push({ key: 'to', label: `To ${formatBsDate(props.filters.to)}` });

    return chips;
});

const hasActiveFilters = computed(() => activeFilterChips.value.length > 0);

function removeFilter(key) {
    filterState[key] = '';
    reload();
}

function clearFilters() {
    filterState.status = '';
    filterState.from = '';
    filterState.to = '';
    reload({});
}

const columns = [
    {
        id: 'number',
        header: 'No.',
        numeric: false,
        cell: ({ row }) =>
            h(Link, { href: `/production-entries/${row.original.id}`, class: 'font-semibold text-primary hover:underline' }, () => `PE-${row.original.number}`),
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
    { id: 'finished_item', header: 'Finished item', numeric: false, cell: ({ row }) => row.original.finished_item?.name ?? '-' },
    { id: 'store', header: 'Store', numeric: false, cell: ({ row }) => row.original.store?.name ?? '-' },
    {
        id: 'planned_quantity',
        header: 'Planned',
        numeric: true,
        cell: ({ row }) => `${formatQuantity(row.original.planned_quantity)} ${row.original.finished_item?.unit ?? ''}`.trim(),
    },
    { id: 'status', header: 'Status', numeric: false, cell: ({ row }) => h(ProductionStatusBadge, { status: row.original.status }) },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            can('production_entries.print')
                ? h(Tooltip, { label: 'Print cost sheet' }, () =>
                      h(
                          'a',
                          {
                              href: `/production-entries/${row.original.id}/print`,
                              target: '_blank',
                              rel: 'noopener',
                              class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 hover:bg-primary-tint hover:text-primary',
                              'aria-label': `Print cost sheet for PE-${row.original.number}`,
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
        <PageHeader title="Production" description="Production entries turn issued raw material into finished goods and carry the cost sheet.">
            <Link v-if="hasOpenFiscalYear && can('production_entries.create')" href="/production-entries/create">
                <Button variant="primary" tone="purple" type="button">
                    <Plus class="size-4" aria-hidden="true" />
                    New production entry
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
                    <NepaliDateInput v-model="filterState.from" />
                </div>
                <div class="min-w-[160px]">
                    <Label class="mb-1">To date (BS)</Label>
                    <NepaliDateInput v-model="filterState.to" />
                </div>
                <div class="min-w-[180px]">
                    <Label class="mb-1">Status</Label>
                    <Select :model-value="filterState.status || 'all'" :options="statusOptions" @update:model-value="(v) => (filterState.status = v === 'all' ? '' : v)" />
                </div>
                <Button variant="secondary" tone="neutral" :loading="filtering" @click="reload()">
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
            <p v-if="entries.data.length > 0" class="mb-3 text-xs text-text-muted" aria-live="polite">Showing {{ entries.from }}–{{ entries.to }} of {{ entries.total }}</p>

            <div v-if="entries.data.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                <template v-if="hasActiveFilters">
                    <p class="text-sm font-semibold text-text-strong">No production entries match these filters</p>
                    <Button variant="secondary" tone="neutral" @click="clearFilters">
                        <X class="size-4" />
                        Clear filters
                    </Button>
                </template>
                <template v-else>
                    <p class="text-sm font-semibold text-text-strong">No production entries yet</p>
                    <template v-if="hasOpenFiscalYear && can('production_entries.create')">
                        <p class="text-xs text-text-muted">Start an entry from issued raw material and it will be listed here with its cost sheet.</p>
                        <Link href="/production-entries/create">
                            <Button variant="primary" tone="purple" type="button">
                                <Plus class="size-4" aria-hidden="true" />
                                New production entry
                            </Button>
                        </Link>
                    </template>
                </template>
            </div>
            <template v-else>
                <DataTable :columns="columns" :data="entries.data" :page-size="Math.max(entries.data.length, 1)" empty-message="No production entries" />
                <PaginatorNav :paginator="entries" label="Production entries pagination" />
            </template>
        </Card>
    </div>
</template>
