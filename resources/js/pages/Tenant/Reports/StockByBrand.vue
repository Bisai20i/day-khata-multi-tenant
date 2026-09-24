<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
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
    asOf: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    grandTotal: { type: Object, default: () => ({ value: '0.00', quantities: [] }) },
    grandTotalValuation: { type: String, default: '0.00' },
    stores: { type: Array, default: () => [] },
    storeId: { type: [Number, null], default: null },
});

useLayoutChrome('Stock by Brand');

const asOf = ref(props.asOf);
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
        { as_of: asOf.value, store_id: storeId.value ?? undefined },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (isLoading.value = true),
            onFinish: () => (isLoading.value = false),
        },
    );
}

const asOfLabel = computed(() => `As of BS ${formatBsDate(props.asOf)} (AD ${props.asOf})`);

// Quantities are a per-base-unit breakdown, never one number: 12 Boxes plus
// 30 Kilograms is not "42". An empty list renders as a dash.
function quantityLabel(quantities) {
    if (!quantities || quantities.length === 0) {
        return '-';
    }

    return quantities.map((entry) => `${formatQuantity(entry.quantity)} ${entry.unit}`.trim()).join(', ');
}
</script>

<template>
    <div>
        <PageHeader title="Stock by Brand" description="How much stock you hold for each brand, and what it is worth." />

        <p class="mb-1 text-[12.5px] text-text-muted">Showing report: {{ asOfLabel }}</p>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Values come from the same weighted average cost the Balance Sheet uses.
        </p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-text-muted">As of date (BS)</label>
                    <NepaliDateInput v-model="asOf" />
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
                No records for this date or filter. Try a different date or store.
            </div>

            <div v-else class="divide-y divide-border">
                <div class="flex items-center px-1 py-1.5 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <div class="flex-1">Brand</div>
                    <div class="w-56 text-right">Quantity</div>
                    <div class="w-32 text-right">Valuation</div>
                </div>

                <div
                    v-for="brand in rows"
                    :key="brand.brandId ?? 'unbranded'"
                    class="flex items-center px-1 py-1.5 text-[13px] font-bold text-text-strong"
                >
                    <div class="flex-1">{{ brand.brandName }}</div>
                    <div class="w-56 text-right">{{ quantityLabel(brand.quantities) }}</div>
                    <div class="w-32 text-right">{{ formatMoney(brand.value) }}</div>
                </div>
            </div>

            <div class="mt-3 flex items-center justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[13px] font-bold text-text-strong">
                <div>Grand Total Valuation</div>
                <div class="w-32 text-right">{{ formatMoney(grandTotalValuation) }}</div>
            </div>
        </Card>
    </div>
</template>
