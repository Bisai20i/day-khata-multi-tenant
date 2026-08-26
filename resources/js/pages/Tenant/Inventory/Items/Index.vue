<script setup>
import { computed, h, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import RowActions from '@/components/ui/RowActions.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
    subcategories: {
        type: Array,
        default: () => [],
    },
    items: {
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

const navItems = computed(() => navGroups(isAdmin.value));

const categoryOptions = computed(() => props.categories.map((category) => ({ value: category.id, label: category.name })));

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    item_category_id: '',
    item_subcategory_id: '',
    name: '',
    description: '',
    unit: '',
    hs_code: '',
    min_stock: '',
    is_vatable: false,
    is_stockable: true,
    is_active: true,
});

form.transform((data) => ({
    ...data,
    item_subcategory_id: data.item_subcategory_id === '' ? null : data.item_subcategory_id,
    description: data.description === '' ? null : data.description,
    hs_code: data.hs_code === '' ? null : data.hs_code,
    min_stock: data.min_stock === '' ? null : data.min_stock,
}));

const subcategoryOptions = computed(() => {
    const filtered = props.subcategories.filter((subcategory) => subcategory.item_category_id === form.item_category_id);
    return [{ value: '', label: 'None' }, ...filtered.map((subcategory) => ({ value: subcategory.id, label: subcategory.name }))];
});

function onCategoryChange(value) {
    form.item_category_id = value;
    form.item_subcategory_id = '';
}

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

function openEdit(item) {
    editing.value = item;
    form.clearErrors();
    form.item_category_id = item.item_category_id;
    form.item_subcategory_id = item.item_subcategory_id ?? '';
    form.name = item.name;
    form.description = item.description ?? '';
    form.unit = item.unit;
    form.hs_code = item.hs_code ?? '';
    form.min_stock = item.min_stock ?? '';
    form.is_vatable = !!item.is_vatable;
    form.is_stockable = !!item.is_stockable;
    form.is_active = !!item.is_active;
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
        form.put(`/items/${editing.value.id}`, { onSuccess: closeModal });
    } else {
        form.post('/items', { onSuccess: closeModal });
    }
}

function destroy(item) {
    if (!confirm('Delete this item?')) return;
    router.delete(`/items/${item.id}`);
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
        id: 'subcategory',
        header: 'Subcategory',
        numeric: false,
        cell: ({ row }) => row.original.subcategory?.name ?? '—',
    },
    { accessorKey: 'unit', header: 'Unit', numeric: false },
    {
        id: 'vatable',
        header: 'Vatable',
        numeric: false,
        cell: ({ row }) =>
            h(Badge, { variant: row.original.is_vatable ? 'success' : 'neutral', pill: true }, () =>
                row.original.is_vatable ? 'Vatable' : 'Not vatable',
            ),
    },
    {
        id: 'active',
        header: 'Active',
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
            h(RowActions, {
                onEdit: () => openEdit(row.original),
                onDelete: () => destroy(row.original),
            }),
    },
];
</script>

<template>
    <AppLayout title="Items" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Items</h2>
            <Button variant="primary" tone="purple" @click="openCreate">New item</Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="items" :page-size="10" />
        </Card>

        <Modal :open="showModal" :title="editing ? 'Edit item' : 'New item'" @update:open="onModalOpenChange">
            <form id="item-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="item_category_id" class="mb-1 block text-sm font-semibold text-text-base">Category</label>
                        <Select
                            id="item_category_id"
                            :model-value="form.item_category_id"
                            :options="categoryOptions"
                            placeholder="Select category"
                            @update:model-value="onCategoryChange"
                        />
                        <p v-if="form.errors.item_category_id" class="mt-1 text-sm text-danger">{{ form.errors.item_category_id }}</p>
                    </div>

                    <div>
                        <label for="item_subcategory_id" class="mb-1 block text-sm font-semibold text-text-base">Subcategory</label>
                        <Select
                            id="item_subcategory_id"
                            v-model="form.item_subcategory_id"
                            :options="subcategoryOptions"
                            placeholder="Select subcategory"
                        />
                        <p v-if="form.errors.item_subcategory_id" class="mt-1 text-sm text-danger">{{ form.errors.item_subcategory_id }}</p>
                    </div>
                </div>

                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input id="name" v-model="form.name" type="text" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="description" class="mb-1 block text-sm font-semibold text-text-base">Description</label>
                    <textarea
                        id="description"
                        v-model="form.description"
                        rows="3"
                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base transition-colors duration-150 outline-none placeholder:text-text-faint focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                    ></textarea>
                    <p v-if="form.errors.description" class="mt-1 text-sm text-danger">{{ form.errors.description }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="unit" class="mb-1 block text-sm font-semibold text-text-base">Unit</label>
                        <Input id="unit" v-model="form.unit" type="text" placeholder="pcs" required />
                        <p v-if="form.errors.unit" class="mt-1 text-sm text-danger">{{ form.errors.unit }}</p>
                    </div>

                    <div>
                        <label for="hs_code" class="mb-1 block text-sm font-semibold text-text-base">HS code</label>
                        <Input id="hs_code" v-model="form.hs_code" type="text" />
                        <p v-if="form.errors.hs_code" class="mt-1 text-sm text-danger">{{ form.errors.hs_code }}</p>
                    </div>
                </div>

                <div>
                    <label for="min_stock" class="mb-1 block text-sm font-semibold text-text-base">Minimum stock</label>
                    <Input id="min_stock" v-model="form.min_stock" type="number" step="0.01" class="max-w-[160px]" />
                    <p v-if="form.errors.min_stock" class="mt-1 text-sm text-danger">{{ form.errors.min_stock }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex items-center gap-2">
                        <input id="is_vatable" v-model="form.is_vatable" type="checkbox" class="size-4 border-[1.5px] border-border" />
                        <label for="is_vatable" class="text-sm font-semibold text-text-base">Vatable</label>
                    </div>

                    <div class="flex items-center gap-2">
                        <input id="is_stockable" v-model="form.is_stockable" type="checkbox" class="size-4 border-[1.5px] border-border" />
                        <label for="is_stockable" class="text-sm font-semibold text-text-base">Stockable</label>
                    </div>

                    <div class="flex items-center gap-2">
                        <input id="is_active" v-model="form.is_active" type="checkbox" class="size-4 border-[1.5px] border-border" />
                        <label for="is_active" class="text-sm font-semibold text-text-base">Active</label>
                    </div>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" form="item-form" :disabled="form.processing">
                    {{ editing ? 'Save changes' : 'Create item' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
