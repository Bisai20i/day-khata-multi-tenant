<script setup>
import { AlertTriangle } from '@lucide/vue';
import { formatBranchAmount, formatNetDrCr } from '@/lib/branchReport.js';

/**
 * One cell per branch column, appended to a report row after its amount
 * columns (plan 12, section 5.9, BR13). Render it only when the page has
 * branch columns (`useBranchReportColumns().byBranch`).
 *
 * - header: the branch names (with a warning marker on `warnIds`). Place it
 *   in the row that carries the page's own header styling.
 * - otherwise: `values` is the row's `by_branch` map ({ "<branchId>": decimal
 *   string }) shown as an amount, or as "1,200.00 Dr" / "300.00 Cr" with
 *   `mode="drcr"`. A scoped default slot replaces the cell content.
 * - `cellClass` must match the width of the page's own amount column.
 */
const props = defineProps({
    columns: { type: Array, required: true },
    header: { type: Boolean, default: false },
    values: { type: Object, default: null },
    mode: { type: String, default: 'amount' },
    cellClass: { type: String, default: 'w-28' },
    warnIds: { type: Array, default: () => [] },
    warnTitle: { type: String, default: 'These figures do not balance for this column.' },
});

function cellText(column) {
    const value = props.values?.[column.id];

    return props.mode === 'drcr' ? formatNetDrCr(value) : formatBranchAmount(value);
}

function columnTitle(column) {
    return column.code ? `${column.name} (${column.code})` : column.name;
}
</script>

<template>
    <div class="ml-3 flex shrink-0 items-center">
        <div v-for="column in columns" :key="column.id" :class="[cellClass, 'shrink-0 text-right']">
            <div v-if="header" class="flex items-center justify-end gap-1" :title="columnTitle(column)">
                <AlertTriangle
                    v-if="warnIds.includes(column.id)"
                    class="size-3 shrink-0 text-warning-text"
                    role="img"
                    :aria-label="warnTitle"
                    :title="warnTitle"
                />
                <span class="truncate">{{ column.name }}</span>
            </div>
            <slot v-else :column="column">{{ cellText(column) }}</slot>
        </div>
    </div>
</template>
