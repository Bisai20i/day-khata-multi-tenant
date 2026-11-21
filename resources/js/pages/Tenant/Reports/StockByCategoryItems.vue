<script setup>
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { formatMoney, formatQuantity, formatRate } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    asOf: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
    storeName: { type: [String, null], default: null },
    // Null for the (schema-impossible) "no category" list.
    categoryId: { type: [Number, null], default: null },
    // Null for a whole-category list or the "Uncategorized" subcategory row.
    subcategoryId: { type: [Number, null], default: null },
    groupName: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    totalValue: { type: String, default: '0.00' },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Stock by Category');

const branchFilter = useBranchReportFilter(() => props.branchReport);

// Back to the parent report with the same as-of/store/branch query.
const backHref = computed(() => {
    const params = new URLSearchParams({ as_of: props.asOf });

    if (props.storeId) {
        params.set('store_id', props.storeId);
    }

    branchFilter.appendTo(params);

    return `/reports/stock-by-category?${params.toString()}`;
});

const asOfLabel = computed(() => `As of BS ${formatBsDate(props.asOf)} (AD ${props.asOf})`);
</script>

<template>
    <div>
        <PageHeader
            :title="groupName"
            description="Items in this category, with the quantity held and what it is worth."
            :back-href="backHref"
            back-label="Back to Stock by Category"
        />

        <p class="mb-1 text-[12.5px] text-text-muted">
            Showing report: {{ asOfLabel }}<template v-if="storeName"> - Store: {{ storeName }}</template>
        </p>

        <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />

        <p class="mb-4 text-[12.5px] text-text-muted">
            Values come from the same weighted average cost the Balance Sheet uses. Quantities are in each item's own unit, so only the value is totalled.
        </p>

        <Card variant="panel">
            <div v-if="rows.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                No stock held in this group for this date or filter.
            </div>

            <div v-else class="divide-y divide-border">
                <div class="flex items-center px-1 py-1.5 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <div class="flex-1">Item</div>
                    <div class="w-40 text-right">Quantity</div>
                    <div class="w-32 text-right">Unit cost</div>
                    <div class="w-32 text-right">Valuation</div>
                </div>

                <div v-for="row in rows" :key="row.itemId" class="flex items-center px-1 py-1.5 text-[13px] text-text-base">
                    <div class="flex-1">{{ row.itemName }}</div>
                    <div class="w-40 text-right">{{ `${formatQuantity(row.quantity)} ${row.unit}`.trim() }}</div>
                    <div class="w-32 text-right">{{ formatRate(row.averageCost) }}</div>
                    <div class="w-32 text-right">{{ formatMoney(row.value) }}</div>
                </div>
            </div>

            <div class="mt-3 flex items-center justify-end gap-6 border-t-[1.5px] border-border pt-3 text-[13px] font-bold text-text-strong">
                <div>Total Valuation</div>
                <div class="w-32 text-right">{{ formatMoney(totalValue) }}</div>
            </div>
        </Card>
    </div>
</template>
