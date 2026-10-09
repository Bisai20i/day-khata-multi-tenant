<script setup>
import { h, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Copy, Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Badge from '@/components/ui/Badge.vue';
import RowActions from '@/components/ui/RowActions.vue';
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

defineOptions({ layout: AppLayout });

/**
 * Owner-only list of roles. `permissions_count` counts only the keys the
 * editor can show (entitled modules), out of `activeKeyCount`.
 */
const props = defineProps({
    roles: { type: Array, default: () => [] },
    activeKeyCount: { type: Number, default: 0 },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
useLayoutChrome('Roles and permissions');

watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

// Delete refusals (built-in role, role still assigned) come back as a
// validation error on `role` after a redirect to this page.
watch(
    () => page.props.errors?.role,
    (message) => {
        if (message) toast({ message, variant: 'danger' });
    },
    { immediate: true },
);

function openCreate() {
    router.visit('/admin/roles/create');
}

function openEdit(role) {
    router.visit(`/admin/roles/${role.id}/edit`);
}

async function duplicateRole(role) {
    const ok = await confirm({
        title: 'Duplicate role?',
        message: `A new role "${role.name} (copy)" will be created with the same permissions. You can rename it next.`,
        tone: 'purple',
        confirmLabel: 'Duplicate',
    });
    if (!ok) return;
    router.post(`/admin/roles/${role.id}/duplicate`, {}, { preserveScroll: true });
}

async function deleteRole(role) {
    const ok = await confirm({
        title: 'Delete role?',
        message: `"${role.name}" will be deleted. This cannot be undone.`,
        tone: 'danger',
        confirmLabel: 'Delete role',
    });
    if (!ok) return;
    router.delete(`/admin/roles/${role.id}`, { preserveScroll: true });
}

function deleteLabel(role) {
    return `Delete ${role.name}`;
}

const columns = [
    {
        id: 'name',
        header: 'Role',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-2' }, [
                h('span', { class: 'font-semibold' }, row.original.name),
                row.original.is_system ? h(Badge, { variant: 'neutral', pill: true }, { default: () => 'Built-in' }) : null,
            ]),
    },
    {
        id: 'users',
        header: 'Users',
        numeric: true,
        cell: ({ row }) => String(row.original.users_count),
    },
    {
        id: 'permissions',
        header: 'Permissions',
        numeric: false,
        cell: ({ row }) => `${row.original.permissions_count} of ${props.activeKeyCount}`,
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) => {
            const role = row.original;
            // Delete is hidden where the server would refuse it anyway; the
            // server re-checks both conditions.
            const canDelete = !role.is_system && role.users_count === 0;

            return h(
                RowActions,
                {
                    canDelete,
                    editLabel: `Edit ${role.name}`,
                    deleteLabel: deleteLabel(role),
                    moreLabel: `More actions for ${role.name}`,
                    onEdit: () => openEdit(role),
                    onDelete: () => deleteRole(role),
                },
                {
                    more: () => [
                        h(DropdownMenuItem, { key: 'duplicate', onSelect: () => duplicateRole(role) }, () => [
                            h(Copy, { class: 'size-3.5', 'aria-hidden': 'true' }),
                            'Duplicate',
                        ]),
                    ],
                },
            );
        },
    },
];
</script>

<template>
    <div>
        <PageHeader
            title="Roles and permissions"
            description="A role is a set of permissions. Give each employee one role on the Employees page. Only the owner can change roles."
        >
            <Button variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New role
            </Button>
        </PageHeader>

        <Card variant="panel">
            <DataTable :columns="columns" :data="roles" :page-size="20" />
        </Card>
    </div>
</template>
