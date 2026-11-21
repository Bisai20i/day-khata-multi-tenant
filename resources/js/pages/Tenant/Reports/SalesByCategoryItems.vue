<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { formatMoney, formatQuantity } from '@/lib/money';
import { formatBsDate } from '@/lib/format';
import { useBranchReportFilter } from '@/composables/useBranchReportFilter';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
    storeName: { type: [String, null], default: null },
    // Null for the (schema-impossible) "no category" list.
    categoryId: { type: [Number, null], default: null },
    // Null for a whole-category list or the "Uncategorized" subcategory row.
    subcategoryId: { type: [Number, null], default: null },
    groupName: { type: String, required: true },
    rows: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ value: '0.00', returns: '0.00', net: '0.00' }) },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Sales by Category');

const branchFilter = useBranchReportFilter(() => props.branchReport);

// The same dates, store and branch the parent report was showing.
function appliedParams() {
    const params = new URLSearchParams({ from: props.from, to: props.to });

    if (props.storeId) {
        params.set('store_id', props.storeId);
    }

    branchFilter.appendTo(params);

    return params;
}

const backHref = computed(() => `/reports/sales-by-category?${appliedParams().toString()}`);

// The bills behind one item, on Item-wise Sales for the same dates.
function billsHref(itemId) {
    const params = appliedParams();
    params.set('item_id', itemId);

    return `/reports/item-wise-sales?${params.toString()}`;
}

const rangeLabel = computed(
    () => `BS ${formatBsDate(props.from)} to ${formatBsDate(props.to)} (AD ${props.from} to ${props.to})`,
);
</script>

<template>
    <div>
        <PageHeader
            :title="groupName"
            description="Items sold in this category, with the quantity, the sales, what was returned and the net."
            :back-href="backHref"
            back-label="Back to Sales by Category"
        />

        <p class="mb-1 text-[12.5px] text-text-muted">
            Showing report for {{ rangeLabel }}<template v-if="storeName"> - Store: {{ storeName }}</template>
        </p>

        <BranchReportNotes v-if="branchReport" :report="branchReport" hide-label />

        <p class="mb-4 text-[12.5px] text-text-muted">
            These rows add up to the row you clicked, for the same dates, store and branch. Amounts are after line and invoice
            discounts, before VAT, the same basis as Sales by Category and Item-wise Sales, so each item's Net here equals its Net on
            the Item-wise page its View link opens. Quantities include free (bonus) units and are in each item's base unit, so only the
            amounts are totalled.
        </p>

        <Card variant="panel">
            <div v-if="rows.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                Nothing sold or returned in this group for these dates or this filter.
            </div>

            <div v-else class="divide-y divide-border">
                <div class="flex items-center px-1 py-1.5 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <div class="flex-1">Item</div>
                    <div class="w-36 text-right">Quantity</div>
                    <div class="w-28 text-right">Sales</div>
                    <div class="w-28 text-right">Returns</div>
                    <div class="w-28 text-right">Net</div>
                    <div class="w-20 text-right">Bills</div>
                </div>

                <div v-for="row in rows" :key="row.itemId" class="flex items-center px-1 py-1.5 text-[13px] text-text-base">
                    <div class="flex-1">{{ row.itemName }}</div>
                    <div class="w-36 text-right">{{ `${formatQuantity(row.quantity)} ${row.unit}`.trim() }}</div>
                    <div class="w-28 text-right">{{ formatMoney(row.value) }}</div>
                    <div class="w-28 text-right">{{ formatMoney(row.returns) }}</div>
                    <div class="w-28 text-right">{{ formatMoney(row.net) }}</div>
                    <div class="w-20 text-right">
                        <Link :href="billsHref(row.itemId)" class="text-[12px] font-semibold text-purple-600 hover:underline">View</Link>
                    </div>
                </div>
            </div>

            <div class="mt-3 flex items-center border-t-[1.5px] border-border px-1 pt-3 text-[13px] font-bold text-text-strong">
                <div class="flex-1">Total</div>
                <div class="w-36" />
                <div class="w-28 text-right">{{ formatMoney(totals.value) }}</div>
                <div class="w-28 text-right">{{ formatMoney(totals.returns) }}</div>
                <div class="w-28 text-right">{{ formatMoney(totals.net) }}</div>
                <div class="w-20" />
            </div>
        </Card>
    </div>
</template>
