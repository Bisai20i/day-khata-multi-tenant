<script setup>
import { computed, h, onMounted, ref } from 'vue';
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
    groups: {
        type: Array,
        default: () => [],
    },
    subgroups: {
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

const groupOptions = computed(() => props.groups.map((group) => ({ value: group.id, label: group.name })));

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    account_group_id: '',
    name: '',
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

function openEdit(subgroup) {
    editing.value = subgroup;
    form.clearErrors();
    form.account_group_id = subgroup.account_group_id;
    form.name = subgroup.name;
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    form.reset();
    form.clearErrors();
    editing.value = null;
}

function onModalOpenChange(value) {
    if (value) {
        showModal.value = true;
    } else {
        closeModal();
    }
}

function submit() {
    if (editing.value) {
        form.put(`/account-subgroups/${editing.value.id}`, {
            onSuccess: () => {
                toast({ message: 'Account subgroup updated', variant: 'success' });
                closeModal();
            },
        });
    } else {
        form.post('/account-subgroups', {
            onSuccess: () => {
                toast({ message: 'Account subgroup created', variant: 'success' });
                closeModal();
            },
        });
    }
}

function destroy(subgroup) {
    if (!confirm('Delete this account subgroup?')) return;
    router.delete(`/account-subgroups/${subgroup.id}`, {
        onSuccess: () => toast({ message: 'Account subgroup deleted', variant: 'success' }),
    });
}

const columns = [
    { accessorKey: 'name', header: 'Subgroup Name' },
    {
        id: 'group',
        header: 'Account Group',
        numeric: false,
        cell: ({ row }) => row.original.account_group?.name ?? '—',
    },
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
    <AppLayout title="Account Subgroups" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Account Subgroups</h2>
            <Button variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New subgroup
            </Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="subgroups" :page-size="10" empty-message="No account subgroups found" />
        </Card>

        <Modal :open="showModal" :title="editing ? 'Edit Account Subgroup' : 'New Account Subgroup'" @update:open="onModalOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="account_group_id" class="mb-1 block text-sm font-semibold text-text-base">Account group</label>
                    <Select
                        id="account_group_id"
                        v-model="form.account_group_id"
                        :options="groupOptions"
                        placeholder="Select account group"
                    />
                    <p v-if="form.errors.account_group_id" class="mt-1 text-sm text-danger">{{ form.errors.account_group_id }}</p>
                </div>

                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input id="name" v-model="form.name" type="text" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" :disabled="form.processing" @click="submit">
                    {{ editing ? 'Save changes' : 'Create subgroup' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
