<script setup>
import { computed, h, ref, watch } from 'vue';
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
    Pencil,
    Trash2,
} from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
    subcategories: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();

// Create/edit/delete all redirect back to this same route/component - Inertia
// patches the already-mounted instance rather than remounting it, so a plain
// onMounted only ever catches the very first page load, not any subsequent
// in-place action. Watching the flash prop directly (with immediate: true to
// preserve the original on-load behavior) catches every update instead.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

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

const categoryOptions = computed(() => props.categories.map((category) => ({ value: category.id, label: category.name })));

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    item_category_id: '',
    name: '',
    is_active: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

function openEdit(subcategory) {
    editing.value = subcategory;
    form.clearErrors();
    form.item_category_id = subcategory.item_category_id;
    form.name = subcategory.name;
    form.is_active = !!subcategory.is_active;
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
}

function onModalOpenChange(value) {
    if (!value) closeModal();
}

function submit() {
    if (editing.value) {
        form.put(`/item-subcategories/${editing.value.id}`, { onSuccess: closeModal });
    } else {
        form.post('/item-subcategories', { onSuccess: closeModal });
    }
}

function destroy(subcategory) {
    if (!confirm('Delete this subcategory?')) return;
    router.delete(`/item-subcategories/${subcategory.id}`);
}

const columns = [
    { accessorKey: 'name', header: 'Name' },
    {
        id: 'category',
        header: 'Category',
        numeric: false,
        cell: ({ row }) => row.original.category.name,
    },
    {
        id: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) =>
            h(Badge, { variant: row.original.is_active ? 'success' : 'neutral', pill: true }, () =>
                row.original.is_active ? 'Active' : 'Inactive',
            ),
    },
    {
        id: 'actions',
        header: '',
        numeric: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-1.5' }, [
                h(
                    Button,
                    {
                        variant: 'icon',
                        type: 'button',
                        'aria-label': 'Edit',
                        onClick: () => openEdit(row.original),
                    },
                    () => h(Pencil, { class: 'size-3.5' }),
                ),
                h(
                    Button,
                    {
                        variant: 'icon',
                        type: 'button',
                        'aria-label': 'Delete',
                        onClick: () => destroy(row.original),
                    },
                    () => h(Trash2, { class: 'size-3.5' }),
                ),
            ]),
    },
];
</script>

<template>
    <AppLayout title="Item Subcategories" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Item Subcategories</h2>
            <Button variant="primary" tone="purple" @click="openCreate">New subcategory</Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="subcategories" :page-size="10" />
        </Card>

        <Modal
            :open="showModal"
            :title="editing ? 'Edit subcategory' : 'New subcategory'"
            size="compact"
            @update:open="onModalOpenChange"
        >
            <form id="item-subcategory-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="item_category_id" class="mb-1 block text-sm font-semibold text-text-base">Category</label>
                    <Select
                        id="item_category_id"
                        v-model="form.item_category_id"
                        :options="categoryOptions"
                        placeholder="Select category"
                    />
                    <p v-if="form.errors.item_category_id" class="mt-1 text-sm text-danger">{{ form.errors.item_category_id }}</p>
                </div>

                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input id="name" v-model="form.name" type="text" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div class="flex items-center gap-2">
                    <input id="is_active" v-model="form.is_active" type="checkbox" class="size-4 border-[1.5px] border-border" />
                    <label for="is_active" class="text-sm font-semibold text-text-base">Active</label>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="submit"
                    form="item-subcategory-form"
                    :disabled="form.processing"
                >
                    {{ editing ? 'Save changes' : 'Create subcategory' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
