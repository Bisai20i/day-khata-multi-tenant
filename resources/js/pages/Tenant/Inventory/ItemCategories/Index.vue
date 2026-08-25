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
import { useToast } from '@/composables/useToast';

defineProps({
    categories: {
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

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    name: '',
    is_active: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

function openEdit(category) {
    editing.value = category;
    form.clearErrors();
    form.name = category.name;
    form.is_active = !!category.is_active;
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
        form.put(`/item-categories/${editing.value.id}`, { onSuccess: closeModal });
    } else {
        form.post('/item-categories', { onSuccess: closeModal });
    }
}

function destroy(category) {
    if (!confirm('Delete this category?')) return;
    router.delete(`/item-categories/${category.id}`);
}

const columns = [
    { accessorKey: 'name', header: 'Name' },
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
    <AppLayout title="Item Categories" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Item Categories</h2>
            <Button variant="primary" tone="purple" @click="openCreate">New category</Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="categories" :page-size="10" />
        </Card>

        <Modal
            :open="showModal"
            :title="editing ? 'Edit category' : 'New category'"
            size="compact"
            @update:open="onModalOpenChange"
        >
            <form id="item-category-form" class="flex flex-col gap-4" @submit.prevent="submit">
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
                    form="item-category-form"
                    :disabled="form.processing"
                >
                    {{ editing ? 'Save changes' : 'Create category' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
