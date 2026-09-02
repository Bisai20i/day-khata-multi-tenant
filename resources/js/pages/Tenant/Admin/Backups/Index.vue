<script setup>
import { computed, h, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Download, Trash2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';

defineProps({
    backups: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');
const navItems = computed(() => navGroups(isAdmin.value));

// Create/delete both redirect back to this same route/component, which
// Inertia re-renders in place rather than remounting it - watch the flash
// prop directly (same pattern as every other module in this app) so a
// second action's status still triggers a toast.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

watch(
    () => page.props.errors?.backup,
    (message) => {
        if (message) toast({ message, variant: 'danger' });
    },
);

function createBackup() {
    router.post('/backups', {}, { preserveScroll: true });
}

function destroy(backup) {
    if (!confirm(`Delete backup "${backup.filename}"? This cannot be undone.`)) return;
    router.delete(`/backups/${backup.id}`, { preserveScroll: true });
}

function formatSize(bytes) {
    if (!bytes && bytes !== 0) return '—';

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = Number(bytes);
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    return `${value.toFixed(unitIndex === 0 ? 0 : 2)} ${units[unitIndex]}`;
}

const columns = [
    { accessorKey: 'filename', header: 'Filename' },
    {
        id: 'size',
        header: 'Size',
        numeric: false,
        cell: ({ row }) => formatSize(row.original.size_bytes),
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(Badge, { variant: row.original.status === 'completed' ? 'success' : 'danger', pill: true }, () =>
                row.original.status === 'completed' ? 'Completed' : 'Failed',
            ),
    },
    {
        id: 'created_by',
        header: 'Created by',
        numeric: false,
        cell: ({ row }) => row.original.creator?.name ?? '—',
    },
    {
        id: 'created_at',
        header: 'Created',
        numeric: false,
        cell: ({ row }) => new Date(row.original.created_at).toLocaleString(),
    },
    {
        id: 'actions',
        header: '',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-2' }, [
                row.original.status === 'completed'
                    ? h(Tooltip, { label: 'Download' }, () =>
                          h(
                              'a',
                              {
                                  href: `/backups/${row.original.id}/download`,
                                  class: 'flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-[filter] duration-150 ease-out hover:brightness-95',
                                  'aria-label': 'Download',
                              },
                              [h(Download, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' })],
                          ),
                      )
                    : null,
                h(Tooltip, { label: 'Delete' }, () =>
                    h(
                        'button',
                        {
                            type: 'button',
                            class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 ease-out hover:bg-danger-bg hover:text-danger',
                            'aria-label': 'Delete',
                            onClick: () => destroy(row.original),
                        },
                        [h(Trash2, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' })],
                    ),
                ),
            ]),
    },
];
</script>

<template>
    <AppLayout title="Backups" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Backups</h2>
            <Button variant="primary" tone="purple" @click="createBackup">Create backup now</Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="backups" :page-size="10" />
        </Card>
    </AppLayout>
</template>
