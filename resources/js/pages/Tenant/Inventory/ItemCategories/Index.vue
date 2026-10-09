<script setup>
import { h, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import Input from '@/components/ui/Input.vue';
import FormField from '@/components/ui/FormField.vue';
import CheckboxField from '@/components/ui/CheckboxField.vue';
import RowActions from '@/components/ui/RowActions.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { useCrudModal } from '@/composables/useCrudModal';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
const { can } = usePermissions();
useLayoutChrome('Item Categories');

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

const form = useForm({
    name: '',
    is_active: true,
});

const { showModal, editing, addAnother, savedNotice, isDirty, openCreate, openEdit, closeModal, onModalOpenChange, submit } = useCrudModal({
    form,
    url: '/item-categories',
    formId: 'item-category-form',
    fill: (category) => {
        form.name = category.name;
        form.is_active = !!category.is_active;
    },
});

async function destroy(category) {
    if (!(await confirm({ message: `Delete category "${category.name}"? Items in it will become uncategorised. This cannot be undone.`, tone: 'danger', confirmLabel: 'Delete category' }))) return;
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
            can('item_categories.manage')
                ? h(RowActions, {
                      onEdit: () => openEdit(row.original),
                      onDelete: () => destroy(row.original),
                  })
                : null,
    },
];
</script>

<template>
    <div>
        <PageHeader title="Item Categories" description="Item categories: top-level groups for organising your items, such as Beverages or Grocery.">
            <Button v-if="can('item_categories.manage')" variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New category
            </Button>
        </PageHeader>

        <Card variant="panel">
            <DataTable :columns="columns" :data="categories" :page-size="10" />
        </Card>

        <Modal
            :open="showModal"
            :title="editing ? 'Edit category' : 'New category'"
            :description="editing ? '' : 'A top-level group for your items, such as Beverages or Grocery.'"
            size="compact"
            :dirty="isDirty"
            @update:open="onModalOpenChange"
        >
            <form id="item-category-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <p v-if="savedNotice" class="border-[1.5px] border-success bg-success-bg-soft px-3 py-2 text-[13px] text-success" role="status">
                    {{ savedNotice }}
                </p>

                <FormField v-slot="{ describedBy }" label="Name" for="item-category-name" required :error="form.errors.name">
                    <Input
                        id="item-category-name"
                        v-model="form.name"
                        type="text"
                        placeholder="e.g. Beverages"
                        :aria-describedby="describedBy"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                        required
                        data-autofocus
                    />
                </FormField>

                <CheckboxField
                    v-if="editing"
                    id="item-category-is-active"
                    v-model="form.is_active"
                    label="Active"
                    hint="Inactive categories are hidden from selection lists."
                />
            </form>

            <template #footer>
                <label v-if="!editing" class="mr-auto flex items-center gap-2 text-[13px] text-text-base">
                    <input v-model="addAnother" type="checkbox" class="size-4 border-[1.5px] border-border" />
                    Add another
                </label>
                <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" form="item-category-form" :disabled="form.processing">
                    {{ form.processing ? 'Saving...' : editing ? 'Save category' : 'Create category' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
