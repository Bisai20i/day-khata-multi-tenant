<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { formatQuantity } from '@/lib/money.js';

const props = defineProps({
    categories: {
        type: Array,
        default: () => [],
    },
    subcategories: {
        type: Array,
        default: () => [],
    },
    brands: {
        type: Array,
        default: () => [],
    },
    // Exact decimal strings keyed by item id, never numbers.
    stockByItem: {
        type: Object,
        default: () => ({}),
    },
    // Expense and Fixed Asset accounts an item may post its purchases to.
    postingAccounts: {
        type: Array,
        default: () => [],
    },
});

const categoryOptions = computed(() => props.categories.map((category) => ({ value: category.id, label: category.name })));

const brandOptions = computed(() => [
    { value: '', label: 'None' },
    ...props.brands.map((brand) => ({ value: brand.id, label: brand.name })),
]);

const postingAccountOptions = computed(() => [
    { value: '', label: 'Default (Purchases Account)' },
    ...props.postingAccounts.map((account) => ({ value: account.id, label: account.label })),
]);

// "0.0000" when the item has never moved. Kept as a string all the way to
// the formatter: this is a quantity, so it never goes through Number().
function stockOnHand(item) {
    return props.stockByItem[item.id] ?? '0.0000';
}

function hasStock(item) {
    const onHand = stockOnHand(item);
    return onHand !== '0.0000' && onHand !== '0';
}

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    item_category_id: '',
    item_subcategory_id: '',
    brand_id: '',
    account_id: '',
    name: '',
    description: '',
    unit: '',
    hs_code: '',
    barcode: '',
    min_stock: '',
    expiry_date: '',
    purchase_rate: '',
    sale_rate: '',
    mrp: '',
    image: null,
    is_vatable: false,
    is_stockable: true,
    is_active: true,
});

form.transform((data) => ({
    ...data,
    item_subcategory_id: data.item_subcategory_id === '' ? null : data.item_subcategory_id,
    brand_id: data.brand_id === '' ? null : data.brand_id,
    account_id: data.account_id === '' ? null : data.account_id,
    description: data.description === '' ? null : data.description,
    hs_code: data.hs_code === '' ? null : data.hs_code,
    barcode: data.barcode === '' ? null : data.barcode,
    min_stock: data.min_stock === '' ? null : data.min_stock,
    expiry_date: data.expiry_date === '' ? null : data.expiry_date,
    purchase_rate: data.purchase_rate === '' ? null : data.purchase_rate,
    sale_rate: data.sale_rate === '' ? null : data.sale_rate,
    mrp: data.mrp === '' ? null : data.mrp,
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
    form.brand_id = item.brand_id ?? '';
    form.account_id = item.account_id ?? '';
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
    form.mrp = item.mrp ?? '';
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

defineExpose({ openCreate, openEdit });
</script>

<template>
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
                        <p class="mt-1 text-xs text-text-faint">Base unit stock is counted in, e.g. pcs, kg, litre. Add bigger units (box, carton) later with "Units".</p>
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

                    <div>
                        <label for="brand_id" class="mb-1 block text-sm font-semibold text-text-base">Brand</label>
                        <Combobox
                            id="brand_id"
                            :model-value="form.brand_id"
                            :options="brandOptions"
                            placeholder="Select brand"
                            @update:model-value="(v) => (form.brand_id = v)"
                        />
                        <p v-if="form.errors.brand_id" class="mt-1 text-sm text-danger">{{ form.errors.brand_id }}</p>
                    </div>
                </div>

                <div>
                    <label for="account_id" class="mb-1 block text-sm font-semibold text-text-base">Posting account</label>
                    <Combobox
                        id="account_id"
                        :model-value="form.account_id"
                        :options="postingAccountOptions"
                        placeholder="Default (Purchases Account)"
                        @update:model-value="(v) => (form.account_id = v)"
                    />
                    <p class="mt-1 text-xs text-text-faint">
                        Where buying this item is posted in the ledger. Leave it on the default for ordinary stock.
                        Pick an expense account for a service item, or a fixed asset account for a capital item.
                    </p>
                    <p v-if="form.errors.account_id" class="mt-1 text-sm text-danger">{{ form.errors.account_id }}</p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="min_stock" class="mb-1 block text-sm font-semibold text-text-base">Reorder level (minimum stock)</label>
                        <Input id="min_stock" v-model="form.min_stock" type="number" step="0.01" placeholder="e.g. 10" class="max-w-[160px]" />
                        <p class="mt-1 text-xs text-text-faint">Reorder level: the item is flagged "Low stock" when quantity falls to this or below.</p>
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

                    <div>
                        <label for="mrp" class="mb-1 block text-sm font-semibold text-text-base">MRP (base unit)</label>
                        <Input id="mrp" v-model="form.mrp" type="number" step="0.01" min="0" placeholder="Optional" />
                        <p v-if="form.errors.mrp" class="mt-1 text-sm text-danger">{{ form.errors.mrp }}</p>
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

                <p v-if="editing && hasStock(editing) && !form.is_active" class="border-[1.5px] border-warning-text bg-warning-bg px-3 py-2 text-sm text-warning-text">
                    This item still has {{ formatQuantity(stockOnHand(editing)) }} {{ editing.unit }} in stock. An inactive item
                    disappears from every picker, so that stock could never be sold, adjusted or transferred out. Clear the stock
                    first.
                </p>
                <p v-if="form.errors.is_active" class="text-sm text-danger">{{ form.errors.is_active }}</p>
            </form>

            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
                <Button variant="primary" tone="purple" type="submit" form="item-form" :disabled="form.processing">
                    {{ form.processing ? 'Saving...' : editing ? 'Save item' : 'Create item' }}
                </Button>
            </template>
        </Modal>
</template>
