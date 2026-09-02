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
import RowActions from '@/components/ui/RowActions.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';

defineProps({
    stores: {
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

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    name: '',
    address: '',
    phone: '',
    is_active: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

function openEdit(store) {
    editing.value = store;
    form.clearErrors();
    form.name = store.name;
    form.address = store.address ?? '';
    form.phone = store.phone ?? '';
    form.is_active = !!store.is_active;
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
        form.put(`/stores/${editing.value.id}`, { onSuccess: closeModal });
    } else {
        form.post('/stores', { onSuccess: closeModal });
    }
}

function destroy(store) {
    if (!confirm('Delete this store?')) return;
    router.delete(`/stores/${store.id}`);
}

const columns = [
    { accessorKey: 'name', header: 'Name' },
    { accessorKey: 'address', header: 'Address' },
    { accessorKey: 'phone', header: 'Phone' },
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
    <AppLayout title="Stores" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Stores</h2>
            <Button variant="primary" tone="purple" @click="openCreate">New store</Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="stores" :page-size="10" />
        </Card>

        <Modal
            :open="showModal"
            :title="editing ? 'Edit store' : 'New store'"
            size="compact"
            @update:open="onModalOpenChange"
        >
            <form id="store-form" class="flex flex-col gap-4" @submit.prevent="submit">
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
                    <label for="phone" class="mb-1 block text-sm font-semibold text-text-base">Phone</label>
                    <Input id="phone" v-model="form.phone" type="text" />
                    <p v-if="form.errors.phone" class="mt-1 text-sm text-danger">{{ form.errors.phone }}</p>
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
                    form="store-form"
                    :disabled="form.processing"
                >
                    {{ editing ? 'Save changes' : 'Create store' }}
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
