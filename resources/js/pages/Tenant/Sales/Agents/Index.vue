<script setup>
import { h, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { Plus } from '@lucide/vue';
import DataTable from '@/components/ui/DataTable.vue';
import RowActions from '@/components/ui/RowActions.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';

defineOptions({ layout: AppLayout });

defineProps({
    agents: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
useLayoutChrome('Sales Agents');

// Flash status is watched (not just read on mount) because create/edit/delete
// all redirect back to this same route + component, which Inertia re-renders
// in place without an onMounted re-run.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const modalOpen = ref(false);
const editing = ref(null);

const form = useForm({
    name: '',
    mobile_no: '',
    address: '',
    commission_rate: '',
    is_active: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function openEdit(agent) {
    editing.value = agent;
    form.clearErrors();
    form.name = agent.name ?? '';
    form.mobile_no = agent.mobile_no ?? '';
    form.address = agent.address ?? '';
    form.commission_rate = agent.commission_rate ?? '';
    form.is_active = !!agent.is_active;
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
}

function onModalOpenChange(value) {
    if (value) {
        modalOpen.value = true;
    } else {
        closeModal();
    }
}

function submit() {
    if (editing.value) {
        form.put(`/agents/${editing.value.id}`, { onSuccess: closeModal });
    } else {
        form.post('/agents', { onSuccess: closeModal });
    }
}

async function destroyAgent(agent) {
    if (
        !(await confirm({
            message: `Delete agent ${agent.name}? They will no longer be available on new sales. Existing sales keep their record.`,
            tone: 'danger',
            confirmLabel: 'Delete agent',
        }))
    ) {
        return;
    }
    router.delete(`/agents/${agent.id}`);
}

const columns = [
    { accessorKey: 'name', header: 'Name' },
    {
        accessorKey: 'mobile_no',
        header: 'Mobile No',
        numeric: false,
        cell: ({ row }) => row.original.mobile_no ?? '-',
    },
    {
        id: 'commission_rate',
        header: 'Commission rate',
        numeric: true,
        cell: ({ row }) => (row.original.commission_rate !== null ? `${Number(row.original.commission_rate).toFixed(2)}%` : '-'),
    },
    {
        id: 'ledger_code',
        header: 'Ledger Code',
        numeric: false,
        cell: ({ row }) => row.original.account?.code ?? '-',
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
        header: 'Actions',
        numeric: false,
        cell: ({ row }) =>
            h(RowActions, {
                editLabel: `Edit ${row.original.name}`,
                deleteLabel: `Delete ${row.original.name}`,
                onEdit: () => openEdit(row.original),
                onDelete: () => destroyAgent(row.original),
            }),
    },
];
</script>

<template>
    <div>
        <PageHeader
            title="Sales agents"
            description="People who bring in sales and earn a commission on them. Each agent gets their own ledger account."
        >
            <Button variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" aria-hidden="true" />
                Add agent
            </Button>
        </PageHeader>

        <Card variant="panel">
            <div v-if="agents.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                <p class="text-sm font-semibold text-text-strong">No agents yet</p>
                <p class="text-xs text-text-muted">Add your first sales agent to track commission on their sales.</p>
                <Button variant="primary" tone="purple" @click="openCreate">
                    <Plus class="size-4" aria-hidden="true" />
                    Add agent
                </Button>
            </div>
            <template v-else>
                <DataTable :columns="columns" :data="agents" :page-size="10" empty-message="No agents" />
                <p class="mt-3 text-xs text-text-muted" aria-live="polite">{{ agents.length }} {{ agents.length === 1 ? 'agent' : 'agents' }} in total</p>
            </template>
        </Card>

        <Modal :open="modalOpen" :title="editing ? 'Edit agent' : 'New agent'" @update:open="onModalOpenChange">
            <form id="agent-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name <span class="text-danger">*</span></label>
                    <Input id="name" v-model="form.name" type="text" placeholder="e.g. Jane Doe" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="mobile_no" class="mb-1 block text-sm font-semibold text-text-base">Mobile No</label>
                    <Input id="mobile_no" v-model="form.mobile_no" type="text" placeholder="98XXXXXXXX" />
                    <p v-if="form.errors.mobile_no" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.mobile_no }}</p>
                </div>

                <div>
                    <label for="address" class="mb-1 block text-sm font-semibold text-text-base">Address</label>
                    <Input id="address" v-model="form.address" type="text" placeholder="e.g. Kathmandu-10" />
                    <p v-if="form.errors.address" class="mt-1 text-sm text-danger">{{ form.errors.address }}</p>
                </div>

                <div>
                    <label for="commission_rate" class="mb-1 block text-sm font-semibold text-text-base">
                        Default commission rate (%)
                    </label>
                    <Input id="commission_rate" v-model="form.commission_rate" type="number" min="0" max="100" step="0.01" placeholder="0.00" />
                    <p class="mt-1 text-xs text-text-muted">Percentage of each sale this agent earns as commission (0 to 100). Leave blank for none.</p>
                    <p v-if="form.errors.commission_rate" class="mt-1 text-sm text-danger" role="alert">{{ form.errors.commission_rate }}</p>
                </div>

                <div class="flex items-center gap-2">
                    <input id="is_active" v-model="form.is_active" type="checkbox" class="size-4 border-[1.5px] border-border" />
                    <label for="is_active" class="text-sm font-semibold text-text-base">Active (available to pick on new sales)</label>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" form="agent-form" :disabled="form.processing" :loading="form.processing">
                    {{ editing ? 'Save agent' : 'Add agent' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
