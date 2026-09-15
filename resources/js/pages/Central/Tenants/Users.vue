<script setup>
import { h } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';

defineOptions({ layout: AppLayout });

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

useLayoutChrome(() => `${props.tenant.company_name} — Users`);

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
    <div>
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
    </div>
</template>
