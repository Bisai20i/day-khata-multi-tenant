<script setup>
import { computed, h, onMounted, reactive, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, CirclePause, CirclePlay, Eye, LogIn, Plus, Search, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useConfirm } from '@/composables/useConfirm';
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
const { confirm } = useConfirm();
useLayoutChrome('Tenants');

onMounted(() => {
    if (page.props.flash?.status) {
        toast({ message: page.props.flash.status, variant: 'success' });
    }
});

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? null);
const searching = ref(false);

const hasFilters = computed(() => Boolean(props.filters.search || props.filters.status));

function applyFilters() {
    router.get(
        window.location.pathname,
        { search: search.value || undefined, status: status.value || undefined },
        { preserveState: true, preserveScroll: true, onStart: () => (searching.value = true), onFinish: () => (searching.value = false) },
    );
}

watch(status, (value) => {
    if ((value || null) !== (props.filters.status || null)) {
        applyFilters();
    }
});

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

async function suspendRow(tenant) {
    const confirmed = await confirm({
        message: `Suspend ${tenant.company_name}? Its users will be blocked from signing in until you resume the tenant. No data is deleted.`,
        tone: 'danger',
        confirmLabel: 'Suspend',
    });

    if (confirmed) {
        runRowAction(tenant, 'suspend', `/tenants/${tenant.id}/suspend`);
    }
}

function resumeRow(tenant) {
    runRowAction(tenant, 'resume', `/tenants/${tenant.id}/resume`);
}

async function impersonateRow(tenant) {
    const confirmed = await confirm({
        message: `Sign in to ${tenant.company_name} as its admin? You will act inside their account, and the session is recorded in the activity log.`,
        confirmLabel: 'Impersonate',
    });

    if (confirmed) {
        runRowAction(tenant, 'impersonate', `/tenants/${tenant.id}/impersonate`);
    }
}

const statusBadgeVariant = {
    active: 'success',
    provisioning: 'warning',
    suspended: 'danger',
};

const statusLabel = {
    active: 'Active',
    provisioning: 'Provisioning',
    suspended: 'Suspended',
};

const secondaryActionClass = 'inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs';

const columns = [
    { accessorKey: 'company_name', header: 'Company' },
    { accessorKey: 'domain', header: 'Domain' },
    {
        accessorKey: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex flex-wrap items-center gap-1.5' }, [
                h(Badge, { variant: statusBadgeVariant[row.original.status] ?? 'neutral', pill: true }, () => statusLabel[row.original.status] ?? row.original.status),
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
        header: 'Actions',
        numeric: false,
        cell: ({ row }) => {
            const tenant = row.original;

            const buttons = [
                h(Tooltip, { label: 'Open tenant details' }, () =>
                    h(
                        Link,
                        {
                            href: `/tenants/${tenant.id}`,
                            class: 'inline-flex items-center gap-1.5 bg-bg-subtle px-2.5 py-1.5 text-xs font-semibold text-text-base transition-colors duration-150 ease-out hover:bg-primary-tint hover:text-primary',
                            'aria-label': `View ${tenant.company_name}`,
                        },
                        () => [h(Eye, { class: 'size-[13px]', 'aria-hidden': 'true' }), 'View'],
                    ),
                ),
            ];

            if (tenant.status === 'active') {
                buttons.push(
                    h(Tooltip, { label: 'Sign in as this tenant admin' }, () =>
                        h(
                            Button,
                            {
                                variant: 'secondary',
                                class: secondaryActionClass,
                                loading: isRowActionLoading(tenant.id, 'impersonate'),
                                'aria-label': `Impersonate admin of ${tenant.company_name}`,
                                onClick: () => impersonateRow(tenant),
                            },
                            () => [h(LogIn, { class: 'size-[13px]', 'aria-hidden': 'true' }), 'Impersonate'],
                        ),
                    ),
                    h(Tooltip, { label: 'Block this tenant from signing in' }, () =>
                        h(
                            Button,
                            {
                                variant: 'secondary',
                                tone: 'danger',
                                class: secondaryActionClass,
                                loading: isRowActionLoading(tenant.id, 'suspend'),
                                'aria-label': `Suspend ${tenant.company_name}`,
                                onClick: () => suspendRow(tenant),
                            },
                            () => [h(CirclePause, { class: 'size-[13px]', 'aria-hidden': 'true' }), 'Suspend'],
                        ),
                    ),
                );
            } else if (tenant.status === 'suspended') {
                buttons.push(
                    h(Tooltip, { label: 'Restore access for this tenant' }, () =>
                        h(
                            Button,
                            {
                                variant: 'secondary',
                                tone: 'success',
                                class: secondaryActionClass,
                                loading: isRowActionLoading(tenant.id, 'resume'),
                                'aria-label': `Resume ${tenant.company_name}`,
                                onClick: () => resumeRow(tenant),
                            },
                            () => [h(CirclePlay, { class: 'size-[13px]', 'aria-hidden': 'true' }), 'Resume'],
                        ),
                    ),
                );
            }

            return h('div', { class: 'flex flex-wrap items-center gap-1.5' }, buttons);
        },
    },
];
</script>

