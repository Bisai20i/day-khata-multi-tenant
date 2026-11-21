<script setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import ItemWiseReport from '@/components/reports/ItemWiseReport.vue';

defineOptions({ layout: AppLayout });

// Every prop ItemWisePurchaseReportController sends, declared so none of them
// falls through as an HTML attribute. The body lives in ItemWiseReport.
const props = defineProps({
    items: { type: Array, default: () => [] },
    totals: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    lineTotals: { type: Object, required: true },
    selectedItemName: { type: [String, null], default: null },
    itemsList: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    subcategories: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    from: { type: String, required: true },
    to: { type: String, required: true },
    storeId: { type: [Number, null], default: null },
    itemId: { type: [Number, null], default: null },
    categoryId: { type: [Number, null], default: null },
    subcategoryId: { type: [Number, null], default: null },
    brandId: { type: [Number, null], default: null },
    search: { type: String, default: '' },
    // Branch filter; null when the company has no branches.
    branchReport: { type: [Object, null], default: null },
});

useLayoutChrome('Item-wise Purchase');
</script>

<template>
    <ItemWiseReport kind="purchase" v-bind="props" />
</template>
