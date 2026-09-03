<script setup>
import { h, onMounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, History, LayoutDashboard } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { useToast } from '@/composables/useToast';

defineProps({
    tenants: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();

onMounted(() => {
    if (page.props.flash?.status) {
        toast({ message: page.props.flash.status, variant: 'success' });
    }
});

const navItems = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard },
    { label: 'Tenants', href: '/tenants', icon: Building2 },
    { label: 'Activity log', href: '/activity-log', icon: History },
];

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
            h(Badge, { variant: statusBadgeVariant[row.original.status] ?? 'neutral', pill: true }, () => row.original.status),
    },
    { accessorKey: 'created_at', header: 'Created' },
    {
        id: 'actions',
        header: '',
        numeric: false,
        cell: ({ row }) =>
            h(
                Link,
                {
                    href: `/tenants/${row.original.id}`,
                    class: 'text-sm font-semibold text-primary hover:underline',
                },
                () => 'View',
            ),
    },
];
</script>

<template>
    <AppLayout title="Tenants" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Tenants</h2>
            <Button :as="Link" href="/tenants/create" variant="primary" tone="purple">New tenant</Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="tenants" :page-size="10" />
        </Card>
    </AppLayout>
</template>
