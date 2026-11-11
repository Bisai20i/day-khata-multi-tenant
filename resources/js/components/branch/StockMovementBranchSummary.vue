<script setup>
import { computed } from 'vue';
import Card from '@/components/ui/Card.vue';
import { useBranchLabel } from '@/composables/useBranchLabel';
import { formatBranchQuantity } from '@/lib/stockBranchReport.js';

/**
 * The per-item, per-branch in / out / net summary above the Stock Movement
 * Register's list (plan 12, section 5.9, BR15). `report` is the page's
 * `branchReport` with `columns` ([{ id, name, code }]) and `summary` (one row
 * per item: { itemId, itemName, unit, byBranch: { "<id>": { in, out, net } },
 * total: { in, out, net } }). Quantities are exact strings from the server and
 * stay per item; nothing is added across units here.
 *
 * Render only when the page has branch columns.
 */
const props = defineProps({
    report: { type: Object, required: true },
});

const { label } = useBranchLabel();

const columns = computed(() => props.report.columns ?? []);
const summary = computed(() => props.report.summary ?? []);

function netClass(value) {
    if (typeof value === 'string' && value.startsWith('-')) {
        return 'text-danger';
    }

    return '';
}
</script>

<template>
    <Card variant="panel" class="mb-4">
        <h2 class="mb-1 text-[13px] font-bold text-text-strong">Movement summary by {{ label('lower') }}</h2>
        <p class="mb-3 text-[12px] text-text-muted">
            Stock in, stock out and net for each item in each {{ label('lower') }}'s stores, for the same dates and filters as the list below.
        </p>

        <p v-if="summary.length === 0" class="py-4 text-center text-[13px] text-text-muted">No movements to summarise for this period or filter.</p>

        <div v-else class="max-h-96 overflow-auto">
            <table class="w-full border-collapse text-[12.5px] text-text-base" :style="{ minWidth: `${420 + columns.length * 240}px` }">
                <thead>
                    <tr class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <th scope="col" rowspan="2" class="border-b-[1.5px] border-border px-2 py-2 text-left align-bottom">Item</th>
                        <th scope="col" rowspan="2" class="border-b-[1.5px] border-border px-2 py-2 text-left align-bottom">Unit</th>
                        <th
                            v-for="column in columns"
                            :key="column.id"
                            scope="colgroup"
                            colspan="3"
                            class="border-l-[1.5px] border-border px-2 pt-2 pb-1 text-center"
                            :title="column.code ? `${column.name} (${column.code})` : column.name"
                        >
                            {{ column.name }}
                        </th>
                        <th scope="colgroup" colspan="3" class="border-l-[1.5px] border-border px-2 pt-2 pb-1 text-center">Total</th>
                    </tr>
                    <tr class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                        <template v-for="column in columns" :key="column.id">
                            <th scope="col" class="border-b-[1.5px] border-l-[1.5px] border-border px-2 pb-2 text-right">In</th>
                            <th scope="col" class="border-b-[1.5px] border-border px-2 pb-2 text-right">Out</th>
                            <th scope="col" class="border-b-[1.5px] border-border px-2 pb-2 text-right">Net</th>
                        </template>
                        <th scope="col" class="border-b-[1.5px] border-l-[1.5px] border-border px-2 pb-2 text-right">In</th>
                        <th scope="col" class="border-b-[1.5px] border-border px-2 pb-2 text-right">Out</th>
                        <th scope="col" class="border-b-[1.5px] border-border px-2 pb-2 text-right">Net</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in summary" :key="row.itemId" class="border-b border-border">
                        <td class="px-2 py-1.5 font-semibold text-text-strong">{{ row.itemName }}</td>
                        <td class="px-2 py-1.5">{{ row.unit ?? '-' }}</td>
                        <template v-for="column in columns" :key="column.id">
                            <td class="border-l-[1.5px] border-border px-2 py-1.5 text-right [font-variant-numeric:tabular-nums]">
                                {{ formatBranchQuantity(row.byBranch?.[column.id]?.in) }}
                            </td>
                            <td class="px-2 py-1.5 text-right [font-variant-numeric:tabular-nums]">
                                {{ formatBranchQuantity(row.byBranch?.[column.id]?.out) }}
                            </td>
                            <td class="px-2 py-1.5 text-right font-semibold [font-variant-numeric:tabular-nums]" :class="netClass(row.byBranch?.[column.id]?.net)">
                                {{ formatBranchQuantity(row.byBranch?.[column.id]?.net) }}
                            </td>
                        </template>
                        <td class="border-l-[1.5px] border-border px-2 py-1.5 text-right [font-variant-numeric:tabular-nums]">
                            {{ formatBranchQuantity(row.total?.in) }}
                        </td>
                        <td class="px-2 py-1.5 text-right [font-variant-numeric:tabular-nums]">{{ formatBranchQuantity(row.total?.out) }}</td>
                        <td class="px-2 py-1.5 text-right font-semibold [font-variant-numeric:tabular-nums]" :class="netClass(row.total?.net)">
                            {{ formatBranchQuantity(row.total?.net) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
</template>
