<script setup>
import { computed, h, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Modal from '@/components/ui/Modal.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import RowActions from '@/components/ui/RowActions.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
    subcategories: {
        type: Array,
        default: () => [],
    },
    items: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();

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

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');

const navItems = computed(() => navGroups(isAdmin.value));

const categoryOptions = computed(() => props.categories.map((category) => ({ value: category.id, label: category.name })));

const showModal = ref(false);
const editing = ref(null);

const importModalOpen = ref(false);
const importForm = useForm({ file: null });
const importResult = ref(null);

// Same flash-watch reasoning as flash.status above: the import submit
// redirects back to this same route + component instead of navigating away.
watch(
    () => page.props.flash?.importResult,
    (result) => {
        if (result) importResult.value = result;
    },
);

function openImport() {
    importForm.reset();
    importForm.clearErrors();
    importResult.value = null;
    importModalOpen.value = true;
}

function closeImportModal() {
    importModalOpen.value = false;
    importForm.reset();
    importForm.clearErrors();
    importResult.value = null;
}

function onImportModalOpenChange(value) {
    if (value) {
        importModalOpen.value = true;
    } else {
        closeImportModal();
    }
}

function onImportFileChange(event) {
    importForm.file = event.target.files[0] ?? null;
}

function submitImport() {
    importForm.post('/items/import', { forceFormData: true });
}

const form = useForm({
    item_category_id: '',
    item_subcategory_id: '',
    name: '',
    description: '',
    unit: '',
    hs_code: '',
    barcode: '',
    min_stock: '',
    expiry_date: '',
    purchase_rate: '',
    sale_rate: '',
    image: null,
    is_vatable: false,
    is_stockable: true,
    is_active: true,
});

form.transform((data) => ({
    ...data,
    item_subcategory_id: data.item_subcategory_id === '' ? null : data.item_subcategory_id,
    description: data.description === '' ? null : data.description,
    hs_code: data.hs_code === '' ? null : data.hs_code,
    barcode: data.barcode === '' ? null : data.barcode,
    min_stock: data.min_stock === '' ? null : data.min_stock,
    expiry_date: data.expiry_date === '' ? null : data.expiry_date,
    purchase_rate: data.purchase_rate === '' ? null : data.purchase_rate,
    sale_rate: data.sale_rate === '' ? null : data.sale_rate,
}));

// Local-only preview for the image picker - shows the item's existing
// image when editing, or a fresh blob preview once a new file is chosen.
// Not part of `form` since the file input itself can't be pre-filled from
// an existing image_path (browsers refuse to set <input type="file">
// programmatically), so this is tracked separately.
const imagePreviewUrl = ref('');

function onImageChange(event) {
    const file = event.target.files?.[0] ?? null;
    form.image = file;
    imagePreviewUrl.value = file ? URL.createObjectURL(file) : '';
}

const subcategoryOptions = computed(() => {
    const filtered = props.subcategories.filter((subcategory) => subcategory.item_category_id === form.item_category_id);
    return [{ value: '', label: 'None' }, ...filtered.map((subcategory) => ({ value: subcategory.id, label: subcategory.name }))];
});

function onCategoryChange(value) {
    form.item_category_id = value;
    form.item_subcategory_id = '';
}

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

function openEdit(item) {
    editing.value = item;
    form.clearErrors();
    form.item_category_id = item.item_category_id;
    form.item_subcategory_id = item.item_subcategory_id ?? '';
    form.name = item.name;
    form.description = item.description ?? '';
    form.unit = item.unit;
    form.hs_code = item.hs_code ?? '';
    form.barcode = item.barcode ?? '';
    form.min_stock = item.min_stock ?? '';
    // item.expiry_date comes back from the server as a full ISO datetime
    // string (Eloquent's default 'date'-cast serialization), not the plain
    // "YYYY-MM-DD" NepaliDateInput/adToBs() require - truncate to the date
    // portion so editing an item with an existing expiry date doesn't
    // silently blank out the BS fields.
    form.expiry_date = item.expiry_date ? item.expiry_date.slice(0, 10) : '';
    form.purchase_rate = item.purchase_rate ?? '';
    form.sale_rate = item.sale_rate ?? '';
    form.image = null;
    imagePreviewUrl.value = item.image_path ? `/storage/${item.image_path}` : '';
    form.is_vatable = !!item.is_vatable;
    form.is_stockable = !!item.is_stockable;
    form.is_active = !!item.is_active;
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    editing.value = null;
    form.reset();
    form.clearErrors();
    imagePreviewUrl.value = '';
}

function onModalOpenChange(value) {
    if (!value) closeModal();
}

function submit() {
    if (editing.value) {
        form.put(`/items/${editing.value.id}`, { onSuccess: closeModal });
    } else {
        form.post('/items', { onSuccess: closeModal });
    }
}

async function destroy(item) {
    if (!(await confirm({ message: 'Delete this item?', tone: 'danger', confirmLabel: 'Delete' }))) return;
    router.delete(`/items/${item.id}`);
}

// Client-side only - mirrors Item::scopeExpired()/scopeExpiringSoon()'s
// boundary rule (an item expiring exactly today counts as expired, not
// expiring soon) using plain "YYYY-MM-DD" string comparison, which sorts
// correctly the same way whereDate() does server-side. item.expiry_date may
// be null (most items won't have one) or a full ISO datetime string, hence
// the slice(0, 10).
const EXPIRING_SOON_WITHIN_DAYS = 30;

function expiryStatus(item) {
    if (!item.expiry_date) return null;

    const expiry = item.expiry_date.slice(0, 10);
    const today = new Date().toISOString().slice(0, 10);
    if (expiry <= today) return 'expired';

    const soonUntil = new Date();
    soonUntil.setDate(soonUntil.getDate() + EXPIRING_SOON_WITHIN_DAYS);
    if (expiry <= soonUntil.toISOString().slice(0, 10)) return 'soon';

    return null;
}

const columns = [
    {
        id: 'image',
        header: '',
        numeric: false,
        cell: ({ row }) =>
            row.original.image_path
                ? h('img', {
                      src: `/storage/${row.original.image_path}`,
                      alt: row.original.name,
                      class: 'h-8 w-8 border-[1.5px] border-border object-cover',
                  })
                : h('div', { class: 'h-8 w-8 border-[1.5px] border-dashed border-border' }),
    },
    { accessorKey: 'name', header: 'Name' },
    {
        id: 'category',
        header: 'Category',
        numeric: false,
        cell: ({ row }) => row.original.category.name,
    },
    {
        id: 'subcategory',
        header: 'Subcategory',
        numeric: false,
        cell: ({ row }) => row.original.subcategory?.name ?? '—',
    },
    { accessorKey: 'unit', header: 'Unit', numeric: false },
    {
        id: 'purchase_rate',
        header: 'Purchase rate',
        numeric: true,
        cell: ({ row }) => (row.original.purchase_rate != null ? Number(row.original.purchase_rate).toFixed(2) : '—'),
    },
    {
        id: 'sale_rate',
        header: 'Sale rate',
        numeric: true,
        cell: ({ row }) => (row.original.sale_rate != null ? Number(row.original.sale_rate).toFixed(2) : '—'),
    },
    {
        id: 'expiry',
        header: 'Expiry',
        numeric: false,
        cell: ({ row }) => {
            const status = expiryStatus(row.original);
            if (status === 'expired') return h(Badge, { variant: 'danger', pill: true }, () => 'Expired');
            if (status === 'soon') return h(Badge, { variant: 'warning', pill: true }, () => 'Expiring soon');
            return '—';
        },
    },
    {
        id: 'vatable',
        header: 'Vatable',
        numeric: false,
        cell: ({ row }) =>
            h(Badge, { variant: row.original.is_vatable ? 'success' : 'neutral', pill: true }, () =>
                row.original.is_vatable ? 'Vatable' : 'Not vatable',
            ),
    },
    {
        id: 'active',
        header: 'Active',
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
    <AppLayout title="Items" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Items</h2>
            <div class="flex items-center gap-2">
                <Button variant="secondary" tone="purple" @click="openImport">Bulk import</Button>
                <Button variant="primary" tone="purple" @click="openCreate">New item</Button>
            </div>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="items" :page-size="10" />
        </Card>

        <Modal :open="showModal" :title="editing ? 'Edit item' : 'New item'" @update:open="onModalOpenChange">
            <form id="item-form" class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="item_category_id" class="mb-1 block text-sm font-semibold text-text-base">Category <span class="text-danger">*</span></label>
                        <Select
                            id="item_category_id"
                            :model-value="form.item_category_id"
                            :options="categoryOptions"
                            placeholder="Select category"
                            @update:model-value="onCategoryChange"
                        />
                        <p v-if="form.errors.item_category_id" class="mt-1 text-sm text-danger">{{ form.errors.item_category_id }}</p>
                    </div>

                    <div>
                        <label for="item_subcategory_id" class="mb-1 block text-sm font-semibold text-text-base">Subcategory</label>
                        <Select
                            id="item_subcategory_id"
                            v-model="form.item_subcategory_id"
                            :options="subcategoryOptions"
                            placeholder="Select subcategory"
                        />
                        <p v-if="form.errors.item_subcategory_id" class="mt-1 text-sm text-danger">{{ form.errors.item_subcategory_id }}</p>
                    </div>
                </div>

                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Name <span class="text-danger">*</span></label>
                    <Input id="name" v-model="form.name" type="text" placeholder="Enter item name" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="description" class="mb-1 block text-sm font-semibold text-text-base">Description</label>
                    <textarea
                        id="description"
                        v-model="form.description"
                        rows="3"
                        placeholder="Optional notes"
                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base transition-colors duration-150 outline-none placeholder:text-text-faint focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                    ></textarea>
                    <p v-if="form.errors.description" class="mt-1 text-sm text-danger">{{ form.errors.description }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="unit" class="mb-1 block text-sm font-semibold text-text-base">Unit <span class="text-danger">*</span></label>
                        <Input id="unit" v-model="form.unit" type="text" placeholder="pcs" required />
                        <p v-if="form.errors.unit" class="mt-1 text-sm text-danger">{{ form.errors.unit }}</p>
                    </div>

                    <div>
                        <label for="hs_code" class="mb-1 block text-sm font-semibold text-text-base">HS code</label>
                        <Input id="hs_code" v-model="form.hs_code" type="text" placeholder="e.g. 8471.30" />
                        <p v-if="form.errors.hs_code" class="mt-1 text-sm text-danger">{{ form.errors.hs_code }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="barcode" class="mb-1 block text-sm font-semibold text-text-base">Barcode</label>
                        <Input id="barcode" v-model="form.barcode" type="text" placeholder="Scan or type a barcode" />
                        <p v-if="form.errors.barcode" class="mt-1 text-sm text-danger">{{ form.errors.barcode }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="min_stock" class="mb-1 block text-sm font-semibold text-text-base">Minimum stock</label>
                        <Input id="min_stock" v-model="form.min_stock" type="number" step="0.01" placeholder="e.g. 10" class="max-w-[160px]" />
                        <p v-if="form.errors.min_stock" class="mt-1 text-sm text-danger">{{ form.errors.min_stock }}</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Expiry date</label>
                        <NepaliDateInput v-model="form.expiry_date" />
                        <p v-if="form.errors.expiry_date" class="mt-1 text-sm text-danger">{{ form.errors.expiry_date }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="purchase_rate" class="mb-1 block text-sm font-semibold text-text-base">Purchase rate</label>
                        <Input id="purchase_rate" v-model="form.purchase_rate" type="number" step="0.01" min="0" placeholder="0.00" />
                        <p v-if="form.errors.purchase_rate" class="mt-1 text-sm text-danger">{{ form.errors.purchase_rate }}</p>
                    </div>

                    <div>
                        <label for="sale_rate" class="mb-1 block text-sm font-semibold text-text-base">Sale rate</label>
                        <Input id="sale_rate" v-model="form.sale_rate" type="number" step="0.01" min="0" placeholder="0.00" />
                        <p v-if="form.errors.sale_rate" class="mt-1 text-sm text-danger">{{ form.errors.sale_rate }}</p>
                    </div>
                </div>

                <div>
                    <label for="image" class="mb-1 block text-sm font-semibold text-text-base">Item image</label>
                    <div class="flex items-center gap-3">
                        <img
                            v-if="imagePreviewUrl"
                            :src="imagePreviewUrl"
                            alt="Item image preview"
                            class="h-16 w-16 border-[1.5px] border-border object-cover"
                        />
                        <input
                            id="image"
                            type="file"
                            accept="image/*"
                            class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base transition-colors duration-150 outline-none file:mr-3 file:border-0 file:bg-transparent file:text-[13px] file:font-semibold file:text-primary focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                            @change="onImageChange"
                        />
                    </div>
                    <p class="mt-1 text-xs text-text-faint">JPEG, PNG, or WebP up to 2MB.</p>
                    <p v-if="form.errors.image" class="mt-1 text-sm text-danger">{{ form.errors.image }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex items-center gap-2">
                        <input id="is_vatable" v-model="form.is_vatable" type="checkbox" class="size-4 border-[1.5px] border-border" />
                        <label for="is_vatable" class="text-sm font-semibold text-text-base">Vatable</label>
                    </div>

                    <div class="flex items-center gap-2">
                        <input id="is_stockable" v-model="form.is_stockable" type="checkbox" class="size-4 border-[1.5px] border-border" />
                        <label for="is_stockable" class="text-sm font-semibold text-text-base">Stockable</label>
                    </div>

                    <div class="flex items-center gap-2">
                        <input id="is_active" v-model="form.is_active" type="checkbox" class="size-4 border-[1.5px] border-border" />
                        <label for="is_active" class="text-sm font-semibold text-text-base">Active</label>
                    </div>
                </div>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" form="item-form" :disabled="form.processing">
                    {{ editing ? 'Save changes' : 'Create item' }}
                </Button>
            </template>
        </Modal>

        <Modal :open="importModalOpen" title="Bulk import items" @update:open="onImportModalOpenChange">
            <div v-if="!importResult" class="flex flex-col gap-4">
                <p class="text-[13px] text-text-muted">
                    Download the template, fill in one item per row (category/subcategory are matched by name),
                    then upload the completed CSV file. Rows with a missing name/unit, an unknown category or
                    subcategory, or a barcode already in use (or repeated in the file) are skipped and reported
                    after import.
                </p>
                <a
                    href="/items/import/template"
                    class="inline-flex w-fit items-center gap-1.5 text-[13px] font-bold text-primary hover:underline"
                >
                    Download CSV template
                </a>
                <div>
                    <label for="item-import-file" class="mb-1 block text-sm font-semibold text-text-base">
                        CSV file <span class="text-danger">*</span>
                    </label>
                    <input
                        id="item-import-file"
                        type="file"
                        accept=".csv,text/csv"
                        class="w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base outline-none file:mr-3 file:cursor-pointer file:border-0 file:bg-primary-tint file:px-3 file:py-1.5 file:text-[12px] file:font-bold file:text-primary"
                        @change="onImportFileChange"
                    />
                    <p v-if="importForm.errors.file" class="mt-1 text-sm text-danger">{{ importForm.errors.file }}</p>
                </div>
            </div>

            <div v-else class="flex flex-col gap-4">
                <p class="text-[13px] font-semibold text-text-base">
                    Imported {{ importResult.imported }} of {{ importResult.imported + importResult.skipped.length }} row(s).
                </p>
                <div v-if="importResult.skipped.length" class="max-h-64 overflow-auto border-[1.5px] border-border">
                    <table class="w-full text-left text-[12px]">
                        <thead class="bg-bg-subtle">
                            <tr>
                                <th class="px-2 py-1.5">Row</th>
                                <th class="px-2 py-1.5">Name</th>
                                <th class="px-2 py-1.5">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in importResult.skipped" :key="item.row" class="border-t border-border">
                                <td class="px-2 py-1.5">{{ item.row }}</td>
                                <td class="px-2 py-1.5">{{ item.name || '—' }}</td>
                                <td class="px-2 py-1.5">{{ item.reason }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <template #footer>
                <template v-if="!importResult">
                    <Button variant="secondary" tone="purple" type="button" @click="closeImportModal">Cancel</Button>
                    <Button
                        variant="primary"
                        tone="purple"
                        type="button"
                        :loading="importForm.processing"
                        :disabled="importForm.processing || !importForm.file"
                        @click="submitImport"
                    >
                        Import
                    </Button>
                </template>
                <template v-else>
                    <Button variant="primary" tone="purple" type="button" @click="closeImportModal">Done</Button>
                </template>
            </template>
        </Modal>
    </AppLayout>
</template>
