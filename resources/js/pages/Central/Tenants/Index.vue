<script setup>
import { h, onMounted, reactive, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { CirclePause, CirclePlay, Eye, LogIn, Plus, Search, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { useToast } from '@/composables/useToast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tenants: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0, prev_page_url: null, next_page_url: null }),
    },
    filters: {
        type: Object,
        default: () => ({ search: null, status: null }),
    },
    statusOptions: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Tenants');

onMounted(() => {
    if (page.props.flash?.status) {
        toast({ message: page.props.flash.status, variant: 'success' });
    }
});

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? null);
const searching = ref(false);

function applyFilters() {
    router.get(
        window.location.pathname,
        { search: search.value || undefined, status: status.value || undefined },
        { preserveState: true, preserveScroll: true, onStart: () => (searching.value = true), onFinish: () => (searching.value = false) },
    );
}

function clearFilters() {
    search.value = '';
    status.value = null;
    router.get(
        window.location.pathname,
        {},
        { preserveState: true, preserveScroll: true, onStart: () => (searching.value = true), onFinish: () => (searching.value = false) },
    );
}

// Keyed by tenant id -> the action currently in flight for that row ('suspend'
// | 'resume' | 'impersonate' | null), so only the button that was actually
// clicked shows a spinner, not every row's button at once.
const rowAction = reactive({});

function isRowActionLoading(tenantId, action) {
    return rowAction[tenantId] === action;
}

function runRowAction(tenant, action, url) {
    rowAction[tenant.id] = action;
    router.post(url, {}, { preserveScroll: true, onFinish: () => (rowAction[tenant.id] = null) });
}

function suspendRow(tenant) {
    runRowAction(tenant, 'suspend', `/tenants/${tenant.id}/suspend`);
}

function resumeRow(tenant) {
    runRowAction(tenant, 'resume', `/tenants/${tenant.id}/resume`);
}

function impersonateRow(tenant) {
    runRowAction(tenant, 'impersonate', `/tenants/${tenant.id}/impersonate`);
}

const statusBadgeVariant = {
    active: 'success',
    provisioning: 'warning',
    suspended: 'danger',
};

const columns = [
    { accessorKey: 'company_name', header: 'Company' },
    { accessorKey: 'domain', header: 'Domain' },
    {
        accessorKey: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-1.5' }, [
                h(Badge, { variant: statusBadgeVariant[row.original.status] ?? 'neutral', pill: true }, () => row.original.status),
                row.original.past_grace_period
                    ? h(Badge, { variant: 'danger', pill: true }, () => 'Past grace period')
                    : null,
                row.original.trial_expired
                    ? h(Badge, { variant: 'warning', pill: true }, () => 'Trial expired')
                    : null,
            ]),
    },
    { accessorKey: 'created_at', header: 'Created' },
    {
        id: 'actions',
        header: '',
        numeric: false,
        cell: ({ row }) => {
            const tenant = row.original;

            const buttons = [
                h(
                    Tooltip,
                    { label: 'View tenant' },
                    () =>
                        h(
                            Link,
                            {
                                href: `/tenants/${tenant.id}`,
                                class: 'flex h-8 w-8 items-center justify-center bg-bg-subtle text-text-muted transition-colors duration-150 ease-out hover:bg-primary-tint hover:text-primary',
                                'aria-label': 'View tenant',
                            },
                            () => h(Eye, { class: 'size-[13px]' }),
                        ),
                ),
            ];

            if (tenant.status === 'active') {
                buttons.push(
                    h(Tooltip, { label: 'Impersonate admin' }, () =>
                        h(
                            Button,
                            {
                                variant: 'icon',
                                loading: isRowActionLoading(tenant.id, 'impersonate'),
                                'aria-label': 'Impersonate admin',
                                onClick: () => impersonateRow(tenant),
                            },
                            () => h(LogIn, { class: 'size-[13px]' }),
                        ),
                    ),
                    h(Tooltip, { label: 'Suspend tenant' }, () =>
                        h(
                            Button,
                            {
                                variant: 'icon',
                                loading: isRowActionLoading(tenant.id, 'suspend'),
                                'aria-label': 'Suspend tenant',
                                onClick: () => suspendRow(tenant),
                            },
                            () => h(CirclePause, { class: 'size-[13px]' }),
                        ),
                    ),
                );
            } else if (tenant.status === 'suspended') {
                buttons.push(
                    h(Tooltip, { label: 'Resume tenant' }, () =>
                        h(
                            Button,
                            {
                                variant: 'icon',
                                tone: 'success',
                                loading: isRowActionLoading(tenant.id, 'resume'),
                                'aria-label': 'Resume tenant',
                                onClick: () => resumeRow(tenant),
                            },
                            () => h(CirclePlay, { class: 'size-[13px]' }),
                        ),
                    ),
                );
            }

            return h('div', { class: 'flex items-center gap-1.5' }, buttons);
        },
    },
];
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Tenants</h2>
            <Button :as="Link" href="/tenants/create" variant="primary" tone="purple">
                <Plus class="size-4" />
                New tenant
            </Button>
        </div>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[240px]">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Search</label>
                    <Input
                        v-model="search"
                        :icon="Search"
                        placeholder="Company name, domain, or email"
                        @keyup.enter="applyFilters"
                    />
                </div>
                <div class="min-w-[180px]">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Status</label>
                    <Select v-model="status" :options="statusOptions" placeholder="All statuses" />
                </div>
                <Button variant="primary" tone="purple" :loading="searching" @click="applyFilters">
                    <Search class="size-4" />
                    Search
                </Button>
                <Button v-if="filters.search || filters.status" variant="secondary" tone="purple" @click="clearFilters">
                    <X class="size-4" />
                    Clear
                </Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable
                :columns="columns"
                :data="tenants.data"
                :page-size="Math.max(tenants.data.length, 1)"
                empty-message="No tenants found."
            />

            <div v-if="tenants.data.length > 0" class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-text-muted">Showing {{ tenants.from }}–{{ tenants.to }} of {{ tenants.total }}</p>
                <div class="flex items-center gap-2">
                    <Link
                        v-if="tenants.prev_page_url"
                        :href="tenants.prev_page_url"
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
                    <span class="text-xs text-text-muted">Page {{ tenants.current_page }} of {{ tenants.last_page }}</span>
                    <Link
                        v-if="tenants.next_page_url"
                        :href="tenants.next_page_url"
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
    </div>
</template>
