<script setup>
import { computed, h, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { LayoutDashboard, Layers, ListTree, BookOpen, Users, Truck, Tags, Tag, Package, UserCog } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { useToast } from '@/composables/useToast';

defineProps({
    customers: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();

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

// Flash status is watched (not just read on mount) because create/edit/delete
// all redirect back to this same route + component, which Inertia re-renders
// in place without an onMounted re-run.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const modalOpen = ref(false);
const editing = ref(null);

const form = useForm({
    name: '',
    address: '',
    mobile_no: '',
    email: '',
    tpin: '',
    citizenship: '',
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function openEdit(customer) {
    editing.value = customer;
    form.clearErrors();
    form.name = customer.name ?? '';
    form.address = customer.address ?? '';
    form.mobile_no = customer.mobile_no ?? '';
    form.email = customer.email ?? '';
    form.tpin = customer.tpin ?? '';
    form.citizenship = customer.citizenship ?? '';
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
        form.put(`/customers/${editing.value.id}`, { onSuccess: closeModal });
    } else {
        form.post('/customers', { onSuccess: closeModal });
    }
}

function destroyCustomer(customer) {
    if (!confirm(`Delete ${customer.name}?`)) return;
    router.delete(`/customers/${customer.id}`);
}

const columns = [
    { accessorKey: 'name', header: 'Name' },
    {
        accessorKey: 'mobile_no',
        header: 'Mobile No',
        numeric: false,
        cell: ({ row }) => row.original.mobile_no ?? '—',
    },
    {
        accessorKey: 'email',
        header: 'Email',
        numeric: false,
        cell: ({ row }) => row.original.email ?? '—',
    },
    {
        accessorKey: 'tpin',
        header: 'TPIN',
        numeric: false,
        cell: ({ row }) => row.original.tpin ?? '—',
    },
    {
        id: 'ledger_code',
        header: 'Ledger Code',
        numeric: false,
        cell: ({ row }) => row.original.account?.code ?? '—',
    },
    {
        id: 'actions',
        header: 'Actions',
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
                        onClick: () => destroyCustomer(row.original),
                    },
                    'Delete',
                ),
            ]),
    },
];
</script>

<template>
    <AppLayout title="Customers" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Customers</h2>
            <Button variant="primary" tone="purple" @click="openCreate">New customer</Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="customers" :page-size="10" />
        </Card>

        <Modal :open="modalOpen" :title="editing ? 'Edit customer' : 'New customer'" @update:open="onModalOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input id="name" v-model="form.name" type="text" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="address" class="mb-1 block text-sm font-semibold text-text-base">Address</label>
                    <Input id="address" v-model="form.address" type="text" />
                    <p v-if="form.errors.address" class="mt-1 text-sm text-danger">{{ form.errors.address }}</p>
                </div>

                <div>
                    <label for="mobile_no" class="mb-1 block text-sm font-semibold text-text-base">Mobile No</label>
                    <Input id="mobile_no" v-model="form.mobile_no" type="text" />
                    <p v-if="form.errors.mobile_no" class="mt-1 text-sm text-danger">{{ form.errors.mobile_no }}</p>
                </div>

                <div>
                    <label for="email" class="mb-1 block text-sm font-semibold text-text-base">Email</label>
                    <Input id="email" v-model="form.email" type="email" />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label for="tpin" class="mb-1 block text-sm font-semibold text-text-base">TPIN</label>
                    <Input id="tpin" v-model="form.tpin" type="text" />
                    <p v-if="form.errors.tpin" class="mt-1 text-sm text-danger">{{ form.errors.tpin }}</p>
                </div>

                <div>
                    <label for="citizenship" class="mb-1 block text-sm font-semibold text-text-base">Citizenship</label>
                    <Input id="citizenship" v-model="form.citizenship" type="text" />
                    <p v-if="form.errors.citizenship" class="mt-1 text-sm text-danger">{{ form.errors.citizenship }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="form.processing" @click="submit">
                    {{ editing ? 'Save changes' : 'Create customer' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