<template>
    <div>
        <PageHeader title="Tenants" description="Manage every company on the platform: open details, sign in as its admin, or suspend and resume access.">
            <Button :as="Link" href="/tenants/create" variant="primary" tone="purple">
                <Plus class="size-4" />
                New tenant
            </Button>
        </PageHeader>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[240px]">
                    <label for="tenant-search" class="mb-1 block text-xs font-semibold text-text-muted">Search</label>
                    <Input
                        id="tenant-search"
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
                <Button v-if="hasFilters" variant="secondary" tone="purple" @click="clearFilters">
                    <X class="size-4" />
                    Clear filters
                </Button>
            </div>
        </Card>

        <Card variant="panel">
            <div v-if="tenants.data.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                <p class="text-sm font-bold text-text-strong">
                    {{ hasFilters ? 'No tenants match your filters' : 'No tenants yet' }}
                </p>
                <p class="text-sm text-text-muted">
                    {{ hasFilters ? 'Try a different search or status.' : 'Create your first tenant to get started.' }}
                </p>
                <Button v-if="hasFilters" variant="secondary" tone="purple" @click="clearFilters">
                    <X class="size-4" />
                    Clear filters
                </Button>
                <Button v-else :as="Link" href="/tenants/create" variant="primary" tone="purple">
                    <Plus class="size-4" />
                    New tenant
                </Button>
            </div>

            <template v-else>
                <DataTable :columns="columns" :data="tenants.data" :page-size="Math.max(tenants.data.length, 1)" />

                <nav class="mt-3 flex flex-wrap items-center justify-between gap-3" aria-label="Tenants pagination">
                    <p class="text-xs text-text-muted">Showing {{ tenants.from }}–{{ tenants.to }} of {{ tenants.total }}</p>
                    <div class="flex items-center gap-2">
                        <Button
                            v-if="tenants.prev_page_url"
                            :as="Link"
                            :href="tenants.prev_page_url"
                            preserve-state
                            preserve-scroll
                            variant="secondary"
                            tone="purple"
                            aria-label="Previous page"
                        >
                            <ChevronLeft class="size-4" aria-hidden="true" />
                            Previous
                        </Button>
                        <Button v-else variant="secondary" tone="purple" disabled aria-label="Previous page">
                            <ChevronLeft class="size-4" aria-hidden="true" />
                            Previous
                        </Button>
                        <span class="text-xs text-text-muted">Page {{ tenants.current_page }} of {{ tenants.last_page }}</span>
                        <Button
                            v-if="tenants.next_page_url"
                            :as="Link"
                            :href="tenants.next_page_url"
                            preserve-state
                            preserve-scroll
                            variant="secondary"
                            tone="purple"
                            aria-label="Next page"
                        >
                            Next
                            <ChevronRight class="size-4" aria-hidden="true" />
                        </Button>
                        <Button v-else variant="secondary" tone="purple" disabled aria-label="Next page">
                            Next
                            <ChevronRight class="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                </nav>
            </template>
        </Card>
    </div>
</template>
