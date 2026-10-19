<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import DataTable from '@/components/ui/DataTable.vue';
import Label from '@/components/ui/Label.vue';
import { formatMoney, formatQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { itemLedgerColumn } from '@/lib/itemLedger';

defineOptions({ layout: AppLayout });

const props = defineProps({
    asOf: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    valuationTotal: { type: String, default: '0.00' },
    negativeStockCount: { type: Number, default: 0 },
    excludedNonStockCount: { type: Number, default: 0 },
    stores: { type: Array, default: () => [] },
    storeId: { type: [Number, null], default: null },
});

useLayoutChrome('Stock Ageing');

const asOfInput = ref(props.asOf);
const storeId = ref(props.storeId);
const isLoading = ref(false);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const asOfLabel = computed(() => `As of BS ${formatBsDate(props.asOf)} (AD ${props.asOf})`);

function applyFilter() {
    router.get(
        window.location.pathname,
        { as_of: asOfInput.value, store_id: storeId.value ?? undefined },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

const buckets = [
    { key: 'd0_30', label: '0-30 days' },
    { key: 'd31_60', label: '31-60 days' },
    { key: 'd61_90', label: '61-90 days' },
    { key: 'd90_plus', label: 'Over 90 days' },
];

const columns = [
    { accessorKey: 'name', header: 'Item' },
    { id: 'code', header: 'Code', numeric: false, cell: ({ row }) => row.original.barcode || row.original.hsCode || '-' },
    { accessorKey: 'unit', header: 'Unit' },
    { id: 'quantity', header: 'Quantity', numeric: true, cell: ({ row }) => formatQuantity(row.original.quantity) },
    { id: 'value', header: 'Value', numeric: true, cell: ({ row }) => formatMoney(row.original.value) },
    ...buckets.flatMap((bucket) => [
        { id: `${bucket.key}Qty`, header: `${bucket.label} qty`, numeric: true, cell: ({ row }) => formatQuantity(row.original[`${bucket.key}Qty`]) },
        { id: `${bucket.key}Value`, header: `${bucket.label} value`, numeric: true, cell: ({ row }) => formatMoney(row.original[`${bucket.key}Value`]) },
    ]),
    { id: 'unagedQuantity', header: 'Unaged qty', numeric: true, cell: ({ row }) => formatQuantity(row.original.unagedQuantity) },
    { id: 'unagedValue', header: 'Unaged value', numeric: true, cell: ({ row }) => formatMoney(row.original.unagedValue) },
    itemLedgerColumn((row) => row.itemId),
];

const reconciles = computed(() => props.totals.value === props.valuationTotal);
</script>

<template>
    <div>
        <PageHeader title="Stock Ageing" description="How long the stock on hand has been sitting, item by item, grouped by age of the receipt." />

        <p class="mb-1 text-[12.5px] text-text-muted">Showing report: {{ asOfLabel }}</p>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Stock on hand is matched newest-first against the item's receipts (the oldest goods are assumed sold first).
            Values use the same weighted average cost as Stock Valuation, split across the age buckets by quantity.
        </p>

        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="w-40">
                <Label class="mb-1 text-[11px]">As of date (BS)</Label>
                <NepaliDateInput v-model="asOfInput" />
            </div>
            <div class="w-56">
                <Label class="mb-1 text-[11px]">Store</Label>
                <Select v-model="storeId" :options="storeOptions" />
            </div>
            <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
        </div>

        <Card variant="panel">
            <DataTable :columns="columns" :data="rows" :page-size="25" empty-message="No stock on hand for this date or store. Try a different date or store." />
        </Card>

        <div class="mt-3 flex flex-wrap justify-end gap-6 border-[1.5px] border-border bg-bg-subtle px-4 py-2 text-[12.5px]">
            <div><span class="text-text-muted">Quantity:</span> <span class="font-semibold">{{ formatQuantity(totals.quantity ?? '0.0000') }}</span></div>
            <div v-for="bucket in buckets" :key="bucket.key">
                <span class="text-text-muted">{{ bucket.label }}:</span>
                <span class="font-semibold">{{ formatMoney(totals[`${bucket.key}Value`] ?? '0.00') }}</span>
            </div>
            <div><span class="text-text-muted">Unaged:</span> <span class="font-semibold">{{ formatMoney(totals.unagedValue ?? '0.00') }}</span></div>
            <div><span class="font-bold text-text-strong">Total value:</span> <span class="font-bold">{{ formatMoney(totals.value ?? '0.00') }}</span></div>
        </div>

        <p class="mt-3 text-[11.5px] text-text-faint">
            <template v-if="reconciles">Total value equals the Stock Valuation report for the same date and store ({{ formatMoney(valuationTotal) }}).</template>
            <template v-else>Total value {{ formatMoney(totals.value ?? '0.00') }} differs from Stock Valuation {{ formatMoney(valuationTotal) }}; please report this.</template>
            Only stockable items are listed
            <template v-if="excludedNonStockCount > 0">({{ excludedNonStockCount }} service / capital / non-stock items are excluded because they carry no stock)</template>.
            <template v-if="negativeStockCount > 0">
                {{ negativeStockCount }} item(s) have negative stock; their quantity and value are shown under "Unaged" and should be corrected with a stock adjustment.
            </template>
            "Unaged" also holds any quantity on hand that has no recorded receipt to date it by.
        </p>
    </div>
</template>
