<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';
import Create from './Create.vue';

const props = defineProps({
    returns: { type: Array, default: () => [] },
    sales: { type: Array, default: () => [] },
});

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showCreateForm = ref(false);

const columns = [
    { accessorKey: 'date', header: 'Date' },
    {
        id: 'sale',
        header: 'Original sale #',
        numeric: false,
        cell: ({ row }) => row.original.sale_id,
    },
    {
        id: 'customer',
        header: 'Customer',
        numeric: false,
        cell: ({ row }) => row.original.sale?.customer?.name ?? '—',
    },
    {
        id: 'reason',
        header: 'Reason',
        numeric: false,
        cell: ({ row }) => row.original.reason ?? '—',
    },
    {
        id: 'total',
        header: 'Total',
        numeric: true,
        cell: ({ row }) => Number(row.original.total).toFixed(2),
    },
];
</script>

<template>
    <AppLayout title="Sales Returns" :nav-items="navItems">
        <template v-if="showCreateForm">
            <Create :sales="sales" @cancel="showCreateForm = false" @posted="showCreateForm = false" />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Sales Returns</h2>
                <Button variant="primary" tone="purple" @click="showCreateForm = true">New return</Button>
            </div>

            <Card variant="panel">
                <DataTable :columns="columns" :data="returns" :page-size="10" empty-message="No sales returns yet" />
            </Card>
        </template>
    </AppLayout>
</template>
