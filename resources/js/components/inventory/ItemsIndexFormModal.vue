<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { ChevronDown, Plus, ScanBarcode } from '@lucide/vue';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import ModalSection from '@/components/ui/ModalSection.vue';
import FormField from '@/components/ui/FormField.vue';
import CheckboxField from '@/components/ui/CheckboxField.vue';
import QuickAddModal from '@/components/ui/QuickAddModal.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import { useCrudModal } from '@/composables/useCrudModal';
import { usePermissions } from '@/composables/usePermissions';
import { generateItemBarcode } from '@/lib/barcode.js';
import { findCreatedByName, hasAnyError, hasAnyValue } from '@/lib/formModal.js';
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

const { can } = usePermissions();

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
const imageInput = ref(null);

function onImageChange(event) {
    const file = event.target.files?.[0] ?? null;
    form.image = file;
    imagePreviewUrl.value = file ? URL.createObjectURL(file) : '';
}

// The rarely-used fields sit behind "More options" so the everyday ones fit
// on one screen. It opens by itself whenever something in it needs attention:
// an item being edited already has a value there, or the server rejected one.
const MORE_OPTION_FIELDS = ['hs_code', 'account_id', 'expiry_date', 'description', 'image'];
const showMoreOptions = ref(false);

watch(
    () => hasAnyError(form.errors, MORE_OPTION_FIELDS),
    (hasError) => {
        if (hasError) showMoreOptions.value = true;
    },
);

const { showModal, editing, addAnother, savedNotice, isDirty, openCreate, openEdit, closeModal, onModalOpenChange, submit } = useCrudModal({
    form,
    url: '/items',
    formId: 'item-form',
    fill: (item) => {
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
        showMoreOptions.value = hasAnyValue(item, [...MORE_OPTION_FIELDS, 'image_path']);
    },
    // Adding items one after another is usually a run through one shelf, so
    // the next blank form keeps where the last item was filed and how it is
    // counted and taxed.
    createDefaults: (lastCreated, { addingAnother }) =>
        addingAnother
            ? {
                  item_category_id: lastCreated.item_category_id,
                  item_subcategory_id: lastCreated.item_subcategory_id,
                  brand_id: lastCreated.brand_id,
                  unit: lastCreated.unit,
                  is_vatable: lastCreated.is_vatable,
                  is_stockable: lastCreated.is_stockable,
              }
            : {},
    onReset: () => {
        imagePreviewUrl.value = '';
        showMoreOptions.value = false;
        if (imageInput.value) imageInput.value.value = '';
    },
});

const subcategoryOptions = computed(() => {
    const filtered = props.subcategories.filter((subcategory) => subcategory.item_category_id === form.item_category_id);
    return [{ value: '', label: 'None' }, ...filtered.map((subcategory) => ({ value: subcategory.id, label: subcategory.name }))];
});

const selectedCategoryName = computed(() => props.categories.find((category) => category.id === form.item_category_id)?.name ?? '');

function onCategoryChange(value) {
    form.item_category_id = value;
    form.item_subcategory_id = '';
}

// Inline "+ New" for the three pickers: which one is open, or null.
const quickAdd = ref(null);

const quickAddConfigs = computed(() => ({
    category: { title: 'New category', url: '/item-categories', placeholder: 'e.g. Beverages', description: '', payload: {} },
    subcategory: {
        title: 'New subcategory',
        url: '/item-subcategories',
        placeholder: 'e.g. Soft drinks',
        description: `Added under ${selectedCategoryName.value}.`,
        payload: { item_category_id: form.item_category_id },
    },
    brand: { title: 'New brand', url: '/brands', placeholder: 'e.g. Unilever', description: '', payload: {} },
}));

const activeQuickAdd = computed(() => (quickAdd.value ? quickAddConfigs.value[quickAdd.value] : null));

function onQuickAddOpenChange(open) {
    if (!open) quickAdd.value = null;
}

