<script setup>
import { computed, h, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Filter, X } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Select from '@/components/ui/Select.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    logs: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, prev_page_url: null, next_page_url: null }),
    },
    filters: {
        type: Object,
        default: () => ({ tenant_id: null, action: null, platform_admin_id: null }),
    },
    tenantOptions: { type: Array, default: () => [] },
    platformAdminOptions: { type: Array, default: () => [] },
    actionOptions: { type: Array, default: () => [] },
});

useLayoutChrome('Activity Log');

const tenantId = ref(props.filters.tenant_id ?? null);
const action = ref(props.filters.action ?? null);
const platformAdminId = ref(props.filters.platform_admin_id ?? null);
const filtering = ref(false);

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            tenant_id: tenantId.value || undefined,
            action: action.value || undefined,
            platform_admin_id: platformAdminId.value || undefined,
        },
        { preserveState: true, preserveScroll: true, onStart: () => (filtering.value = true), onFinish: () => (filtering.value = false) },
    );
}

function clearFilter() {
    tenantId.value = null;
    action.value = null;
    platformAdminId.value = null;
    router.get(
        window.location.pathname,
        {},
        { preserveState: true, preserveScroll: true, onStart: () => (filtering.value = true), onFinish: () => (filtering.value = false) },
    );
}

/** "tenant.suspended" -> "Tenant suspended" */
function humanizeAction(value) {
    if (!value) {
        return '-';
    }
    const text = String(value).replace(/[._-]+/g, ' ').trim();
    return text.charAt(0).toUpperCase() + text.slice(1);
}

const humanActionOptions = computed(() => props.actionOptions.map((o) => ({ ...o, label: humanizeAction(o.label ?? o.value) })));

const hasFilters = computed(() => !!(tenantId.value || action.value || platformAdminId.value));

const columns = [
    {
        accessorKey: 'action',
        header: 'Action',
        numeric: false,
        cell: ({ row }) => h(Badge, { variant: 'neutral', pill: true }, () => humanizeAction(row.original.action)),
    },
    {
        id: 'tenant',
        header: 'Tenant',
        numeric: false,
        accessorFn: (row) => row.tenant?.company_name ?? '-',
        cell: ({ row }) => row.original.tenant?.company_name ?? '-',
    },
    {
        id: 'platform_admin',
        header: 'Platform Admin',
        numeric: false,
        accessorFn: (row) => row.platform_admin?.name ?? '-',
        cell: ({ row }) =>
            row.original.platform_admin
                ? `${row.original.platform_admin.name} (${row.original.platform_admin.email})`
                : '-',
    },
    { accessorKey: 'created_at', header: 'Date' },
];
</script>

<template>
    <div>
        <PageHeader title="Activity log" description="A read-only record of actions platform admins have taken on tenants and admin accounts. Newest first." />

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[180px]">
                    <label for="filter-tenant" class="mb-1 block text-xs font-semibold text-text-muted">Tenant</label>
                    <Select id="filter-tenant" v-model="tenantId" :options="tenantOptions" placeholder="All tenants" />
                </div>
                <div class="min-w-[180px]">
                    <label for="filter-action" class="mb-1 block text-xs font-semibold text-text-muted">Action</label>
                    <Select id="filter-action" v-model="action" :options="humanActionOptions" placeholder="All actions" />
                </div>
                <div class="min-w-[180px]">
                    <label for="filter-platform-admin" class="mb-1 block text-xs font-semibold text-text-muted">Platform admin</label>
                    <Select id="filter-platform-admin" v-model="platformAdminId" :options="platformAdminOptions" placeholder="All admins" />
                </div>
                <Button variant="primary" tone="purple" :loading="filtering" @click="applyFilter">
                    <Filter class="size-4" />
                    Apply filters
                </Button>
                <Button v-if="hasFilters" variant="secondary" tone="purple" @click="clearFilter">
                    <X class="size-4" />
                    Clear filters
                </Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="logs.data" :page-size="Math.max(logs.data.length, 1)" :empty-message="hasFilters ? 'No activity matches these filters. Try clearing them.' : 'No activity has been recorded yet.'" />

            <div v-if="logs.data.length > 0" class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-text-muted">Showing {{ logs.from }}–{{ logs.to }} of {{ logs.total }}</p>
                <nav class="flex items-center gap-2" aria-label="Pagination">
                    <Link
                        v-if="logs.prev_page_url"
                        :href="logs.prev_page_url"
                        aria-label="Previous page"
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
                    <span class="text-xs text-text-muted">Page {{ logs.current_page }} of {{ logs.last_page }}</span>
                    <Link
                        v-if="logs.next_page_url"
                        :href="logs.next_page_url"
                        aria-label="Next page"
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
                </nav>
            </div>
        </Card>
    </div>
</template>
