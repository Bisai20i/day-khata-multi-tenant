<script setup>
import { h, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Building2, History, LayoutDashboard } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Select from '@/components/ui/Select.vue';
import DataTable from '@/components/ui/DataTable.vue';

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

const navItems = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard },
    { label: 'Tenants', href: '/tenants', icon: Building2 },
    { label: 'Activity log', href: '/activity-log', icon: History },
];

const tenantId = ref(props.filters.tenant_id ?? null);
const action = ref(props.filters.action ?? null);
const platformAdminId = ref(props.filters.platform_admin_id ?? null);

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            tenant_id: tenantId.value || undefined,
            action: action.value || undefined,
            platform_admin_id: platformAdminId.value || undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function clearFilter() {
    tenantId.value = null;
    action.value = null;
    platformAdminId.value = null;
    router.get(window.location.pathname, {}, { preserveState: true, preserveScroll: true });
}

const columns = [
    {
        accessorKey: 'action',
        header: 'Action',
        numeric: false,
        cell: ({ row }) => h(Badge, { variant: 'neutral', pill: true }, () => row.original.action),
    },
    {
        id: 'tenant',
        header: 'Tenant',
        numeric: false,
        accessorFn: (row) => row.tenant?.company_name ?? '—',
        cell: ({ row }) => row.original.tenant?.company_name ?? '—',
    },
    {
        id: 'platform_admin',
        header: 'Platform Admin',
        numeric: false,
        accessorFn: (row) => row.platform_admin?.name ?? '—',
        cell: ({ row }) =>
            row.original.platform_admin
                ? `${row.original.platform_admin.name} (${row.original.platform_admin.email})`
                : '—',
    },
    { accessorKey: 'created_at', header: 'Date' },
];
</script>

<template>
    <AppLayout title="Activity Log" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Activity Log</h2>
        </div>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[180px]">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Tenant</label>
                    <Select v-model="tenantId" :options="tenantOptions" placeholder="All tenants" />
                </div>
                <div class="min-w-[180px]">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Action</label>
                    <Select v-model="action" :options="actionOptions" placeholder="All actions" />
                </div>
                <div class="min-w-[180px]">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Platform admin</label>
                    <Select v-model="platformAdminId" :options="platformAdminOptions" placeholder="All admins" />
                </div>
                <Button variant="primary" tone="purple" @click="applyFilter">Apply</Button>
                <Button variant="secondary" tone="purple" @click="clearFilter">Clear</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="logs.data" :page-size="Math.max(logs.data.length, 1)" empty-message="No activity recorded." />

            <div v-if="logs.data.length > 0" class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-text-muted">Showing {{ logs.from }}–{{ logs.to }} of {{ logs.total }}</p>
                <div class="flex items-center gap-2">
                    <Link
                        v-if="logs.prev_page_url"
                        :href="logs.prev_page_url"
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
    </AppLayout>
</template>
