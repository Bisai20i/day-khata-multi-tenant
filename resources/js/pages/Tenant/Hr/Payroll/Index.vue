<script setup>
import { computed, h, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Search, SlidersHorizontal, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Select from '@/components/ui/Select.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PaginatorNav from '@/components/ui/PaginatorNav.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { usePermissions } from '@/composables/usePermissions';
import { useBranchListColumn } from '@/composables/useBranchListColumn';
import { formatMoney, sumMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

const props = defineProps({
    runs: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    // Only what the user supplied (null when not supplied).
    filters: { type: Object, default: () => ({ from: null, to: null, status: null }) },
    // Effective date window the list was built from (fiscal-year defaults when no filter is given).
    window: { type: Object, default: () => ({ from: null, to: null }) },
});

const page = usePage();
const { branchColumns } = useBranchListColumn();
const { toast } = useToast();
const { can } = usePermissions();
useLayoutChrome('Payroll');

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

const STATUS_META = {
    draft: { label: 'Draft', variant: 'warning' },
    posted: { label: 'Posted', variant: 'success' },
    cancelled: { label: 'Cancelled', variant: 'danger' },
};

const statusOptions = [
    { value: 'all', label: 'All statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'posted', label: 'Posted' },
    { value: 'cancelled', label: 'Cancelled' },
];

function applyFilter() {
    router.get('/hr/payroll', { ...filter.value }, {
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
    if (props.filters.status) chips.push({ key: 'status', label: STATUS_META[props.filters.status]?.label ?? props.filters.status });

    return chips;
});

const hasActiveFilters = computed(() => activeFilterChips.value.length > 0);

function removeFilter(key) {
    filter.value = { ...filter.value, [key]: '' };
    applyFilter();
}

const windowLabel = computed(() =>
    props.window?.from && props.window?.to ? `${formatBsDate(props.window.from)} to ${formatBsDate(props.window.to)} (BS)` : '',
);

// Exact decimal sums of the posted rows on this page (never floats). Draft and
// cancelled runs hold no ledger entry, so they are left out.
const pageTotals = computed(() => {
    const rows = props.runs.data.filter((run) => run.status === 'posted');

    return {
        gross: sumMoney(rows.map((run) => run.total_gross ?? '0')),
        recovery: sumMoney(rows.map((run) => run.total_recovery ?? '0')),
        net: sumMoney(rows.map((run) => run.total_net ?? '0')),
    };
});

const columns = [
    {
        id: 'number',
        header: 'No.',
        numeric: false,
        cell: ({ row }) =>
            h(
                Link,
                { href: `/hr/payroll/${row.original.id}`, class: 'font-semibold text-primary hover:underline' },
                () => row.original.display_number ?? 'Draft',
            ),
    },
    // The run's own branch, only for a multi-branch company viewing all branches.
    ...branchColumns(),
    { id: 'period', header: 'Period', numeric: false, cell: ({ row }) => row.original.period_label },
    {
        id: 'range',
        header: 'Period range (BS)',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'whitespace-nowrap' }, `${formatBsDate(row.original.period_start)} - ${formatBsDate(row.original.period_end)}`),
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
    { id: 'lines_count', header: 'Staff', numeric: true, cell: ({ row }) => String(row.original.lines_count ?? 0) },
    { id: 'total_gross', header: 'Gross', numeric: true, cell: ({ row }) => formatMoney(row.original.total_gross ?? '0') },
    { id: 'total_recovery', header: 'Advance recovery', numeric: true, cell: ({ row }) => formatMoney(row.original.total_recovery ?? '0') },
    { id: 'total_net', header: 'Net pay', numeric: true, cell: ({ row }) => formatMoney(row.original.total_net ?? '0') },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) => {
            const meta = STATUS_META[row.original.status] ?? { label: row.original.status, variant: 'neutral' };

            return h(Badge, { pill: true, variant: meta.variant }, () => meta.label);
        },
    },
];
</script>

<template>
    <div>
        <PageHeader title="Payroll" description="Monthly payroll runs. Open a draft, review each staff member's pay, then post it to the books.">
            <Link v-if="can('payroll.create')" href="/hr/payroll/create">
                <Button variant="primary" tone="purple" type="button">
                    <Plus class="size-4" />
                    New payroll run
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
            <p v-if="runs.data.length > 0" class="mb-3 text-xs text-text-muted" aria-live="polite">
                Showing {{ runs.from }}–{{ runs.to }} of {{ runs.total }}
                <template v-if="windowLabel"> &middot; Period window {{ windowLabel }}</template>
            </p>

            <div v-if="runs.data.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                <template v-if="hasActiveFilters">
                    <p class="text-sm font-semibold text-text-strong">No payroll runs match these filters</p>
                    <Button variant="secondary" tone="neutral" @click="clearFilter">
                        <X class="size-4" />
                        Clear filters
                    </Button>
                </template>
                <template v-else>
                    <p class="text-sm font-semibold text-text-strong">No payroll runs yet</p>
                    <p v-if="windowLabel" class="text-xs text-text-muted">Only runs dated in the period window {{ windowLabel }} are listed; set From and To above to look further back.</p>
                    <template v-if="can('payroll.create')">
                        <p class="text-xs text-text-muted">Open a draft run for a pay period and it will be listed here.</p>
                        <Link href="/hr/payroll/create">
                            <Button variant="primary" tone="purple" type="button">
                                <Plus class="size-4" aria-hidden="true" />
                                New payroll run
                            </Button>
                        </Link>
                    </template>
                </template>
            </div>
            <template v-else>
                <DataTable :columns="columns" :data="runs.data" :page-size="Math.max(runs.data.length, 1)" empty-message="No payroll runs" />

                <div class="mt-3 overflow-x-auto border-[1.5px] border-border">
                    <table class="w-full table-auto border-collapse text-sm">
                        <thead>
                            <tr class="divide-x divide-border border-b-[1.5px] border-border bg-bg-subtle">
                                <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Gross (posted runs on this page)</th>
                                <th class="px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Advance recovery (posted)</th>
                                <th class="bg-primary-tint px-3 py-1.5 text-left text-[10px] font-bold tracking-[.8px] text-primary uppercase">Net pay (posted)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="divide-x divide-border">
                                <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(pageTotals.gross) }}</td>
                                <td class="px-3 py-1.5 font-bold text-text-strong">{{ formatMoney(pageTotals.recovery) }}</td>
                                <td class="bg-primary-tint px-3 py-1.5 text-base font-extrabold text-primary">{{ formatMoney(pageTotals.net) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <PaginatorNav :paginator="runs" label="Payroll run pagination" />
            </template>
        </Card>
    </div>
</template>
