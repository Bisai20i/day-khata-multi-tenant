<script setup>
import { computed, h } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Pencil, UserPlus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

defineOptions({ layout: AppLayout });

defineProps({
    admins: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();

const isOwner = computed(() => page.props.auth?.platformAdmin?.role === 'owner');
useLayoutChrome('Platform Admins');

const roleBadgeVariant = { owner: 'success', support: 'neutral' };

function roleLabel(role) {
    return role ? role.charAt(0).toUpperCase() + role.slice(1) : '';
}

const columns = computed(() => {
    const base = [
        { accessorKey: 'name', header: 'Name' },
        { accessorKey: 'email', header: 'Email' },
        {
            accessorKey: 'role',
            header: 'Role',
            numeric: false,
            cell: ({ row }) => h(Badge, { variant: roleBadgeVariant[row.original.role] ?? 'neutral', pill: true }, () => roleLabel(row.original.role)),
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
            header: 'Actions',
            numeric: false,
            cell: ({ row }) =>
                h(
                    Link,
                    {
                        href: `/platform-admins/${row.original.id}/edit`,
                        class: 'inline-flex items-center gap-1 bg-bg-subtle px-2.5 py-1.5 text-xs font-semibold text-text-muted transition-colors duration-150 ease-out hover:bg-primary-tint hover:text-primary',
                        'aria-label': `Edit ${row.original.name}`,
                    },
                    () => [h(Pencil, { class: 'size-[13px]', 'aria-hidden': 'true' }), 'Edit'],
                ),
        },
    ];
});
</script>

<template>
    <div>
        <PageHeader
            title="Platform admins"
            description="People who can sign in to this platform panel. Admins are never deleted - set an admin to Inactive to block their access."
        >
            <Button v-if="isOwner" :as="Link" href="/platform-admins/create" variant="primary" tone="purple">
                <UserPlus class="size-4" />
                Add platform admin
            </Button>
        </PageHeader>

        <Card variant="panel">
            <DataTable :columns="columns" :data="admins" :page-size="10" empty-message="No platform admins yet." />
        </Card>
    </div>
</template>