function onQuickAddCreated(name) {
    if (quickAdd.value === 'category') {
        const category = findCreatedByName(props.categories, name);
        if (category) onCategoryChange(category.id);
    } else if (quickAdd.value === 'subcategory') {
        const subcategory = findCreatedByName(props.subcategories, name, { item_category_id: form.item_category_id });
        if (subcategory) form.item_subcategory_id = subcategory.id;
    } else if (quickAdd.value === 'brand') {
        const brand = findCreatedByName(props.brands, name);
        if (brand) form.brand_id = brand.id;
    }
}

const quickAddButtonClass = 'flex items-center gap-1 text-[12px] font-bold text-text-muted hover:text-primary';
const textareaClass =
    'w-full border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base transition-colors duration-150 outline-none placeholder:text-text-faint focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)] aria-invalid:border-danger';

// Built in the browser from the category and the moment it was made - see
// lib/barcode.js for the layout.
function generateBarcode() {
    if (!form.item_category_id) {
        form.setError('barcode', 'Choose a category first, the barcode is built from it.');
        return;
    }

    form.clearErrors('barcode');
    form.barcode = generateItemBarcode(form.item_category_id);
}

function invalid(field) {
    return form.errors[field] ? 'true' : undefined;
}

defineExpose({ openCreate, openEdit });
</script>

