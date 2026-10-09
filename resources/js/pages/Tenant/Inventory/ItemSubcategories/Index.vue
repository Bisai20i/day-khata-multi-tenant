<script setup>
import { computed, h, ref, watch } from 'vue';
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
import Combobox from '@/components/ui/Combobox.vue';
import FormField from '@/components/ui/FormField.vue';
import CheckboxField from '@/components/ui/CheckboxField.vue';
import QuickAddModal from '@/components/ui/QuickAddModal.vue';
import RowActions from '@/components/ui/RowActions.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { useCrudModal } from '@/composables/useCrudModal';
import { usePermissions } from '@/composables/usePermissions';
import { findCreatedByName } from '@/lib/formModal.js';

defineOptions({ layout: AppLayout });

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
const { confirm } = useConfirm();
const { can } = usePermissions();
useLayoutChrome('Item Subcategories');

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

const categoryOptions = computed(() => props.categories.map((category) => ({ value: category.id, label: category.name })));

const form = useForm({
    item_category_id: '',
    name: '',
    is_active: true,
});

// A new subcategory starts in the category the last one was added to: the
// usual job is filling one category with several subcategories in a row.
const { showModal, editing, addAnother, savedNotice, isDirty, openCreate, openEdit, closeModal, onModalOpenChange, submit } = useCrudModal({
    form,
    url: '/item-subcategories',
    formId: 'item-subcategory-form',
    fill: (subcategory) => {
        form.item_category_id = subcategory.item_category_id;
        form.name = subcategory.name;
        form.is_active = !!subcategory.is_active;
    },
    createDefaults: (lastCreated) => (lastCreated ? { item_category_id: lastCreated.item_category_id } : {}),
});

const showCategoryQuickAdd = ref(false);

function onCategoryCreated(name) {
    const category = findCreatedByName(props.categories, name);
    if (category) form.item_category_id = category.id;
}

async function destroy(subcategory) {
    if (!(await confirm({ message: `Delete subcategory "${subcategory.name}"? Items in it will become uncategorised. This cannot be undone.`, tone: 'danger', confirmLabel: 'Delete subcategory' }))) return;
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
        <PageHeader title="Item Subcategories" description="Item subcategories: finer groups inside a category, such as Soft drinks under Beverages.">
            <Button v-if="can('item_categories.manage')" variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New subcategory
            </Button>
        </PageHeader>

        <Card variant="panel">
            <DataTable :columns="columns" :data="subcategories" :page-size="10" />
        </Card>

        <Modal
            :open="showModal"
            :title="editing ? 'Edit subcategory' : 'New subcategory'"
            :description="editing ? '' : 'A finer group inside a category, such as Soft drinks under Beverages.'"
            :dirty="isDirty"
            @update:open="onModalOpenChange"
        >
            <form id="item-subcategory-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <p v-if="savedNotice" class="border-[1.5px] border-success bg-success-bg-soft px-3 py-2 text-[13px] text-success" role="status">
                    {{ savedNotice }}
                </p>

                <FormField label="Category" for="item-subcategory-category" required :error="form.errors.item_category_id">
                    <Combobox
                        id="item-subcategory-category"
                        :model-value="form.item_category_id"
                        :options="categoryOptions"
                        placeholder="Search or select a category"
                        @update:model-value="(value) => (form.item_category_id = value)"
                    >
                        <template #addon>
                            <button
                                type="button"
                                class="flex items-center gap-1 text-[12px] font-bold text-text-muted hover:text-primary"
                                title="Add a new category"
                                @click="showCategoryQuickAdd = true"
                            >
                                <Plus class="h-3.5 w-3.5" />
                                New
                            </button>
                        </template>
                    </Combobox>
                </FormField>

                <FormField v-slot="{ describedBy }" label="Name" for="item-subcategory-name" required :error="form.errors.name">
                    <Input
                        id="item-subcategory-name"
                        v-model="form.name"
                        type="text"
                        placeholder="e.g. Soft drinks"
                        :aria-describedby="describedBy"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                        required
                        :data-autofocus="form.item_category_id ? '' : undefined"
                    />
                </FormField>

                <CheckboxField
                    v-if="editing"
                    id="item-subcategory-is-active"
                    v-model="form.is_active"
                    label="Active"
                    hint="Inactive subcategories are hidden from selection lists."
                />
            </form>

            <QuickAddModal
                v-model:open="showCategoryQuickAdd"
                title="New category"
                url="/item-categories"
                placeholder="e.g. Beverages"
                @created="onCategoryCreated"
            />

            <template #footer>
                <label v-if="!editing" class="mr-auto flex items-center gap-2 text-[13px] text-text-base">
                    <input v-model="addAnother" type="checkbox" class="size-4 border-[1.5px] border-border" />
                    Add another
                </label>
                <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" form="item-subcategory-form" :disabled="form.processing">
                    {{ form.processing ? 'Saving...' : editing ? 'Save subcategory' : 'Create subcategory' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
