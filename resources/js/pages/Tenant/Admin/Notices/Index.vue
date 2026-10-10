<script setup>
import { h, ref, watch } from 'vue';
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
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import RowActions from '@/components/ui/RowActions.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';

defineOptions({ layout: AppLayout });

defineProps({
    notices: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
useLayoutChrome('Notices');

// Create/edit/delete all redirect back to this same route/component -
// Inertia patches the already-mounted instance rather than remounting it,
// so watching the flash prop (not a one-time onMounted) catches every
// in-place action, not just the very first page load.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    title: '',
    body: '',
    starts_at: '',
    ends_at: '',
    is_active: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

function openEdit(notice) {
    editing.value = notice;
    form.clearErrors();
    form.title = notice.title;
    form.body = notice.body;
    form.starts_at = notice.starts_at ?? '';
    form.ends_at = notice.ends_at ?? '';
    form.is_active = !!notice.is_active;
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
        form.put(`/notices/${editing.value.id}`, { onSuccess: closeModal });
    } else {
        form.post('/notices', { onSuccess: closeModal });
    }
}

async function destroy(notice) {
    if (!(await confirm({ title: 'Delete notice?', message: `"${notice.title}" will no longer be shown to any user. This cannot be undone.`, tone: 'danger', confirmLabel: 'Delete notice' }))) return;
    router.delete(`/notices/${notice.id}`);
}

const columns = [
    { accessorKey: 'title', header: 'Title' },
    {
        id: 'window',
        header: 'Active window',
        numeric: false,
        cell: ({ row }) => (row.original.starts_at ?? '-') + ' → ' + (row.original.ends_at ?? '-'),
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
    <div>
        <PageHeader title="Notices" description="Announcements shown to all users of your company.">
            <Button variant="primary" tone="purple" @click="openCreate">
                <Plus class="size-4" />
                New notice
            </Button>
        </PageHeader>

        <Card variant="panel" class="bg-white">
            <div v-if="notices.length === 0" class="flex flex-col items-center gap-3 py-10 text-center">
                <p class="text-sm font-semibold text-text-strong">No notices yet</p>
                <p class="text-xs text-text-muted">Create your first notice and it will be listed here.</p>
                <Button variant="primary" tone="purple" @click="openCreate">
                    <Plus class="size-4" />
                    New notice
                </Button>
            </div>
            <DataTable v-else :columns="columns" :data="notices" :page-size="10" />
        </Card>

        <Modal
            :open="showModal"
            :title="editing ? 'Edit notice' : 'New notice'"
            @update:open="onModalOpenChange"
        >
            <form id="notice-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <Label for="title" class="mb-1">Title <span class="text-danger">*</span></Label>
                    <Input id="title" v-model="form.title" type="text" placeholder="e.g. Holiday hours" required />
                    <p v-if="form.errors.title" class="mt-1 text-sm text-danger">{{ form.errors.title }}</p>
                </div>

                <div>
                    <Label for="body" class="mb-1">Body <span class="text-danger">*</span></Label>
                    <textarea
                        id="body"
                        v-model="form.body"
                        rows="4"
                        placeholder="Write the notice message..."
                        required
                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base outline-none transition-colors duration-150 focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                    ></textarea>
                    <p v-if="form.errors.body" class="mt-1 text-sm text-danger">{{ form.errors.body }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <div class="mb-1 flex items-center gap-1">
                            <Label for="starts_at">Starts on</Label>
                            <InfoTip text="Optional. The notice is active immediately if left blank." />
                        </div>
                        <NepaliDateInput id="starts_at" v-model="form.starts_at" />
                        <p v-if="form.errors.starts_at" class="mt-1 text-sm text-danger">{{ form.errors.starts_at }}</p>
                    </div>

                    <div>
                        <div class="mb-1 flex items-center gap-1">
                            <Label for="ends_at">Ends on</Label>
                            <InfoTip text="Optional. The notice never expires if left blank." />
                        </div>
                        <NepaliDateInput id="ends_at" v-model="form.ends_at" />
                        <p v-if="form.errors.ends_at" class="mt-1 text-sm text-danger">{{ form.errors.ends_at }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <input id="is_active" v-model="form.is_active" type="checkbox" class="size-4 border-[1.5px] border-border" />
                    <label for="is_active" class="text-sm font-semibold text-text-base">Active</label>
                    <InfoTip text="Inactive notices are not shown to users." />
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="neutral" type="button" @click="closeModal">Cancel</Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="submit"
                    form="notice-form"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving...' : editing ? 'Save notice' : 'Create notice' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
