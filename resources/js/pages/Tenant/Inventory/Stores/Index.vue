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
    stores: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
const { can } = usePermissions();
useLayoutChrome('Stores');

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
    address: '',
    phone: '',
    is_active: true,
});

const { showModal, editing, addAnother, savedNotice, isDirty, openCreate, openEdit, closeModal, onModalOpenChange, submit } = useCrudModal({
    form,
    url: '/stores',
    formId: 'store-form',
    fill: (store) => {
        form.name = store.name;
        form.address = store.address ?? '';
        form.phone = store.phone ?? '';
        form.is_active = !!store.is_active;
    },
});

async function destroy(store) {
    if (!(await confirm({ message: `Delete store "${store.name}"? Stock recorded in it will no longer be available to select. This cannot be undone.`, tone: 'danger', confirmLabel: 'Delete store' }))) return;
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
            can('stores.manage')
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
        <PageHeader title="Stores" description="Stores: the warehouses, shops or godowns where you keep stock.">
            <Button v-if="can('stores.manage')" variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New store
            </Button>
        </PageHeader>

        <Card variant="panel" class="bg-white">
            <div v-if="stores.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                <p class="text-sm font-semibold text-text-strong">No stores yet</p>
                <p v-if="can('stores.manage')" class="text-xs text-text-muted">Create your first store and it will be listed here.</p>
                <Button v-if="can('stores.manage')" variant="primary" tone="purple" @click="openCreate">
                    <Plus class="size-4" />
                    New store
                </Button>
            </div>
            <DataTable v-else :columns="columns" :data="stores" :page-size="10" empty-message="No stores" />
        </Card>

        <Modal
            :open="showModal"
            :title="editing ? 'Edit store' : 'New store'"
            :description="editing ? '' : 'A warehouse, shop or godown where you keep stock.'"
            :dirty="isDirty"
            @update:open="onModalOpenChange"
        >
            <form id="store-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <p v-if="savedNotice" class="border-[1.5px] border-success bg-success-bg-soft px-3 py-2 text-[13px] text-success" role="status">
                    {{ savedNotice }}
                </p>

                <FormField v-slot="{ describedBy }" label="Name" for="store-name" required :error="form.errors.name">
                    <Input
                        id="store-name"
                        v-model="form.name"
                        type="text"
                        placeholder="e.g. Main Warehouse"
                        :aria-describedby="describedBy"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                        required
                        data-autofocus
                    />
                </FormField>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ describedBy }" label="Address" for="store-address" :error="form.errors.address">
                        <Input
                            id="store-address"
                            v-model="form.address"
                            type="text"
                            placeholder="e.g. Street, City"
                            :aria-describedby="describedBy"
                            :aria-invalid="form.errors.address ? 'true' : undefined"
                        />
                    </FormField>

                    <FormField v-slot="{ describedBy }" label="Phone" for="store-phone" :error="form.errors.phone">
                        <Input
                            id="store-phone"
                            v-model="form.phone"
                            type="tel"
                            placeholder="e.g. 98XXXXXXXX"
                            :aria-describedby="describedBy"
                            :aria-invalid="form.errors.phone ? 'true' : undefined"
                        />
                    </FormField>
                </div>

                <CheckboxField
                    v-if="editing"
                    id="store-is-active"
                    v-model="form.is_active"
                    label="Active"
                    hint="Inactive stores are hidden from selection lists."
                />
            </form>

            <template #footer>
                <label v-if="!editing" class="mr-auto flex items-center gap-2 text-[13px] text-text-base">
                    <input v-model="addAnother" type="checkbox" class="size-4 border-[1.5px] border-border" />
                    Add another
                </label>
                <Button variant="secondary" tone="neutral" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" form="store-form" :disabled="form.processing">
                    {{ form.processing ? 'Saving...' : editing ? 'Save store' : 'Create store' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
