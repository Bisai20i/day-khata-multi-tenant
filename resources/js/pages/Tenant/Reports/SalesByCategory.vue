<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import { formatMoney, formatQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';

defineOptions({ layout: AppLayout });

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    grandTotal: { type: Object, default: () => ({ value: '0.00', quantities: [] }) },
    stores: { type: Array, default: () => [] },
    storeId: { type: [Number, null], default: null },
});

useLayoutChrome('Sales by Category');

const from = ref(props.from);
const to = ref(props.to);
const storeId = ref(props.storeId);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const isLoading = ref(false);

const hasActiveFilter = computed(() => storeId.value !== null);

function resetFilters() {
    storeId.value = null;
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        { from: from.value, to: to.value, store_id: storeId.value ?? undefined },
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

// Quantities are a per-base-unit breakdown, never one number: 12 Boxes plus
// 30 Kilograms is not "42". An empty list renders as a dash.
function quantityLabel(quantities) {
    if (!quantities || quantities.length === 0) {
        return '-';
    }

    return quantities.map((entry) => `${formatQuantity(entry.quantity)} ${entry.unit}`.trim()).join(', ');
}

// Pivots a category/subcategory aggregate row into its underlying sale
// lines via Item-wise Sales' own category_id/subcategory_id filters (audit
// T15-11), keeping the same date range and store filter this report is
// currently showing, rather than building a separate drill-down endpoint.
function lineDetailHref(categoryId, subcategoryId) {
    const params = new URLSearchParams({ from: props.from, to: props.to });

    if (props.storeId) {
        params.set('store_id', props.storeId);
    }

    params.set('category_id', categoryId);

    if (subcategoryId) {
        params.set('subcategory_id', subcategoryId);
    }

    return `/reports/item-wise-sales?${params.toString()}`;
}
</script>

<template>
    <div>
        <PageHeader title="Sales by Category" description="What you sold in each item category in the selected dates, by quantity and amount." />

        <p class="mb-1 text-[12.5px] text-text-muted">Showing report for {{ rangeLabel }}</p>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Quantities are in base units and listed per unit, so two different units are never added together.
        </p>

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
                    <label class="mb-1 block text-xs font-semibold text-text-muted">Store</label>
                    <Select v-model="storeId" :options="storeOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
        </Card>

        <Card variant="panel">
            <div v-if="rows.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No records for this period or filter. Try widening the date range.
            </div>

            <div v-else class="divide-y divide-border">
                <div class="flex items-center px-1 py-1.5 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <div class="flex-1">Category / Subcategory</div>
                    <div class="w-56 text-right">Quantity</div>
                    <div class="w-32 text-right">Value</div>
                    <div class="w-24 text-right">Lines</div>
                </div>

                <template v-for="category in rows" :key="category.categoryId">
                    <div class="flex items-center px-1 py-1.5 text-[13px] font-bold text-text-strong">
                        <div class="flex-1">{{ category.categoryName }}</div>
                        <div class="w-56 text-right">{{ quantityLabel(category.quantities) }}</div>
                        <div class="w-32 text-right">{{ formatMoney(category.value) }}</div>
                        <div class="w-24 text-right">
                            <Link :href="lineDetailHref(category.categoryId, null)" class="text-[12px] font-semibold text-purple-600 hover:underline">
                                View lines
                            </Link>
                        </div>
                    </div>

                    <div
                        v-for="subcategory in category.subcategories"
                        :key="`sub-${category.categoryId}-${subcategory.subcategoryId ?? 'none'}`"
                        class="flex items-center px-1 py-1 pl-6 text-[13px] text-text-base"
                    >
                        <div class="flex-1">{{ subcategory.subcategoryName }}</div>
                        <div class="w-56 text-right">{{ quantityLabel(subcategory.quantities) }}</div>
                        <div class="w-32 text-right">{{ formatMoney(subcategory.value) }}</div>
                        <div class="w-24 text-right">
                            <Link
                                v-if="subcategory.subcategoryId"
                                :href="lineDetailHref(category.categoryId, subcategory.subcategoryId)"
                                class="text-[12px] font-semibold text-purple-600 hover:underline"
                            >
                                View lines
                            </Link>
                        </div>
                    </div>
                </template>
            </div>

            <div class="mt-3 flex items-center justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[13px] font-bold text-text-strong">
                <div>Grand Total</div>
                <div class="w-56 text-right">{{ quantityLabel(grandTotal.quantities) }}</div>
                <div class="w-32 text-right">{{ formatMoney(grandTotal.value) }}</div>
            </div>
        </Card>
    </div>
</template>
