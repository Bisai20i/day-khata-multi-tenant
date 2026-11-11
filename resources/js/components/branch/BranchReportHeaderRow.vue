<script setup>
import BranchReportCells from '@/components/branch/BranchReportCells.vue';

/**
 * The caption row of a statement card that has branch columns (plan 12,
 * section 5.9, BR13): an empty name cell, the company-wide amount column
 * captioned "Total", then one caption per branch. Used by the income
 * statement and balance sheet, whose rows are `flex-1` name plus one
 * `w-32` amount. Render it only when the page has branch columns.
 */
defineProps({
    columns: { type: Array, required: true },
    warnIds: { type: Array, default: () => [] },
    warnTitle: { type: String, default: 'These figures do not balance for this column.' },
});
</script>

<template>
    <div class="flex items-center px-1 pb-1 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
        <div class="flex-1"></div>
        <div class="w-32 text-right">Total</div>
        <BranchReportCells header :columns="columns" cell-class="w-32" :warn-ids="warnIds" :warn-title="warnTitle" />
    </div>
</template>
