<script setup>
import { h, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import Input from '@/components/ui/Input.vue';
import FormField from '@/components/ui/FormField.vue';
import RowActions from '@/components/ui/RowActions.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { useCrudModal } from '@/composables/useCrudModal';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

defineProps({
    areas: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
const { can } = usePermissions();
useLayoutChrome('Areas');

// Create/rename/delete all redirect back to this route, which Inertia patches
// in place, so the flash is watched rather than read on mount.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const form = useForm({ name: '' });

const { showModal, editing, addAnother, savedNotice, isDirty, openCreate, openEdit, closeModal, onModalOpenChange, submit } = useCrudModal({
    form,
    url: '/areas',
    formId: 'area-form',
    fill: (area) => {
        form.name = area.name;
    },
});

async function destroy(area) {
    const inUse = (area.customers_count ?? 0) + (area.suppliers_count ?? 0);
    const message = inUse > 0
        ? `"${area.name}" still has ${inUse} customer(s) and supplier(s) assigned, so it cannot be deleted until they are moved to another area. Try anyway?`
        : `Delete area "${area.name}"? This cannot be undone.`;

    if (!(await confirm({ message, tone: 'danger', confirmLabel: 'Delete area' }))) return;

    router.delete(`/areas/${area.id}`, {
        preserveScroll: true,
        onError: (errors) => toast({ message: errors.area ?? Object.values(errors)[0] ?? 'Could not delete the area.', variant: 'danger' }),
    });
}

const columns = [
    { accessorKey: 'name', header: 'Area' },
    { accessorKey: 'customers_count', header: 'Customers', numeric: true },
    { accessorKey: 'suppliers_count', header: 'Suppliers', numeric: true },
    {
        id: 'actions',
        header: '',
        numeric: false,
        cell: ({ row }) =>
            can('areas.manage')
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
        <PageHeader
            title="Areas"
            description="Places you group customers and suppliers by - a city, district or sales route. Filter and group the Debtors, Creditors and ageing reports by area."
        >
            <Button v-if="can('areas.manage')" variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New area
            </Button>
        </PageHeader>

        <Card variant="panel" class="bg-white">
            <div v-if="areas.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                <p class="text-sm font-semibold text-text-strong">No areas yet</p>
                <p v-if="can('areas.manage')" class="text-xs text-text-muted">Create your first area, then assign it to customers and suppliers.</p>
                <Button v-if="can('areas.manage')" variant="primary" tone="purple" @click="openCreate">
                    <Plus class="size-4" />
                    New area
                </Button>
            </div>
            <DataTable v-else :columns="columns" :data="areas" :page-size="25" empty-message="No areas" />
        </Card>

        <Modal
            :open="showModal"
            :title="editing ? 'Rename area' : 'New area'"
            :description="editing ? '' : 'A place you group customers and suppliers by.'"
            :dirty="isDirty"
            @update:open="onModalOpenChange"
        >
            <form id="area-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <p v-if="savedNotice" class="border-[1.5px] border-success bg-success-bg-soft px-3 py-2 text-[13px] text-success" role="status">
                    {{ savedNotice }}
                </p>

                <FormField v-slot="{ describedBy }" label="Name" for="area-name" required :error="form.errors.name">
                    <Input
                        id="area-name"
                        v-model="form.name"
                        type="text"
                        maxlength="150"
                        placeholder="e.g. Pokhara"
                        :aria-describedby="describedBy"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                        required
                        data-autofocus
                    />
                </FormField>
            </form>

            <template #footer>
                <label v-if="!editing" class="mr-auto flex items-center gap-2 text-[13px] text-text-base">
                    <input v-model="addAnother" type="checkbox" class="size-4 border-[1.5px] border-border" />
                    Add another
                </label>
                <Button variant="secondary" tone="neutral" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" form="area-form" :disabled="form.processing">
                    {{ form.processing ? 'Saving...' : editing ? 'Save area' : 'Create area' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
