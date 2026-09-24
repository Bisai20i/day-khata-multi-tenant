<script setup>
import { h, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
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
            <Button variant="primary" tone="purple" @click="openCreate">New notice</Button>
        </PageHeader>

        <Card variant="panel">
            <DataTable :columns="columns" :data="notices" :page-size="10" />
        </Card>

        <Modal
            :open="showModal"
            :title="editing ? 'Edit notice' : 'New notice'"
            @update:open="onModalOpenChange"
        >
            <form id="notice-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="title" class="mb-1 block text-sm font-semibold text-text-base">Title <span class="text-danger">*</span></label>
                    <Input id="title" v-model="form.title" type="text" placeholder="e.g. Holiday hours" required />
                    <p v-if="form.errors.title" class="mt-1 text-sm text-danger">{{ form.errors.title }}</p>
                </div>

                <div>
                    <label for="body" class="mb-1 block text-sm font-semibold text-text-base">Body <span class="text-danger">*</span></label>
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
                        <label for="starts_at" class="mb-1 block text-sm font-semibold text-text-base">
                            Starts on <span class="font-normal text-text-muted">(optional - active immediately if blank)</span>
                        </label>
                        <NepaliDateInput id="starts_at" v-model="form.starts_at" />
                        <p v-if="form.errors.starts_at" class="mt-1 text-sm text-danger">{{ form.errors.starts_at }}</p>
                    </div>

                    <div>
                        <label for="ends_at" class="mb-1 block text-sm font-semibold text-text-base">
                            Ends on <span class="font-normal text-text-muted">(optional - never expires if blank)</span>
                        </label>
                        <NepaliDateInput id="ends_at" v-model="form.ends_at" />
                        <p v-if="form.errors.ends_at" class="mt-1 text-sm text-danger">{{ form.errors.ends_at }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <input id="is_active" v-model="form.is_active" type="checkbox" class="size-4 border-[1.5px] border-border" />
                    <label for="is_active" class="text-sm font-semibold text-text-base">Active</label>
                    <span class="text-xs text-text-faint">(inactive notices are not shown to users)</span>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
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
