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
    varieties: {
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

const itemOptions = computed(() => props.items.map((item) => ({ value: item.id, label: item.name })));

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    item_id: '',
    name: '',
    sku_suffix: '',
    price_adjustment: 0,
    is_active: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

function openEdit(variety) {
    editing.value = variety;
    form.clearErrors();
    form.item_id = variety.item_id;
    form.name = variety.name;
    form.sku_suffix = variety.sku_suffix ?? '';
    form.price_adjustment = variety.price_adjustment;
    form.is_active = !!variety.is_active;
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
        form.put(`/item-varieties/${editing.value.id}`, { onSuccess: closeModal });
    } else {
        form.post('/item-varieties', { onSuccess: closeModal });
    }
}

function destroy(variety) {
    if (!confirm('Delete this variety?')) return;
    router.delete(`/item-varieties/${variety.id}`);
}

const columns = [
    { accessorKey: 'name', header: 'Name' },
    {
        id: 'item',
        header: 'Item',
        numeric: false,
        cell: ({ row }) => row.original.item.name,
    },
    {
        id: 'sku_suffix',
        header: 'SKU Suffix',
        numeric: false,
        cell: ({ row }) => row.original.sku_suffix ?? '-',
    },
    {
        id: 'price_adjustment',
        header: 'Price Adjustment',
        numeric: true,
        cell: ({ row }) => Number(row.original.price_adjustment).toFixed(2),
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
            h(RowActions, {
                onEdit: () => openEdit(row.original),
                onDelete: () => destroy(row.original),
            }),
    },
];
</script>

<template>
    <AppLayout title="Item Varieties" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Item Varieties</h2>
            <Button variant="primary" tone="purple" @click="openCreate">New variety</Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="varieties" :page-size="10" />
        </Card>

        <Modal
            :open="showModal"
            :title="editing ? 'Edit variety' : 'New variety'"
            size="compact"
            @update:open="onModalOpenChange"
        >
            <form id="item-variety-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="item_id" class="mb-1 block text-sm font-semibold text-text-base">Item</label>
                    <Select id="item_id" v-model="form.item_id" :options="itemOptions" placeholder="Select item" />
                    <p v-if="form.errors.item_id" class="mt-1 text-sm text-danger">{{ form.errors.item_id }}</p>
                </div>

                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input id="name" v-model="form.name" type="text" placeholder="e.g. Red / Large" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="sku_suffix" class="mb-1 block text-sm font-semibold text-text-base">SKU Suffix</label>
                    <Input id="sku_suffix" v-model="form.sku_suffix" type="text" />
                    <p v-if="form.errors.sku_suffix" class="mt-1 text-sm text-danger">{{ form.errors.sku_suffix }}</p>
                </div>

                <div>
                    <label for="price_adjustment" class="mb-1 block text-sm font-semibold text-text-base">
                        Price Adjustment
                    </label>
                    <Input id="price_adjustment" v-model="form.price_adjustment" type="number" step="0.01" />
                    <p v-if="form.errors.price_adjustment" class="mt-1 text-sm text-danger">
                        {{ form.errors.price_adjustment }}
                    </p>
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
                    form="item-variety-form"
                    :disabled="form.processing"
                >
                    {{ editing ? 'Save changes' : 'Create variety' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
