<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { FileSpreadsheet } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import Label from '@/components/ui/Label.vue';
import { formatMoney, formatQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import BranchReportFilter from '@/components/branch/BranchReportFilter.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });

const props = defineProps({
    asOf: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    grandTotal: { type: Object, default: () => ({ value: '0.00', quantities: [] }) },
    grandTotalValuation: { type: String, default: '0.00' },
    stores: { type: Array, default: () => [] },
    storeId: { type: [Number, null], default: null },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Stock by Brand');

const { can } = usePermissions();

const asOfInput = ref(props.asOf);
const storeIdInput = ref(props.storeId);

// The server drops a store outside the chosen branches; follow what it applied.
watch(
    () => [props.asOf, props.storeId, props.stores],
    () => {
        asOfInput.value = props.asOf;
        storeIdInput.value = props.storeId;
    },
);

const branchFilter = useBranchReportFilter(() => props.branchReport);

const storeOptions = computed(() => [
    { value: null, label: 'All stores' },
    ...props.stores.map((store) => ({ value: store.id, label: store.name })),
]);

const isLoading = ref(false);

const hasActiveFilter = computed(() => storeIdInput.value !== null);

function resetFilters() {
    storeIdInput.value = null;
    applyFilter();
}

function applyFilter() {
    router.get(
        window.location.pathname,
        { as_of: asOfInput.value, store_id: storeIdInput.value ?? undefined, ...branchFilter.toQuery() },
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

// Built from the currently-applied filters (props, not the unsaved inputs)
// so a link or an export always matches what's on screen.
function appliedParams() {
    const params = new URLSearchParams({ as_of: props.asOf });

    if (props.storeId) {
        params.set('store_id', String(props.storeId));
    }

    branchFilter.appendTo(params);

    return params;
}

const exportUrl = computed(() => `/reports/stock-by-brand/export?${appliedParams().toString()}`);

// Legacy's brand -> items drill-down; the "Unbranded" row is sent as `none`.
function itemsHref(brandId) {
    const params = appliedParams();
    params.set('brand_id', brandId ?? 'none');

    return `/reports/stock-by-brand/items?${params.toString()}`;
}
</script>

<template>
    <div>
        <PageHeader
            title="Stock by Brand"
            description="How much stock you hold for each brand, and what it is worth."
        >
            <BranchReportFilter
                v-if="branchReport"
                v-model:choice="branchFilter.choice.value"
                :report="branchReport"
                id-prefix="stock-brand-branch"
                hide-label
                @change="applyFilter"
            />
            <Button v-if="can('stock_reports.export')" as="a" :href="exportUrl" variant="secondary" tone="purple">
                <FileSpreadsheet class="h-[14px] w-[14px]" aria-hidden="true" />
                Export to Excel
            </Button>
        </PageHeader>

        <p class="mb-1 text-[12.5px] text-text-muted">Showing report: {{ asOfLabel }}</p>

        <p class="mb-4 text-[12.5px] text-text-muted">
            Values come from the same weighted average cost the Balance Sheet uses.
        </p>

        <Card variant="panel" class="mb-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <Label class="mb-1">As of date (BS)</Label>
                    <NepaliDateInput v-model="asOfInput" />
                </div>
                <div class="w-56">
                    <Label class="mb-1">Store</Label>
                    <Select v-model="storeIdInput" :options="storeOptions" />
                </div>
                <Button variant="primary" tone="purple" :loading="isLoading" @click="applyFilter">Generate report</Button>
                <Button v-if="hasActiveFilter" variant="secondary" tone="purple" :disabled="isLoading" @click="resetFilters">Reset</Button>
            </div>
            <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />
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
                    <div class="flex-1">
                        <Link :href="itemsHref(brand.brandId)" class="hover:text-purple-600 hover:underline">{{ brand.brandName }}</Link>
                    </div>
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
