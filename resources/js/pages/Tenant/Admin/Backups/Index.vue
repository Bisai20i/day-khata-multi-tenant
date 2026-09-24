<script setup>
import { h, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Download, Trash2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';

defineOptions({ layout: AppLayout });

defineProps({
    backups: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
useLayoutChrome('Backups');

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

const creating = ref(false);

function createBackup() {
    router.post('/backups', {}, {
        preserveScroll: true,
        onStart: () => {
            creating.value = true;
        },
        onFinish: () => {
            creating.value = false;
        },
    });
}

async function destroy(backup) {
    const confirmed = await confirm({
        title: 'Delete this backup?',
        message: `"${backup.filename}" will be permanently deleted and can no longer be downloaded. This cannot be undone.`,
        tone: 'danger',
        confirmLabel: 'Delete backup',
    });
    if (!confirmed) return;
    router.delete(`/backups/${backup.id}`, { preserveScroll: true });
}

function formatSize(bytes) {
    if (!bytes && bytes !== 0) return '-';

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
        cell: ({ row }) => row.original.creator?.name ?? '-',
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
                    ? h(Tooltip, { label: `Download ${row.original.filename}` }, () =>
                          h(
                              'a',
                              {
                                  href: `/backups/${row.original.id}/download`,
                                  class: 'flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-[filter] duration-150 ease-out hover:brightness-95',
                                  'aria-label': `Download ${row.original.filename}`,
                              },
                              [h(Download, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' })],
                          ),
                      )
                    : null,
                h(Tooltip, { label: `Delete ${row.original.filename}` }, () =>
                    h(
                        'button',
                        {
                            type: 'button',
                            class: 'flex h-[26px] w-[26px] items-center justify-center bg-bg-subtle text-text-faint transition-colors duration-150 ease-out hover:bg-danger-bg hover:text-danger',
                            'aria-label': `Delete ${row.original.filename}`,
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
    <div>
        <PageHeader title="Backups" description="Download or restore copies of your company data.">
            <Button variant="primary" tone="purple" :disabled="creating" @click="createBackup">
                {{ creating ? 'Creating backup...' : 'Create backup now' }}
            </Button>
        </PageHeader>

        <p class="mb-4 text-sm text-text-muted">
            A backup is a snapshot of all your company data (sales, purchases, items, parties and accounts) at the moment it is created. Download a backup and keep it somewhere safe. Creating one can take a minute for large companies.
        </p>

        <Card variant="panel">
            <DataTable :columns="columns" :data="backups" :page-size="10" />
        </Card>
    </div>
</template>
