<script setup>
import { computed, h } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, History, LayoutDashboard, Pencil, Settings as SettingsIcon, ShieldCheck, UserPlus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Tooltip from '@/components/ui/Tooltip.vue';

defineProps({
    admins: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();

const isOwner = computed(() => page.props.auth?.platformAdmin?.role === 'owner');

const navItems = computed(() => [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard },
    { label: 'Tenants', href: '/tenants', icon: Building2 },
    { label: 'Activity log', href: '/activity-log', icon: History },
    { label: 'Settings', href: '/settings', icon: SettingsIcon },
    { label: 'Platform admins', href: '/platform-admins', icon: ShieldCheck },
]);

const roleBadgeVariant = { owner: 'success', support: 'neutral' };

const columns = computed(() => {
    const base = [
        { accessorKey: 'name', header: 'Name' },
        { accessorKey: 'email', header: 'Email' },
        {
            accessorKey: 'role',
            header: 'Role',
            numeric: false,
            cell: ({ row }) => h(Badge, { variant: roleBadgeVariant[row.original.role] ?? 'neutral', pill: true }, () => row.original.role),
        },
        {
            id: 'status',
            header: 'Status',
            numeric: false,
            cell: ({ row }) =>
                h(Badge, { variant: row.original.is_active ? 'success' : 'neutral', pill: true }, () =>
                    row.original.is_active ? 'Active' : 'Inactive',
                ),
        },
    ];

    if (!isOwner.value) {
        return base;
    }

    return [
        ...base,
        {
            id: 'actions',
            header: '',
            numeric: false,
            cell: ({ row }) =>
                h(Tooltip, { label: 'Edit platform admin' }, () =>
                    h(
                        Link,
                        {
                            href: `/platform-admins/${row.original.id}/edit`,
                            class: 'flex h-8 w-8 items-center justify-center bg-bg-subtle text-text-muted transition-colors duration-150 ease-out hover:bg-primary-tint hover:text-primary',
                            'aria-label': 'Edit platform admin',
                        },
                        () => h(Pencil, { class: 'size-[13px]' }),
                    ),
                ),
        },
    ];
});
</script>

<template>
    <AppLayout title="Platform Admins" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Platform Admins</h2>
            <Button v-if="isOwner" :as="Link" href="/platform-admins/create" variant="primary" tone="purple">
                <UserPlus class="size-4" />
                New platform admin
            </Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="admins" :page-size="10" />
        </Card>
    </AppLayout>
</template>
