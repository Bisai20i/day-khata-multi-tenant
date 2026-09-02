<script setup>
import { computed, h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Badge from '@/components/ui/Badge.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    users: {
        type: Array,
        default: () => [],
    },
    roles: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');

const navItems = computed(() => navGroups(isAdmin.value));

// Flash status is watched (not just read on mount) because create/edit both
// redirect back to this same route + component, which Inertia re-renders
// in place without an onMounted re-run.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const roleOptions = computed(() => props.roles.map((role) => ({ value: role.id, label: role.name })));

// Select's modelValue only accepts String/Number/null, so is_active (a
// boolean on the form) is bridged through 1/0 here rather than passed
// straight through - avoids a Vue prop-type warning on every keystroke.
const statusOptions = [
    { value: 1, label: 'Active' },
    { value: 0, label: 'Inactive' },
];

const isActiveOption = computed({
    get: () => (form.is_active ? 1 : 0),
    set: (value) => {
        form.is_active = value === 1;
    },
});

const modalOpen = ref(false);
const editing = ref(null);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role_id: null,
    is_active: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.is_active = true;
    modalOpen.value = true;
}

function openEdit(user) {
    editing.value = user;
    form.clearErrors();
    form.name = user.name ?? '';
    form.email = user.email ?? '';
    form.password = '';
    form.password_confirmation = '';
    form.role_id = user.role_id ?? null;
    form.is_active = user.is_active;
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
}

function onModalOpenChange(value) {
    if (value) {
        modalOpen.value = true;
    } else {
        closeModal();
    }
}

function submit() {
    if (editing.value) {
        form.put(`/admin/users/${editing.value.id}`, { onSuccess: closeModal });
    } else {
        form.post('/admin/users', { onSuccess: closeModal });
    }
}

const columns = [
    { accessorKey: 'name', header: 'Name' },
    { accessorKey: 'email', header: 'Email' },
    {
        id: 'role',
        header: 'Role',
        numeric: false,
        cell: ({ row }) => row.original.role?.name ?? '—',
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(
                Badge,
                { variant: row.original.is_active ? 'success' : 'neutral', pill: true },
                { default: () => (row.original.is_active ? 'Active' : 'Inactive') },
            ),
    },
    {
        id: 'actions',
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            h(Tooltip, { label: 'Edit' }, () =>
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-[filter] duration-150 ease-out hover:brightness-95',
                        'aria-label': 'Edit',
                        onClick: () => openEdit(row.original),
                    },
                    [h(Pencil, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' })],
                ),
            ),
    },
];
</script>

<template>
    <AppLayout title="Manage Users" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Employees</h2>
            <Button variant="primary" tone="purple" @click="openCreate">New employee</Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="users" :page-size="10" />
        </Card>

        <Modal :open="modalOpen" :title="editing ? 'Edit employee' : 'New employee'" @update:open="onModalOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input id="name" v-model="form.name" type="text" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="email" class="mb-1 block text-sm font-semibold text-text-base">Email</label>
                    <Input id="email" v-model="form.email" type="email" required />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label for="role_id" class="mb-1 block text-sm font-semibold text-text-base">Role</label>
                    <Select id="role_id" v-model="form.role_id" :options="roleOptions" placeholder="Select role" />
                    <p v-if="form.errors.role_id" class="mt-1 text-sm text-danger">{{ form.errors.role_id }}</p>
                </div>

                <div v-if="editing">
                    <label for="is_active" class="mb-1 block text-sm font-semibold text-text-base">Status</label>
                    <Select id="is_active" v-model="isActiveOption" :options="statusOptions" />
                    <p v-if="form.errors.is_active" class="mt-1 text-sm text-danger">{{ form.errors.is_active }}</p>
                </div>

                <div>
                    <label for="password" class="mb-1 block text-sm font-semibold text-text-base">
                        {{ editing ? 'New password (leave blank to keep current)' : 'Password' }}
                    </label>
                    <Input id="password" v-model="form.password" type="password" :required="!editing" />
                    <p v-if="form.errors.password" class="mt-1 text-sm text-danger">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label for="password_confirmation" class="mb-1 block text-sm font-semibold text-text-base">Confirm password</label>
                    <Input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        :required="!editing"
                    />
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="form.processing" @click="submit">
                    {{ editing ? 'Save changes' : 'Create employee' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
