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
import RowActions from '@/components/ui/RowActions.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';

defineOptions({ layout: AppLayout });

defineProps({
    brands: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
useLayoutChrome('Brands');

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

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    name: '',
    logo: null,
    is_active: true,
});

// Local-only preview for the logo picker - same reasoning as Items/Index.vue's
// imagePreviewUrl: a file input can't be pre-filled from an existing
// logo_path, so this is tracked separately from `form`.
const logoPreviewUrl = ref('');

function onLogoChange(event) {
    const file = event.target.files?.[0] ?? null;
    form.logo = file;
    logoPreviewUrl.value = file ? URL.createObjectURL(file) : '';
}

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    logoPreviewUrl.value = '';
    showModal.value = true;
}

function openEdit(brand) {
    editing.value = brand;
    form.clearErrors();
    form.name = brand.name;
    form.logo = null;
    form.is_active = !!brand.is_active;
    logoPreviewUrl.value = brand.logo_path ? `/storage/${brand.logo_path}` : '';
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
    logoPreviewUrl.value = '';
}

function onModalOpenChange(value) {
    if (!value) closeModal();
}

function submit() {
    if (editing.value) {
        form.put(`/brands/${editing.value.id}`, { onSuccess: closeModal });
    } else {
        form.post('/brands', { onSuccess: closeModal });
    }
}

async function destroy(brand) {
    if (!(await confirm({ message: `Delete brand "${brand.name}"? Items using it will lose their brand. This cannot be undone.`, tone: 'danger', confirmLabel: 'Delete brand' }))) return;
    router.delete(`/brands/${brand.id}`);
}

const columns = [
    {
        id: 'logo',
        header: '',
        numeric: false,
        cell: ({ row }) =>
            row.original.logo_path
                ? h('img', {
                      src: `/storage/${row.original.logo_path}`,
                      alt: row.original.name,
                      class: 'h-8 w-8 border-[1.5px] border-border object-cover',
                  })
                : h('div', { class: 'h-8 w-8 border-[1.5px] border-dashed border-border' }),
    },
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
            h(RowActions, {
                onEdit: () => openEdit(row.original),
                onDelete: () => destroy(row.original),
            }),
    },
];
</script>

<template>
    <div>
        <PageHeader title="Brands" description="Brands: manufacturers or labels your items belong to, used to group and filter items.">
            <Button variant="primary" tone="purple" @click="openCreate">New brand</Button>
        </PageHeader>

        <Card variant="panel">
            <DataTable :columns="columns" :data="brands" :page-size="10" />
        </Card>

        <Modal
            :open="showModal"
            :title="editing ? 'Edit brand' : 'New brand'"
            size="compact"
            @update:open="onModalOpenChange"
        >
            <form id="brand-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name <span class="text-danger">*</span></label>
                    <Input id="name" v-model="form.name" type="text" placeholder="e.g. Unilever" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="logo" class="mb-1 block text-sm font-semibold text-text-base">Logo</label>
                    <div class="flex items-center gap-3">
                        <img
                            v-if="logoPreviewUrl"
                            :src="logoPreviewUrl"
                            alt="Brand logo preview"
                            class="h-16 w-16 border-[1.5px] border-border object-cover"
                        />
                        <input
                            id="logo"
                            type="file"
                            accept="image/*"
                            class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base transition-colors duration-150 outline-none file:mr-3 file:border-0 file:bg-transparent file:text-[13px] file:font-semibold file:text-primary focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                            @change="onLogoChange"
                        />
                    </div>
                    <p class="mt-1 text-xs text-text-faint">JPEG, PNG, or WebP up to 2MB.</p>
                    <p v-if="form.errors.logo" class="mt-1 text-sm text-danger">{{ form.errors.logo }}</p>
                </div>

                <div class="flex items-center gap-2">
                    <input id="is_active" v-model="form.is_active" type="checkbox" class="size-4 border-[1.5px] border-border" />
                    <label for="is_active" class="text-sm font-semibold text-text-base">Active</label>
                    <span class="text-xs text-text-faint">(inactive brands are hidden from item forms)</span>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
                <Button
                    variant="primary"
                    tone="purple"
                    type="submit"
                    form="brand-form"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving...' : editing ? 'Save brand' : 'Create brand' }}
                </Button>
            </template>
        </Modal>
    </div>
</template>
