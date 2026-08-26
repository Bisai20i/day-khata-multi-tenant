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

defineProps({
    returns: { type: Array, default: () => [] },
    purchases: { type: Array, default: () => [] },
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

function itemSummary(purchaseReturn) {
    return purchaseReturn.lines
        .map((line) => line.purchase_line?.item?.name)
        .filter(Boolean)
        .join(', ');
}

const columns = [
    { accessorKey: 'date', header: 'Date' },
    {
        id: 'purchase',
        header: 'Purchase',
        numeric: false,
        cell: ({ row }) => `#${row.original.purchase?.id} — ${row.original.purchase?.supplier?.name ?? '—'}`,
    },
    {
        id: 'items',
        header: 'Items',
        numeric: false,
        cell: ({ row }) => itemSummary(row.original) || '—',
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
    <AppLayout title="Purchase Returns" :nav-items="navItems">
        <template v-if="showCreateForm">
            <Create :purchases="purchases" @cancel="showCreateForm = false" @posted="showCreateForm = false" />
        </template>

        <template v-else>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-base font-bold text-text-strong">Purchase Returns</h2>
                <Button variant="primary" tone="purple" @click="showCreateForm = true">New return</Button>
            </div>

            <Card variant="panel">
                <DataTable :columns="columns" :data="returns" :page-size="10" empty-message="No purchase returns yet" />
            </Card>
        </template>
    </AppLayout>
</template>
