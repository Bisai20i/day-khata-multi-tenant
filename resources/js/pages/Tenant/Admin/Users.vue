<script setup>
import { computed, h, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Crown, Pencil, Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Badge from '@/components/ui/Badge.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

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
const { confirm } = useConfirm();
const { can, isOwner } = usePermissions();
useLayoutChrome('Manage Users');

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

// The server already narrowed `roles` to the ones this user may hand out
// (the escalation guard in UserController); the page just offers them.
const roleOptions = computed(() => props.roles.map((role) => ({ value: role.id, label: role.name })));

const currentUserId = computed(() => page.props.auth?.user?.id ?? null);

// Role and status are fixed for the owner row (ownership moves only through
// "Transfer ownership") and for a non-owner's own row (nobody changes their
// own role or deactivates themselves). The server enforces both; this only
// avoids offering a change that would be refused.
const roleAndStatusLocked = computed(() => {
    if (!editing.value) return false;
    if (editing.value.is_owner) return true;
    return !isOwner.value && editing.value.id === currentUserId.value;
});

const roleLockReason = computed(() => {
    if (!editing.value) return '';
    if (editing.value.is_owner) return "The owner's role and status cannot be changed. Transfer ownership to someone else first.";
    return 'You cannot change your own role or deactivate your own account.';
});

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

async function submit() {
    if (editing.value && editing.value.is_active && !form.is_active) {
        const ok = await confirm({
            title: 'Deactivate employee?',
            message: `${editing.value.name} will no longer be able to sign in. Their past records are kept. You can reactivate them later.`,
            tone: 'danger',
            confirmLabel: 'Deactivate employee',
        });
        if (!ok) return;
    }
    if (editing.value && form.password) {
        const ok = await confirm({
            title: 'Reset password?',
            message: `${editing.value.name}'s current password will stop working. Share the new password with them.`,
            tone: 'purple',
            confirmLabel: 'Save new password',
        });
        if (!ok) return;
    }
    if (editing.value) {
        form.put(`/admin/users/${editing.value.id}`, { onSuccess: closeModal });
    } else {
        form.post('/admin/users', { onSuccess: closeModal });
    }
}

// Ownership transfer. ownership.transfer is owner-only, so only the owner
// ever sees this; the target must be someone else and active (the server
// re-checks all of it, under lock, in OwnershipTransfer).
const transferTarget = ref(null);
const transferForm = useForm({ current_password: '' });

function canTransferTo(user) {
    return can('ownership.transfer') && !user.is_owner && user.is_active && user.id !== currentUserId.value;
}

function openTransfer(user) {
    transferTarget.value = user;
    transferForm.reset();
    transferForm.clearErrors();
}

function closeTransfer() {
    transferTarget.value = null;
    transferForm.reset();
    transferForm.clearErrors();
}

function onTransferOpenChange(value) {
    if (!value) closeTransfer();
}

function submitTransfer() {
    if (!transferTarget.value) return;
    transferForm.post(`/admin/users/${transferTarget.value.id}/transfer-ownership`, {
        preserveScroll: true,
        onSuccess: closeTransfer,
        onFinish: () => transferForm.reset('current_password'),
    });
}

const myRoleName = computed(() => props.users.find((user) => user.id === currentUserId.value)?.role?.name ?? null);

const columns = [
    { accessorKey: 'name', header: 'Name' },
    { accessorKey: 'email', header: 'Email' },
    {
        id: 'role',
        header: 'Role',
        numeric: false,
        cell: ({ row }) => {
            const badges = [];
            if (row.original.is_owner) {
                badges.push(h(Badge, { variant: 'warning', pill: true }, { default: () => 'Owner' }));
            }
            if (row.original.role?.name) {
                badges.push(h(Badge, { variant: 'info', pill: true }, { default: () => row.original.role.name }));
            }
            return badges.length ? h('div', { class: 'flex flex-wrap items-center gap-1' }, badges) : '-';
        },
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
        cell: ({ row }) => {
            const actions = [];
            if (row.original.editable) {
                actions.push(
                    h(Tooltip, { label: `Edit ${row.original.name}` }, () =>
                        h(
                            'button',
                            {
                                type: 'button',
                                class: 'flex h-[26px] w-[26px] items-center justify-center bg-primary-tint text-primary transition-[filter] duration-150 ease-out hover:brightness-95',
                                'aria-label': `Edit ${row.original.name}`,
                                onClick: () => openEdit(row.original),
                            },
                            [h(Pencil, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' })],
                        ),
                    ),
                );
            }
            if (canTransferTo(row.original)) {
                actions.push(
                    h(Tooltip, { label: `Transfer ownership to ${row.original.name}` }, () =>
                        h(
                            'button',
                            {
                                type: 'button',
                                class: 'flex h-[26px] w-[26px] items-center justify-center bg-danger-bg text-danger transition-colors duration-150 ease-out hover:bg-danger hover:text-white',
                                'aria-label': `Transfer ownership to ${row.original.name}`,
                                onClick: () => openTransfer(row.original),
                            },
                            [h(Crown, { class: 'h-[13px] w-[13px]', 'aria-hidden': 'true' })],
                        ),
                    ),
                );
            }
            return actions.length ? h('div', { class: 'flex items-center gap-2' }, actions) : '-';
        },
    },
];
</script>

<template>
    <div>
        <PageHeader title="Employees" description="People who can sign in to your company. Each employee's role controls what they can see and do.">
            <Button variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New employee
            </Button>
        </PageHeader>

        <Card variant="panel">
            <DataTable :columns="columns" :data="users" :page-size="10" />
        </Card>

        <Modal :open="modalOpen" :title="editing ? 'Edit employee' : 'New employee'" @update:open="onModalOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <Label for="name" class="mb-1">Name <span class="text-danger">*</span></Label>
                    <Input id="name" v-model="form.name" type="text" placeholder="e.g. Jane Doe" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <Label for="email" class="mb-1">Email <span class="text-danger">*</span></Label>
                    <Input id="email" v-model="form.email" type="email" placeholder="you@example.com" required />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
                </div>

                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <Label for="role_id">Role <span class="text-danger">*</span></Label>
                        <InfoTip
                            v-if="!roleAndStatusLocked"
                            text="The role decides which screens and actions this employee can use. You can only give roles whose permissions you hold yourself."
                        />
                    </div>
                    <Select id="role_id" v-model="form.role_id" :options="roleOptions" placeholder="Select role" :disabled="roleAndStatusLocked" />
                    <p v-if="roleAndStatusLocked" class="mt-1 text-xs text-text-faint">{{ roleLockReason }}</p>
                    <p v-if="form.errors.role_id" class="mt-1 text-sm text-danger">{{ form.errors.role_id }}</p>
                </div>

                <div v-if="editing">
                    <div class="mb-1 flex items-center gap-1">
                        <Label for="is_active">Status <span class="text-danger">*</span></Label>
                        <InfoTip text="Inactive employees cannot sign in." />
                    </div>
                    <Select id="is_active" v-model="isActiveOption" :options="statusOptions" :disabled="roleAndStatusLocked" />
                    <p v-if="form.errors.is_active" class="mt-1 text-sm text-danger">{{ form.errors.is_active }}</p>
                </div>

                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <Label for="password">
                            {{ editing ? 'New password (leave blank to keep current)' : 'Password' }}
                            <span v-if="!editing" class="text-danger">*</span>
                        </Label>
                        <InfoTip text="At least 8 characters." />
                    </div>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        :placeholder="editing ? 'Leave blank to keep current' : 'Enter a password'"
                        :required="!editing"
                    />
                    <p v-if="form.errors.password" class="mt-1 text-sm text-danger">{{ form.errors.password }}</p>
                </div>

                <div>
                    <Label for="password_confirmation" class="mb-1">
                        Confirm password
                        <span v-if="!editing" class="text-danger">*</span>
                    </Label>
                    <Input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        :placeholder="editing ? 'Confirm new password' : 'Re-enter the password'"
                        :required="!editing"
                    />
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="form.processing" @click="submit">
                    {{ form.processing ? 'Saving...' : editing ? 'Save employee' : 'Create employee' }}
                </Button>
            </template>
        </Modal>

        <Modal :open="transferTarget !== null" title="Transfer ownership" size="compact" @update:open="onTransferOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submitTransfer">
                <div class="bg-danger-bg p-3 text-sm text-danger">
                    <p class="font-semibold">{{ transferTarget?.name }} will become the owner of this company.</p>
                    <p class="mt-1">You stop being the owner immediately and only they can give ownership back.</p>
                    <p v-if="myRoleName" class="mt-1">From then on you have only the permissions of your role ({{ myRoleName }}).</p>
                    <p v-else class="mt-1">You have no role, so you will lose access to almost everything until someone gives you one.</p>
                </div>

                <div>
                    <Label for="current_password" class="mb-1">Your password <span class="text-danger">*</span></Label>
                    <Input id="current_password" v-model="transferForm.current_password" type="password" autocomplete="current-password" placeholder="Enter your password to confirm" required />
                    <p v-if="transferForm.errors.current_password" class="mt-1 text-sm text-danger">{{ transferForm.errors.current_password }}</p>
                    <p v-if="transferForm.errors.transfer" class="mt-1 text-sm text-danger">{{ transferForm.errors.transfer }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeTransfer">Cancel</Button>
                <Button variant="primary" tone="danger" type="button" :disabled="transferForm.processing || !transferForm.current_password" @click="submitTransfer">
                    {{ transferForm.processing ? 'Transferring...' : 'Transfer ownership' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
