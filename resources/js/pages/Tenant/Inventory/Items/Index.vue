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
import RowActions from '@/components/ui/RowActions.vue';
import ItemsIndexFormModal from '@/components/inventory/ItemsIndexFormModal.vue';
import ItemsIndexImportModal from '@/components/inventory/ItemsIndexImportModal.vue';
import ItemsIndexUnitsModal from '@/components/inventory/ItemsIndexUnitsModal.vue';
import ItemsIndexBarcodeModal from '@/components/inventory/ItemsIndexBarcodeModal.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';
import { formatQuantity, formatRate } from '@/lib/money.js';
import { todayInKathmandu } from '@/lib/format.js';

defineOptions({ layout: AppLayout });

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
    items: {
        type: Array,
        default: () => [],
    },
    // Exact decimal strings keyed by item id, never numbers - see
    // ItemController::index().
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

const page = usePage();
const { toast } = useToast();
const { confirm } = useConfirm();
useLayoutChrome('Items');

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


const formModal = ref(null);
const importModal = ref(null);
const unitsModal = ref(null);
const barcodeModal = ref(null);

function openCreate() {
    formModal.value.openCreate();
}

function openEdit(item) {
    formModal.value.openEdit(item);
}

function openImport() {
    importModal.value.openImport();
}

function openUnits(item) {
    unitsModal.value.openUnits(item);
}

function openBarcodeModal(item) {
    barcodeModal.value.openBarcodeModal(item);
}

// "0.0000" when the item has never moved. Kept as a string all the way to
// the formatter: this is a quantity, so it never goes through Number().
function stockOnHand(item) {
    return props.stockByItem[item.id] ?? '0.0000';
}

async function destroy(item) {
    if (!(await confirm({ message: `Delete item "${item.name}"? Items already used in invoices or stock cannot be deleted; deactivate them instead. This cannot be undone.`, tone: 'danger', confirmLabel: 'Delete item' }))) return;
    router.delete(`/items/${item.id}`, {
        // The server refuses to delete an item any document references and
        // returns a field error instead of a raw SQL page - surface it here,
        // since this row action has no form of its own to render errors in.
        onError: (errors) => {
            if (errors.item) toast({ message: errors.item, variant: 'danger' });
        },
    });
}

// Client-side only - mirrors Item::scopeExpired()/scopeExpiringSoon()'s
// boundary rule (an item expiring exactly today counts as expired, not
// expiring soon) using plain "YYYY-MM-DD" string comparison, which sorts
// correctly the same way whereDate() does server-side. item.expiry_date may
// be null (most items won't have one) or a full ISO datetime string, hence
// the slice(0, 10).
//
// "Today" is today in Kathmandu, not in UTC: toISOString() returns the UTC
// day, so between midnight and 05:44 local time it named yesterday and an
// item expiring today was shown as still good (contract C8).
const EXPIRING_SOON_WITHIN_DAYS = 30;

function expiryStatus(item) {
    if (!item.expiry_date) return null;

    const expiry = item.expiry_date.slice(0, 10);
    const today = todayInKathmandu();
    if (expiry <= today) return 'expired';

    const soonUntil = new Date();
    soonUntil.setDate(soonUntil.getDate() + EXPIRING_SOON_WITHIN_DAYS);
    if (expiry <= todayInKathmandu(soonUntil)) return 'soon';

    return null;
}

// Bulk "mark vatable" (item 6): a plain array of selected item ids, not a
// Set - Vue's reactivity tracks array mutation fine here and it serializes
// straight into the request body with no extra conversion.
const selectedItemIds = ref([]);

function isSelected(item) {
    return selectedItemIds.value.includes(item.id);
}

function toggleSelected(item) {
    selectedItemIds.value = isSelected(item)
        ? selectedItemIds.value.filter((id) => id !== item.id)
        : [...selectedItemIds.value, item.id];
}

function toggleSelectAll() {
    selectedItemIds.value = selectedItemIds.value.length === props.items.length ? [] : props.items.map((item) => item.id);
}

const markVatableForm = useForm({ item_ids: [] });

function markSelectedVatable() {
    if (selectedItemIds.value.length === 0) return;

    markVatableForm.item_ids = [...selectedItemIds.value];
    markVatableForm.post('/items/mark-vatable', {
        preserveScroll: true,
        onSuccess: () => {
            selectedItemIds.value = [];
        },
    });
}

