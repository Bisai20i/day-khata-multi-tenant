<script setup>
import { computed, h, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import DataTable from '@/components/ui/DataTable.vue';
import { formatQuantity, formatRate } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    movements: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    subcategories: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    itemId: { type: [Number, null], default: null },
    storeId: { type: [Number, null], default: null },
    categoryId: { type: [Number, null], default: null },
    subcategoryId: { type: [Number, null], default: null },
    brandId: { type: [Number, null], default: null },
});

useLayoutChrome('Stock Movement Register');

const from = ref(props.from);
const to = ref(props.to);
const itemId = ref(props.itemId);
const storeId = ref(props.storeId);
const categoryId = ref(props.categoryId);
const subcategoryId = ref(props.subcategoryId);
const brandId = ref(props.brandId);

const itemOptions = computed(() => [
    { value: null, label: 'All items' },
    ...props.items.map((item) => ({ value: item.id, label: item.name })),
]);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const categoryOptions = computed(() => [
    { value: null, label: 'All categories' },
    ...props.categories.map((category) => ({ value: category.id, label: category.name })),
]);

// Narrowed to the picked category's own subcategories, same convention as
// the item-wise reports' subcategory picker.
const subcategoryOptions = computed(() => [
    { value: null, label: 'All subcategories' },
    ...props.subcategories
        .filter((subcategory) => !categoryId.value || subcategory.item_category_id === categoryId.value)
        .map((subcategory) => ({ value: subcategory.id, label: subcategory.name })),
]);

const brandOptions = computed(() => [
    { value: null, label: 'All brands' },
    ...props.brands.map((brand) => ({ value: brand.id, label: brand.name })),
]);

const isLoading = ref(false);

const hasActiveFilter = computed(
    () =>
        itemId.value !== null ||
        storeId.value !== null ||
        categoryId.value !== null ||
        subcategoryId.value !== null ||
        brandId.value !== null,
);

function resetFilters() {
    itemId.value = null;
    storeId.value = null;
    categoryId.value = null;
    subcategoryId.value = null;
    brandId.value = null;
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        {
            from: from.value,
            to: to.value,
            item_id: itemId.value ?? undefined,
            store_id: storeId.value ?? undefined,
            category_id: categoryId.value ?? undefined,
            subcategory_id: subcategoryId.value ?? undefined,
            brand_id: brandId.value ?? undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);

// The quantity arrives as an exact 4-decimal string, so its sign is read
// off the string itself - parsing it back into a float just to pick a
// colour is the habit this rewrite removed.
function quantitySign(value) {
    if (value.startsWith('-')) {
        return 'text-danger';
    }

    return /[1-9]/.test(value) ? 'text-success' : '';
}

const columns = [
    { id: 'date_bs', header: 'Date (BS)', numeric: false, cell: ({ row }) => formatBsDate(row.original.date) },
    { accessorKey: 'date', header: 'Date (AD)' },
    { accessorKey: 'itemName', header: 'Item' },
    { id: 'storeName', header: 'Store', numeric: false, cell: ({ row }) => row.original.storeName ?? '-' },
    { accessorKey: 'unit', header: 'Unit' },
    { accessorKey: 'movementType', header: 'Movement Type' },
    {
        id: 'quantity',
        header: 'Quantity',
        numeric: true,
        cell: ({ row }) =>
            h(
                'span',
                { class: `font-semibold ${quantitySign(row.original.quantity)}` },
                (row.original.quantity.startsWith('-') ? '' : '+') + formatQuantity(row.original.quantity),
            ),
    },
    {
        id: 'unitCostRate',
        header: 'Cost per unit',
        numeric: true,
        cell: ({ row }) => (row.original.unitCostRate === null ? '-' : formatRate(row.original.unitCostRate)),
    },
    { accessorKey: 'reference', header: 'Reference' },
];
</script>

<template>
    <div>
        <PageHeader title="Stock Movement Register" description="Every stock in and out movement in the selected dates, line by line." />

        <p class="mb-4 text-[12.5px] text-text-muted">Showing report for {{ rangeLabel }}</p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">From date (BS)</label>
                    <NepaliDateInput v-model="from" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">To date (BS)</label>
                    <NepaliDateInput v-model="to" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Item</label>
                    <Select v-model="itemId" :options="itemOptions" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Category</label>
                    <Select v-model="categoryId" :options="categoryOptions" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Subcategory</label>
                    <Select v-model="subcategoryId" :options="subcategoryOptions" />
                </div>
                <div class="w-56">
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Brand</label>
                    <Select v-model="brandId" :options="brandOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
        </Card>

        <Card variant="panel">
            <DataTable :columns="columns" :data="movements" :page-size="25" empty-message="No records for this period or filter. Try widening the date range." />
        </Card>
    </div>
</template>
