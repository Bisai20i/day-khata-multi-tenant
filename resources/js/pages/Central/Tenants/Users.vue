<script setup>
import { h } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Building2, History, LayoutDashboard } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';

const props = defineProps({
    tenant: {
        type: Object,
        required: true,
    },
    users: {
        type: Array,
        default: () => [],
    },
});

const navItems = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard },
    { label: 'Tenants', href: '/tenants', icon: Building2 },
    { label: 'Activity log', href: '/activity-log', icon: History },
];

const columns = [
    { accessorKey: 'name', header: 'Name' },
    { accessorKey: 'email', header: 'Email' },
    {
        accessorKey: 'role',
        header: 'Role',
        numeric: false,
        cell: ({ row }) => (row.original.role ? h(Badge, { variant: 'neutral', pill: true }, () => row.original.role) : '—'),
    },
    {
        accessorKey: 'is_active',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(Badge, { variant: row.original.is_active ? 'success' : 'danger', pill: true }, () =>
                row.original.is_active ? 'Active' : 'Inactive',
            ),
    },
    { accessorKey: 'created_at', header: 'Created' },
];
</script>

<template>
    <AppLayout :title="`${tenant.company_name} — Users`" :nav-items="navItems">
        <Link :href="`/tenants/${tenant.id}`" class="mb-2 inline-flex items-center gap-1 text-sm font-semibold text-primary">
            <ArrowLeft class="size-4" />
            Back to {{ tenant.company_name }}
        </Link>

        <div class="mb-4">
            <h2 class="text-base font-bold text-text-strong">Users</h2>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="users" :page-size="10" empty-message="This tenant has no users yet" />
        </Card>
    </AppLayout>
</template>
