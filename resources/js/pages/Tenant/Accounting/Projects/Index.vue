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
import Select from '@/components/ui/Select.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import FormField from '@/components/ui/FormField.vue';
import RowActions from '@/components/ui/RowActions.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { useCrudModal } from '@/composables/useCrudModal';
import { usePermissions } from '@/composables/usePermissions';
import { formatMoney } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

defineProps({
    projects: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
const { can } = usePermissions();
useLayoutChrome('Projects');

// Create/update/delete all redirect back to this route, which Inertia patches
// in place, so the flash is watched rather than read on mount.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const STATUS_LABELS = { active: 'Active', on_hold: 'On hold', completed: 'Completed' };
const STATUS_VARIANTS = { active: 'success', on_hold: 'warning', completed: 'neutral' };
const statusOptions = Object.entries(STATUS_LABELS).map(([value, label]) => ({ value, label }));

// Amounts stay decimal strings from the server, through the form, back to the
// server; the server validates them (at most 2 decimals) and stores them exactly.
const form = useForm({
    code: '',
    name: '',
    name_np: '',
    client: '',
    contract_value: '',
    budget: '',
    start_date: '',
    end_date: '',
    status: 'active',
    notes: '',
});

const { showModal, editing, addAnother, savedNotice, isDirty, openCreate, openEdit, closeModal, onModalOpenChange, submit } = useCrudModal({
    form,
    url: '/projects',
    formId: 'project-form',
    savedLabel: (data) => data.code,
    fill: (project) => {
        form.code = project.code;
        form.name = project.name;
        form.name_np = project.name_np ?? '';
        form.client = project.client ?? '';
        form.contract_value = project.contract_value ?? '';
        form.budget = project.budget ?? '';
        form.start_date = project.start_date ?? '';
        form.end_date = project.end_date ?? '';
        form.status = project.status;
        form.notes = project.notes ?? '';
    },
});

async function destroy(project) {
    // Nothing left to do: a project with entries cannot be deleted and is already completed.
    if (project.lines_count > 0 && project.status === 'completed') {
        toast({ message: `"${project.code}" has journal entries, so it cannot be deleted. It is already completed.`, variant: 'danger' });
        return;
    }

    const message =
        project.lines_count > 0
            ? `"${project.code}" already has journal entries, so it cannot be deleted. It will be marked completed instead. Continue?`
            : `Delete project "${project.code} - ${project.name}"? If any document already uses it, it is marked completed instead; otherwise it is deleted and this cannot be undone.`;

    if (!(await confirm({ message, tone: 'danger', confirmLabel: project.lines_count > 0 ? 'Mark completed' : 'Delete project' }))) return;

    router.delete(`/projects/${project.id}`, {
        preserveScroll: true,
        onError: (errors) => toast({ message: Object.values(errors)[0] ?? 'Could not delete the project.', variant: 'danger' }),
    });
}

function dateCell(value) {
    return (value && formatBsDate(value)) || '-';
}

const columns = [
    { accessorKey: 'code', header: 'Code' },
    { accessorKey: 'name', header: 'Name' },
    { accessorKey: 'client', header: 'Client', cell: ({ row }) => row.original.client || '-' },
    { accessorKey: 'contract_value', header: 'Contract value', numeric: true, cell: ({ row }) => formatMoney(row.original.contract_value) },
    { accessorKey: 'budget', header: 'Budget', numeric: true, cell: ({ row }) => formatMoney(row.original.budget) },
    {
        accessorKey: 'status',
        header: 'Status',
        numeric: false,
        cell: ({ row }) => h(Badge, { variant: STATUS_VARIANTS[row.original.status] ?? 'neutral', pill: true }, () => STATUS_LABELS[row.original.status] ?? row.original.status),
    },
    { accessorKey: 'start_date', header: 'Start (BS)', numeric: false, cell: ({ row }) => dateCell(row.original.start_date) },
    { accessorKey: 'end_date', header: 'End (BS)', numeric: false, cell: ({ row }) => dateCell(row.original.end_date) },
    {
        id: 'actions',
        header: '',
        numeric: false,
        cell: ({ row }) =>
            can('projects.manage')
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
            title="Projects"
            description="Jobs or contracts you tag transactions with, so income, cost and profit can be followed per project."
        >
            <Button v-if="can('projects.manage')" variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New project
            </Button>
        </PageHeader>

        <Card variant="panel" class="bg-white">
            <div v-if="projects.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                <p class="text-sm font-semibold text-text-strong">No projects yet</p>
                <p v-if="can('projects.manage')" class="text-xs text-text-muted">Create your first project, then pick it on sales, purchases and vouchers.</p>
                <Button v-if="can('projects.manage')" variant="primary" tone="purple" @click="openCreate">
                    <Plus class="size-4" />
                    New project
                </Button>
            </div>
            <DataTable v-else :columns="columns" :data="projects" :page-size="25" empty-message="No projects" />
        </Card>

        <Modal
            :open="showModal"
            :title="editing ? 'Edit project' : 'New project'"
            :description="editing ? '' : 'A job or contract that transactions can be tagged with.'"
            size="lg"
            :dirty="isDirty"
            @update:open="onModalOpenChange"
        >
            <form id="project-form" class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <p v-if="savedNotice" class="border-[1.5px] border-success bg-success-bg-soft px-3 py-2 text-[13px] text-success sm:col-span-2" role="status">
                    {{ savedNotice }}
                </p>

                <FormField v-slot="{ describedBy }" label="Code" for="project-code" required :error="form.errors.code">
                    <Input
                        id="project-code"
                        v-model="form.code"
                        type="text"
                        maxlength="50"
                        placeholder="e.g. PRJ-001"
                        :aria-describedby="describedBy"
                        :aria-invalid="form.errors.code ? 'true' : undefined"
                        required
                        data-autofocus
                    />
                </FormField>

                <FormField v-slot="{ describedBy }" label="Name" for="project-name" required :error="form.errors.name">
                    <Input
                        id="project-name"
                        v-model="form.name"
                        type="text"
                        maxlength="255"
                        :aria-describedby="describedBy"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                        required
                    />
                </FormField>

                <FormField v-slot="{ describedBy }" label="Name (Nepali)" for="project-name-np" :error="form.errors.name_np">
                    <Input id="project-name-np" v-model="form.name_np" type="text" maxlength="255" :aria-describedby="describedBy" />
                </FormField>

                <FormField v-slot="{ describedBy }" label="Client" for="project-client" :error="form.errors.client">
                    <Input id="project-client" v-model="form.client" type="text" maxlength="255" :aria-describedby="describedBy" />
                </FormField>

                <FormField v-slot="{ describedBy }" label="Contract value" for="project-contract-value" :error="form.errors.contract_value">
                    <Input
                        id="project-contract-value"
                        v-model="form.contract_value"
                        type="text"
                        inputmode="decimal"
                        placeholder="0.00"
                        :aria-describedby="describedBy"
                        :aria-invalid="form.errors.contract_value ? 'true' : undefined"
                    />
                </FormField>

                <FormField v-slot="{ describedBy }" label="Budget" for="project-budget" :error="form.errors.budget">
                    <Input
                        id="project-budget"
                        v-model="form.budget"
                        type="text"
                        inputmode="decimal"
                        placeholder="0.00"
                        :aria-describedby="describedBy"
                        :aria-invalid="form.errors.budget ? 'true' : undefined"
                    />
                </FormField>

                <FormField label="Start date" for="project-start-date" :error="form.errors.start_date">
                    <NepaliDateInput id="project-start-date" v-model="form.start_date" />
                </FormField>

                <FormField label="End date" for="project-end-date" :error="form.errors.end_date">
                    <NepaliDateInput id="project-end-date" v-model="form.end_date" />
                </FormField>

                <FormField label="Status" for="project-status" required :error="form.errors.status">
                    <Select id="project-status" v-model="form.status" :options="statusOptions" />
                </FormField>

                <FormField v-slot="{ describedBy }" label="Notes" for="project-notes" :error="form.errors.notes" class="sm:col-span-2">
                    <textarea
                        id="project-notes"
                        v-model="form.notes"
                        rows="3"
                        maxlength="2000"
                        :aria-describedby="describedBy"
                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base outline-none transition-colors duration-150 focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                    ></textarea>
                </FormField>
            </form>

            <template #footer>
                <label v-if="!editing" class="mr-auto flex items-center gap-2 text-[13px] text-text-base">
                    <input v-model="addAnother" type="checkbox" class="size-4 border-[1.5px] border-border" />
                    Add another
                </label>
                <Button variant="secondary" tone="neutral" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" form="project-form" :disabled="form.processing">
                    {{ form.processing ? 'Saving...' : editing ? 'Save project' : 'Create project' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
