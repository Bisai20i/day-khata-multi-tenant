<script setup>
import { h } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

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

useLayoutChrome(() => `${props.tenant.company_name} - Users`);

const columns = [
    { accessorKey: 'name', header: 'Name' },
    { accessorKey: 'email', header: 'Email' },
    {
        accessorKey: 'role',
        header: 'Role',
        numeric: false,
        cell: ({ row }) => (row.original.role ? h(Badge, { variant: 'neutral', pill: true }, () => row.original.role) : 'No role'),
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
        <PageHeader
            :title="`Users of ${tenant.company_name}`"
            description="People who can sign in to this tenant, with their role and whether their account is active."
            :back-href="`/tenants/${tenant.id}`"
            :back-label="`Back to ${tenant.company_name}`"
        />

        <Card variant="panel">
            <DataTable
                :columns="columns"
                :data="users"
                :page-size="10"
                empty-message="No users yet. Users appear here once the tenant's first admin is created (during provisioning) or when the tenant adds staff."
            />
        </Card>
    </div>
</template>
