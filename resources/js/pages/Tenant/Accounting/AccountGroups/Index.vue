<script setup>
import { computed, h, onMounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Modal from '@/components/ui/Modal.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import DataTable from '@/components/ui/DataTable.vue';
import RowActions from '@/components/ui/RowActions.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    heads: {
        type: Array,
        default: () => [],
    },
    groups: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { can } = usePermissions();
useLayoutChrome('Account Groups');

const { toast } = useToast();
const { confirm } = useConfirm();

onMounted(() => {
    if (page.props.flash?.status) {
        toast({ message: page.props.flash.status, variant: 'success' });
    }
});

const headOptions = computed(() => props.heads.map((head) => ({ value: head.id, label: head.name })));

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    account_head_id: '',
    name: '',
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

function openEdit(group) {
    editing.value = group;
    form.clearErrors();
    form.account_head_id = group.account_head_id;
    form.name = group.name;
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    form.reset();
    form.clearErrors();
    editing.value = null;
}

function onModalOpenChange(value) {
    if (value) {
        showModal.value = true;
    } else {
        closeModal();
    }
}

function submit() {
    if (editing.value) {
        form.put(`/account-groups/${editing.value.id}`, {
            onSuccess: () => {
                toast({ message: 'Account group updated', variant: 'success' });
                closeModal();
            },
        });
    } else {
        form.post('/account-groups', {
            onSuccess: () => {
                toast({ message: 'Account group created', variant: 'success' });
                closeModal();
            },
        });
    }
}

async function destroy(group) {
    if (!(await confirm({ message: `Delete account group "${group.name}"? This cannot be undone, and it will be refused if subgroups or accounts still use it.`, tone: 'danger', confirmLabel: 'Delete' }))) return;
    router.delete(`/account-groups/${group.id}`, {
        onSuccess: () => toast({ message: 'Account group deleted', variant: 'success' }),
    });
}

const columns = [
    { accessorKey: 'name', header: 'Group Name' },
    {
        id: 'head',
        header: 'Account Head',
        numeric: false,
        cell: ({ row }) => row.original.account_head?.name ?? '-',
    },
    {
        id: 'actions',
        header: '',
        numeric: false,
        // Gated by accounts.edit / accounts.delete server side, so the row
        // actions are hidden rather than left to fail with a 403.
        cell: ({ row }) =>
            can('accounts.edit') || can('accounts.delete')
                ? h(RowActions, {
                      canEdit: can('accounts.edit'),
                      canDelete: can('accounts.delete'),
                      onEdit: () => openEdit(row.original),
                      onDelete: () => destroy(row.original),
                  })
                : null,
    },
];
</script>

<template>
    <div>
        <PageHeader title="Account Groups" description="Top-level categories that organise your chart of accounts. Each group sits under an account head and can be split into subgroups.">
            <Button v-if="can('accounts.create')" variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New group
            </Button>
        </PageHeader>

        <Card variant="panel">
            <DataTable :columns="columns" :data="groups" :page-size="10" empty-message="No account groups yet. Use New group to create your first one." />
        </Card>

        <Modal :open="showModal" :title="editing ? 'Edit Account Group' : 'New Account Group'" @update:open="onModalOpenChange">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <label for="account_head_id" class="block text-sm font-semibold text-text-base">Account head <span class="text-danger">*</span></label>
                        <InfoTip text="The main statement section (Assets, Liabilities, Income, Expense, Equity) this group belongs to." />
                    </div>
                    <Select
                        id="account_head_id"
                        v-model="form.account_head_id"
                        :options="headOptions"
                        placeholder="Select account head"
                    />
                    <p v-if="form.errors.account_head_id" class="mt-1 text-sm text-danger">{{ form.errors.account_head_id }}</p>
                </div>

                <div>
                    <div class="mb-1 flex items-center gap-1">
                        <label for="name" class="block text-sm font-semibold text-text-base">Name <span class="text-danger">*</span></label>
                        <InfoTip text="Account group is the broadest level; subgroups and individual accounts go beneath it." />
                    </div>
                    <Input id="name" v-model="form.name" type="text" placeholder="e.g. Current Assets" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" :disabled="form.processing" @click="submit">
                    {{ editing ? 'Save changes' : 'Create group' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
