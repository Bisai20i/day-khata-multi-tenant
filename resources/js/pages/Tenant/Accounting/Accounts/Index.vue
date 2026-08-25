<script setup>
import { computed, h, onMounted, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    Layers,
    ListTree,
    BookOpen,
    Users,
    Truck,
    Tags,
    Tag,
    Package,
    UserCog,
    Plus,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps({
    accountGroups: {
        type: Array,
        default: () => [],
    },
    accountSubgroups: {
        type: Array,
        default: () => [],
    },
    accounts: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');

const navItems = computed(() => {
    const items = [
        { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
        { label: 'Account Groups', href: '/account-groups', icon: Layers },
        { label: 'Account Subgroups', href: '/account-subgroups', icon: ListTree },
        { label: 'Accounts', href: '/accounts', icon: BookOpen },
        { label: 'Customers', href: '/customers', icon: Users },
        { label: 'Suppliers', href: '/suppliers', icon: Truck },
        { label: 'Item Categories', href: '/item-categories', icon: Tags },
        { label: 'Item Subcategories', href: '/item-subcategories', icon: Tag },
        { label: 'Items', href: '/items', icon: Package },
    ];
    if (isAdmin.value) {
        items.push({ label: 'Manage users', href: '/admin/users', icon: UserCog });
    }
    return items;
});

const { toast } = useToast();

onMounted(() => {
    if (page.props.flash?.status) {
        toast({ message: page.props.flash.status, variant: 'success' });
    }
});

const groupOptions = computed(() => props.accountGroups.map((group) => ({ value: group.id, label: group.name })));
const subgroupOptions = computed(() => props.accountSubgroups.map((subgroup) => ({ value: subgroup.id, label: subgroup.name })));

const parentTypeOptions = [
    { value: 'group', label: 'Group' },
    { value: 'subgroup', label: 'Subgroup' },
];

const showModal = ref(false);
const editing = ref(null);
const parentType = ref('group');

const form = useForm({
    account_group_id: null,
    account_subgroup_id: null,
    code: '',
    name: '',
    phone: '',
    address: '',
});

watch(parentType, (value) => {
    if (value === 'group') {
        form.account_subgroup_id = null;
    } else {
        form.account_group_id = null;
    }
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    parentType.value = 'group';
    showModal.value = true;
}

function openEdit(account) {
    editing.value = account;
    form.clearErrors();
    form.code = account.code ?? '';
    form.name = account.name;
    form.phone = account.phone ?? '';
    form.address = account.address ?? '';
    form.account_group_id = account.account_group_id;
    form.account_subgroup_id = account.account_subgroup_id;
    parentType.value = account.account_subgroup_id ? 'subgroup' : 'group';
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    form.reset();
    form.clearErrors();
    editing.value = null;
    parentType.value = 'group';
}

function onModalOpenChange(value) {
    if (value) {
        showModal.value = true;
    } else {
        closeModal();
    }
}

function submit() {
    if (parentType.value === 'group') {
        form.account_subgroup_id = null;
    } else {
        form.account_group_id = null;
    }

    if (editing.value) {
        form.put(`/accounts/${editing.value.id}`, {
            onSuccess: () => {
                toast({ message: 'Account updated', variant: 'success' });
                closeModal();
            },
        });
    } else {
        form.post('/accounts', {
            onSuccess: () => {
                toast({ message: 'Account created', variant: 'success' });
                closeModal();
            },
        });
    }
}

function destroy(account) {
    if (!confirm('Delete this account?')) return;
    router.delete(`/accounts/${account.id}`, {
        onSuccess: () => toast({ message: 'Account deleted', variant: 'success' }),
    });
}

const columns = [
    { accessorKey: 'code', header: 'Code' },
    { accessorKey: 'name', header: 'Name' },
    {
        id: 'parent',
        header: 'Parent',
        numeric: false,
        cell: ({ row }) => row.original.group?.name ?? row.original.subgroup?.name ?? '—',
    },
    { accessorKey: 'phone', header: 'Phone' },
    { accessorKey: 'address', header: 'Address' },
    {
        id: 'actions',
        header: '',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-3' }, [
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'text-sm font-semibold text-primary hover:underline',
                        onClick: () => openEdit(row.original),
                    },
                    'Edit',
                ),
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'text-sm font-semibold text-danger hover:underline',
                        onClick: () => destroy(row.original),
                    },
                    'Delete',
                ),
            ]),
    },
];
</script>

<template>
    <AppLayout title="Accounts" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Accounts</h2>
            <Button variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New account
            </Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="accounts" :page-size="10" empty-message="No accounts found" />
        </Card>

        <Modal :open="showModal" :title="editing ? 'Edit Account' : 'New Account'" @update:open="onModalOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="parent_type" class="mb-1 block text-sm font-semibold text-text-base">File under</label>
                    <Select id="parent_type" v-model="parentType" :options="parentTypeOptions" />
                </div>

                <div v-if="parentType === 'group'">
                    <label for="account_group_id" class="mb-1 block text-sm font-semibold text-text-base">Account group</label>
                    <Select
                        id="account_group_id"
                        v-model="form.account_group_id"
                        :options="groupOptions"
                        placeholder="Select account group"
                    />
                    <p v-if="form.errors.account_group_id" class="mt-1 text-sm text-danger">{{ form.errors.account_group_id }}</p>
                </div>

                <div v-else>
                    <label for="account_subgroup_id" class="mb-1 block text-sm font-semibold text-text-base">Account subgroup</label>
                    <Select
                        id="account_subgroup_id"
                        v-model="form.account_subgroup_id"
                        :options="subgroupOptions"
                        placeholder="Select account subgroup"
                    />
                    <p v-if="form.errors.account_subgroup_id" class="mt-1 text-sm text-danger">{{ form.errors.account_subgroup_id }}</p>
                </div>

                <div>
                    <label for="code" class="mb-1 block text-sm font-semibold text-text-base">Code</label>
                    <Input id="code" v-model="form.code" type="text" />
                    <p v-if="form.errors.code" class="mt-1 text-sm text-danger">{{ form.errors.code }}</p>
                </div>

                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input id="name" v-model="form.name" type="text" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="phone" class="mb-1 block text-sm font-semibold text-text-base">Phone</label>
                    <Input id="phone" v-model="form.phone" type="text" />
                    <p v-if="form.errors.phone" class="mt-1 text-sm text-danger">{{ form.errors.phone }}</p>
                </div>

                <div>
                    <label for="address" class="mb-1 block text-sm font-semibold text-text-base">Address</label>
                    <Input id="address" v-model="form.address" type="text" />
                    <p v-if="form.errors.address" class="mt-1 text-sm text-danger">{{ form.errors.address }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" :disabled="form.processing" @click="submit">
                    {{ editing ? 'Save changes' : 'Create account' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
