<script setup>
import { computed, h, reactive, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Plus, Search, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Label from '@/components/ui/Label.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PaginatorNav from '@/components/ui/PaginatorNav.vue';
import { useToast } from '@/composables/useToast';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney } from '@/lib/money';

defineOptions({ layout: AppLayout });

const props = defineProps({
    // A LengthAwarePaginator page; every money field is a decimal string.
    staff: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    filters: {
        type: Object,
        default: () => ({ q: null, status: null }),
    },
});

const page = usePage();
const { toast } = useToast();
const { can } = usePermissions();
useLayoutChrome('Staff');

// UI mirrors of routes/tenant-hr.php; each route still enforces its own key.
const canCreateStaff = computed(() => can('hr_staff.create'));
const canViewStatement = computed(() => can('hr_reports.view'));

// Saving a staff member redirects here or back to the form; watch the flash
// prop so a re-render in place (no remount) still shows the message.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const statusOptions = [
    { value: 'all', label: 'All staff' },
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
];

const filterState = reactive({
    q: props.filters.q ?? '',
    status: props.filters.status ?? 'all',
});
const filtering = ref(false);

const hasActiveFilters = computed(() => !!(props.filters.q || (props.filters.status && props.filters.status !== 'all')));

function queryParams() {
    return {
        q: filterState.q || undefined,
        status: filterState.status && filterState.status !== 'all' ? filterState.status : undefined,
    };
}

function applyFilters() {
    router.get('/hr/staff', queryParams(), {
        preserveState: true,
        preserveScroll: true,
        onStart: () => (filtering.value = true),
        onFinish: () => (filtering.value = false),
    });
}

function clearFilters() {
    filterState.q = '';
    filterState.status = 'all';
    applyFilters();
}

const actionLinkClass = 'text-xs font-semibold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-primary';

const columns = computed(() => [
    {
        id: 'staff_code',
        header: 'Code',
        numeric: false,
        cell: ({ row }) => row.original.staff_code ?? '-',
    },
    {
        id: 'name',
        header: 'Name',
        numeric: false,
        cell: ({ row }) => h('span', { class: 'font-semibold text-text-strong' }, row.original.name),
    },
    {
        id: 'designation',
        header: 'Designation',
        numeric: false,
        cell: ({ row }) => row.original.designation ?? '-',
    },
    {
        id: 'department',
        header: 'Department',
        numeric: false,
        cell: ({ row }) => row.original.department ?? '-',
    },
    {
        id: 'mobile_no',
        header: 'Mobile no.',
        numeric: false,
        cell: ({ row }) => row.original.mobile_no ?? '-',
    },
    {
        id: 'monthly_pay',
        header: 'Monthly pay (Rs.)',
        numeric: true,
        cell: ({ row }) => formatMoney(row.original.monthly_pay),
    },
    {
        id: 'payable',
        header: 'Salary payable (Rs.)',
        numeric: true,
        cell: ({ row }) => formatMoney(row.original.payable),
    },
    {
        id: 'advance',
        header: 'Advance receivable (Rs.)',
        numeric: true,
        cell: ({ row }) => formatMoney(row.original.advance),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(Badge, { pill: true, variant: row.original.is_active ? 'success' : 'neutral' }, () => (row.original.is_active ? 'Active' : 'Inactive')),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-3' }, [
                h(Link, { href: `/hr/staff/${row.original.id}/edit`, class: actionLinkClass, 'aria-label': `Edit ${row.original.name}` }, () => 'Edit'),
                canViewStatement.value
                    ? h(Link, { href: `/hr/staff/${row.original.id}/statement`, class: actionLinkClass, 'aria-label': `Statement for ${row.original.name}` }, () => 'Statement')
                    : null,
            ]),
    },
]);
</script>

<template>
    <div>
        <PageHeader title="Staff" description="Your employees, their recurring pay, and what you owe them or they owe you.">
            <Button v-if="canCreateStaff" :as="Link" href="/hr/staff/create" variant="primary" tone="purple">
                <Plus class="size-4" aria-hidden="true" />
                Add staff
            </Button>
        </PageHeader>

        <Card variant="panel" class="mb-4 bg-white">
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
                <div class="min-w-[220px] flex-1">
                    <Label for="staff-search" class="mb-1">Search</Label>
                    <div class="relative">
                        <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-text-faint" />
                        <Input id="staff-search" v-model="filterState.q" type="text" placeholder="Name, code or designation" class="pl-8" />
                    </div>
                </div>
                <div class="min-w-[180px]">
                    <Label class="mb-1">Status</Label>
                    <Select v-model="filterState.status" :options="statusOptions" @update:model-value="applyFilters" />
                </div>
                <Button variant="secondary" tone="neutral" type="submit" :loading="filtering">
                    <Search class="size-4" />
                    Filter
                </Button>
            </form>
        </Card>

        <Card variant="panel" class="bg-white">
            <div v-if="staff.data.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                <template v-if="hasActiveFilters">
                    <p class="text-sm font-semibold text-text-strong">No staff match these filters</p>
                    <Button variant="secondary" tone="neutral" @click="clearFilters">
                        <X class="size-4" />
                        Clear filters
                    </Button>
                </template>
                <template v-else>
                    <p class="text-sm font-semibold text-text-strong">No staff yet</p>
                    <p v-if="canCreateStaff" class="text-xs text-text-muted">Add your first staff member to start tracking their pay.</p>
                    <Button v-if="canCreateStaff" :as="Link" href="/hr/staff/create" variant="primary" tone="purple">
                        <Plus class="size-4" aria-hidden="true" />
                        Add staff
                    </Button>
                </template>
            </div>
            <template v-else>
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-text-muted" aria-live="polite">Showing {{ staff.from }}–{{ staff.to }} of {{ staff.total }}</p>
                </div>
                <DataTable :columns="columns" :data="staff.data" :page-size="Math.max(staff.data.length, 1)" empty-message="No staff" />
                <PaginatorNav :paginator="staff" label="Staff pagination" />
            </template>
        </Card>
    </div>
</template>