<template>
    <Modal
        :open="showModal"
        :title="editing ? 'Edit item' : 'New item'"
        :description="editing ? '' : 'Only the name, category and unit are required. Everything else can be filled in later.'"
        size="xl"
        :dirty="isDirty"
        @update:open="onModalOpenChange"
    >
        <form id="item-form" class="flex flex-col gap-6" @submit.prevent="submit">
            <p v-if="savedNotice" class="border-[1.5px] border-success bg-success-bg-soft px-3 py-2 text-[13px] text-success" role="status">
                {{ savedNotice }}
            </p>

            <ModalSection title="Basics">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <FormField v-slot="{ describedBy }" label="Name" for="item-name" required :error="form.errors.name" class="sm:col-span-2">
                        <Input
                            id="item-name"
                            v-model="form.name"
                            type="text"
                            placeholder="e.g. Coca-Cola 500ml"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid('name')"
                            required
                            data-autofocus
                        />
                    </FormField>

                    <FormField
                        v-slot="{ describedBy }"
                        label="Unit"
                        for="item-unit"
                        required
                        help="What stock is counted in: pcs, kg, litre. Add box or carton later with &quot;Units&quot;."
                        :error="form.errors.unit"
                    >
                        <Input
                            id="item-unit"
                            v-model="form.unit"
                            type="text"
                            placeholder="pcs"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid('unit')"
                            required
                        />
                    </FormField>

                    <FormField label="Category" for="item-category" required :error="form.errors.item_category_id">
                        <Combobox
                            id="item-category"
                            :model-value="form.item_category_id"
                            :options="categoryOptions"
                            placeholder="Search or select"
                            @update:model-value="onCategoryChange"
                        >
                            <template v-if="can('item_categories.manage')" #addon>
                                <button type="button" :class="quickAddButtonClass" title="Add a new category" @click="quickAdd = 'category'">
                                    <Plus class="h-3.5 w-3.5" />
                                    New
                                </button>
                            </template>
                        </Combobox>
                    </FormField>

                    <FormField
                        label="Subcategory"
                        for="item-subcategory"
                        :help="form.item_category_id ? '' : 'Choose a category first.'"
                        :error="form.errors.item_subcategory_id"
                    >
                        <Combobox
                            id="item-subcategory"
                            :model-value="form.item_subcategory_id"
                            :options="subcategoryOptions"
                            :disabled="!form.item_category_id"
                            placeholder="None"
                            @update:model-value="(value) => (form.item_subcategory_id = value)"
                        >
                            <template v-if="form.item_category_id && can('item_categories.manage')" #addon>
                                <button type="button" :class="quickAddButtonClass" title="Add a new subcategory" @click="quickAdd = 'subcategory'">
                                    <Plus class="h-3.5 w-3.5" />
                                    New
                                </button>
                            </template>
                        </Combobox>
                    </FormField>

                    <FormField label="Brand" for="item-brand" :error="form.errors.brand_id">
                        <Combobox
                            id="item-brand"
                            :model-value="form.brand_id"
                            :options="brandOptions"
                            placeholder="None"
                            @update:model-value="(value) => (form.brand_id = value)"
                        >
                            <template v-if="can('brands.manage')" #addon>
                                <button type="button" :class="quickAddButtonClass" title="Add a new brand" @click="quickAdd = 'brand'">
                                    <Plus class="h-3.5 w-3.5" />
                                    New
                                </button>
                            </template>
                        </Combobox>
                    </FormField>
                </div>
            </ModalSection>

            <ModalSection title="Pricing" description="Rates are per base unit. They pre-fill bills and can be changed on each one.">
                <template #actions>
                    <CheckboxField id="item-is-vatable" v-model="form.is_vatable" label="Vatable" hint="VAT applies to this item." />
                </template>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <FormField v-slot="{ describedBy }" label="Purchase rate" for="item-purchase-rate" :error="form.errors.purchase_rate">
                        <Input
                            id="item-purchase-rate"
                            v-model="form.purchase_rate"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="0.00"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid('purchase_rate')"
                        />
                    </FormField>

                    <FormField v-slot="{ describedBy }" label="Sale rate" for="item-sale-rate" :error="form.errors.sale_rate">
                        <Input
                            id="item-sale-rate"
                            v-model="form.sale_rate"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="0.00"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid('sale_rate')"
                        />
                    </FormField>

                    <FormField v-slot="{ describedBy }" label="MRP" for="item-mrp" :error="form.errors.mrp">
                        <Input
                            id="item-mrp"
                            v-model="form.mrp"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="Optional"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid('mrp')"
                        />
                    </FormField>
                </div>
            </ModalSection>

            <ModalSection title="Stock">
                <template #actions>
                    <CheckboxField
                        id="item-is-stockable"
                        v-model="form.is_stockable"
                        label="Stockable"
                        hint="Keep a quantity on hand. Untick for services and other things you do not count."
                    />
                </template>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField
                        v-slot="{ describedBy }"
                        label="Reorder level"
                        for="item-min-stock"
                        help="Flagged &quot;Low stock&quot; when the quantity falls to this or below."
                        :error="form.errors.min_stock"
                    >
                        <Input
                            id="item-min-stock"
                            v-model="form.min_stock"
                            type="number"
                            step="0.01"
                            placeholder="e.g. 10"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid('min_stock')"
                        />
                    </FormField>

                    <FormField
                        v-slot="{ describedBy }"
                        label="Barcode"
                        for="item-barcode"
                        help="Scan the code printed on the pack. For an item without one, Generate makes an in-store code from its category and the date and time it was made."
                        :error="form.errors.barcode"
                    >
                        <div class="flex gap-2">
                            <div class="min-w-0 flex-1">
                                <Input
                                    id="item-barcode"
                                    v-model="form.barcode"
                                    type="text"
                                    placeholder="Scan or type a barcode"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid('barcode')"
                                    @keydown.enter.prevent
                                />
                            </div>
                            <Button variant="secondary" tone="purple" type="button" class="shrink-0 px-3" @click="generateBarcode">
                                <ScanBarcode class="h-3.5 w-3.5" />
                                Generate
                            </Button>
                        </div>
                    </FormField>
                </div>
            </ModalSection>

            <section class="flex flex-col gap-3 border-t border-border pt-4">
                <button
                    type="button"
                    class="flex items-center gap-1.5 self-start text-[13px] font-bold text-primary"
                    :aria-expanded="showMoreOptions"
                    aria-controls="item-more-options"
                    @click="showMoreOptions = !showMoreOptions"
                >
                    <ChevronDown :class="['h-3.5 w-3.5 transition-transform duration-150', showMoreOptions ? 'rotate-180' : '']" />
                    More options
                    <span class="font-normal text-text-muted">HS code, posting account, expiry, description, image</span>
                </button>

                <div v-show="showMoreOptions" id="item-more-options" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <FormField v-slot="{ describedBy }" label="HS code" for="item-hs-code" :error="form.errors.hs_code">
                        <Input
                            id="item-hs-code"
                            v-model="form.hs_code"
                            type="text"
                            placeholder="e.g. 8471.30"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid('hs_code')"
                        />
                    </FormField>

                    <FormField label="Expiry date" :error="form.errors.expiry_date">
                        <NepaliDateInput v-model="form.expiry_date" />
                    </FormField>

                    <FormField
                        label="Posting account"
                        for="item-account"
                        help="Where buying this item is posted in the ledger. Keep the default for ordinary stock; pick an expense account for a service, or a fixed asset account for a capital item."
                        :error="form.errors.account_id"
                        class="sm:col-span-2 lg:col-span-1"
                    >
                        <Combobox
                            id="item-account"
                            :model-value="form.account_id"
                            :options="postingAccountOptions"
                            placeholder="Default (Purchases Account)"
                            @update:model-value="(value) => (form.account_id = value)"
                        />
                    </FormField>

                    <FormField v-slot="{ describedBy }" label="Description" for="item-description" :error="form.errors.description" class="sm:col-span-2">
                        <textarea
                            id="item-description"
                            v-model="form.description"
                            rows="3"
                            placeholder="Optional notes"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid('description')"
                            :class="textareaClass"
                        ></textarea>
                    </FormField>

                    <FormField label="Item image" for="item-image" help="JPEG, PNG, or WebP up to 2MB." :error="form.errors.image" class="sm:col-span-2 lg:col-span-1">
                        <div class="flex items-center gap-3">
                            <img
                                v-if="imagePreviewUrl"
                                :src="imagePreviewUrl"
                                alt="Item image preview"
                                class="h-16 w-16 shrink-0 border-[1.5px] border-border object-cover"
                            />
                            <input
                                id="item-image"
                                ref="imageInput"
                                type="file"
                                accept="image/*"
                                class="w-full min-w-0 border-[1.5px] border-border bg-bg-subtle px-3 py-2 text-[13px] text-text-base transition-colors duration-150 outline-none file:mr-3 file:border-0 file:bg-transparent file:text-[13px] file:font-semibold file:text-primary focus:border-primary focus:bg-white focus:[box-shadow:0_0_0_3px_var(--color-primary-focus-ring)]"
                                @change="onImageChange"
                            />
                        </div>
                    </FormField>
                </div>
            </section>

            <section v-if="editing" class="flex flex-col gap-3 border-t border-border pt-4">
                <CheckboxField
                    id="item-is-active"
                    v-model="form.is_active"
                    label="Active"
                    hint="Inactive items are hidden from every picker."
                />

                <p v-if="hasStock(editing) && !form.is_active" class="border-[1.5px] border-warning-text bg-warning-bg px-3 py-2 text-sm text-warning-text">
                    This item still has {{ formatQuantity(stockOnHand(editing)) }} {{ editing.unit }} in stock. An inactive item
                    disappears from every picker, so that stock could never be sold, adjusted or transferred out. Clear the stock
                    first.
                </p>
                <p v-if="form.errors.is_active" class="text-sm text-danger" role="alert">{{ form.errors.is_active }}</p>
            </section>
        </form>

        <QuickAddModal
            :open="activeQuickAdd !== null"
            :title="activeQuickAdd?.title ?? ''"
            :description="activeQuickAdd?.description ?? ''"
            :url="activeQuickAdd?.url ?? ''"
            :placeholder="activeQuickAdd?.placeholder ?? ''"
            :payload="activeQuickAdd?.payload ?? {}"
            @update:open="onQuickAddOpenChange"
            @created="onQuickAddCreated"
        />

        <template #footer>
            <label v-if="!editing" class="mr-auto flex items-center gap-2 text-[13px] text-text-base">
                <input v-model="addAnother" type="checkbox" class="size-4 border-[1.5px] border-border" />
                Add another
            </label>
            <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
            <Button variant="primary" tone="purple" type="submit" form="item-form" :disabled="form.processing">
                {{ form.processing ? 'Saving...' : editing ? 'Save item' : 'Create item' }}
            </Button>
        </template>
    </Modal>
</template>