const columns = [
    {
        id: 'select',
        header: () =>
            h('input', {
                type: 'checkbox',
                class: 'size-4 border-[1.5px] border-border',
                checked: props.items.length > 0 && selectedItemIds.value.length === props.items.length,
                onChange: toggleSelectAll,
            }),
        numeric: false,
        cell: ({ row }) =>
            h('input', {
                type: 'checkbox',
                class: 'size-4 border-[1.5px] border-border',
                checked: isSelected(row.original),
                onChange: () => toggleSelected(row.original),
            }),
    },
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
        cell: ({ row }) => row.original.subcategory?.name ?? '-',
    },
    { accessorKey: 'unit', header: 'Unit', numeric: false },
    {
        id: 'brand',
        header: 'Brand',
        numeric: false,
        cell: ({ row }) => row.original.brand?.name ?? '-',
    },
    {
        id: 'stock',
        header: 'In stock',
        numeric: true,
        // 4-decimal quantities shown as quantities, trailing zeros trimmed -
        // audit P3 found this column rendering a 4dp value at 2dp.
        cell: ({ row }) => {
            if (!row.original.is_stockable) return '-';
            const onHand = Number(stockOnHand(row.original));
            const minimum = Number(row.original.min_stock ?? 0);
            const badge =
                onHand <= 0
                    ? h(Badge, { variant: 'danger', pill: true }, () => 'Out of stock')
                    : minimum > 0 && onHand <= minimum
                      ? h(Badge, { variant: 'warning', pill: true }, () => 'Low stock')
                      : null;
            return h('div', { class: 'flex flex-col items-end gap-1' }, [
                h('span', formatQuantity(stockOnHand(row.original))),
                badge,
            ]);
        },
    },
    {
        id: 'purchase_rate',
        header: 'Purchase rate',
        numeric: true,
        // formatRate, not toFixed(2): a rate legitimately carries 4 decimals
        // and 12.3456 must not print as 12.35 (audit P0-1/P3).
        cell: ({ row }) => (row.original.purchase_rate != null ? formatRate(row.original.purchase_rate) : '-'),
    },
    {
        id: 'sale_rate',
        header: 'Sale rate',
        numeric: true,
        cell: ({ row }) => (row.original.sale_rate != null ? formatRate(row.original.sale_rate) : '-'),
    },
    {
        id: 'expiry',
        header: 'Expiry',
        numeric: false,
        cell: ({ row }) => {
            const status = expiryStatus(row.original);
            if (status === 'expired') return h(Badge, { variant: 'danger', pill: true }, () => 'Expired');
            if (status === 'soon') return h(Badge, { variant: 'warning', pill: true }, () => 'Expiring soon');
            return '-';
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
            h('div', { class: 'flex items-center justify-end gap-2' }, [
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'h-[26px] shrink-0 border-[1.5px] border-border px-2 text-[11px] font-bold text-text-muted transition-colors duration-150 hover:border-primary hover:text-primary',
                        onClick: () => openUnits(row.original),
                    },
                    'Units',
                ),
                h(
                    'button',
                    {
                        type: 'button',
                        class: 'h-[26px] shrink-0 border-[1.5px] border-border px-2 text-[11px] font-bold text-text-muted transition-colors duration-150 hover:border-primary hover:text-primary',
                        onClick: () => openBarcodeModal(row.original),
                    },
                    'Print barcode',
                ),
                h(RowActions, {
                    onEdit: () => openEdit(row.original),
                    onDelete: () => destroy(row.original),
                }),
            ]),
    },
];
</script>

<template>
    <div>
        <PageHeader title="Items" description="Items: the products you buy and sell, with their unit, rates and stock level.">
                <Button
                    v-if="selectedItemIds.length > 0"
                    variant="secondary"
                    tone="purple"
                    :disabled="markVatableForm.processing"
                    @click="markSelectedVatable"
                >
                    Mark {{ selectedItemIds.length }} vatable
                </Button>
                <Button variant="secondary" tone="purple" @click="openImport">Bulk import (CSV)</Button>
                <Button variant="primary" tone="purple" @click="openCreate">New item</Button>
        </PageHeader>

        <Card variant="panel">
            <DataTable :columns="columns" :data="items" :page-size="10" empty-message="No items yet. Use 'New item' above to add one, or Bulk import (CSV)." />
        </Card>

        <ItemsIndexFormModal
            ref="formModal"
            :categories="categories"
            :subcategories="subcategories"
            :brands="brands"
            :stock-by-item="stockByItem"
            :posting-accounts="postingAccounts"
        />
        <ItemsIndexImportModal ref="importModal" />
        <ItemsIndexUnitsModal ref="unitsModal" :items="items" />
        <ItemsIndexBarcodeModal ref="barcodeModal" />
    </div>
</template>
