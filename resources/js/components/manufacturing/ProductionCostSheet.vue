<script setup>
import Card from '@/components/ui/Card.vue';
import { formatMoney, formatQuantity, formatRate } from '@/lib/money.js';

/**
 * The live cost sheet: materials per item, direct costs per type and the
 * four headline figures. Every number is the exact string the server's
 * costSheet() produced; nothing is recomputed here.
 */
defineProps({
    sheet: { type: Object, required: true },
    unit: { type: String, default: '' },
});
</script>

<template>
    <Card variant="panel" title="Cost sheet" class="!p-4">
        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="border-[1.5px] border-border bg-bg-subtle p-3">
                <div class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Total Production Cost</div>
                <div class="mt-0.5 text-lg font-bold text-text-strong">{{ formatMoney(sheet.total_production_cost) }}</div>
            </div>
            <div class="border-[1.5px] border-border bg-bg-subtle p-3">
                <div class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Completed quantity</div>
                <div class="mt-0.5 text-lg font-bold text-text-strong">{{ formatQuantity(sheet.completed_quantity) }} {{ unit }}</div>
            </div>
            <div class="border-[1.5px] border-border bg-bg-subtle p-3">
                <div class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Cost per Unit</div>
                <div class="mt-0.5 text-lg font-bold text-text-strong">{{ formatRate(sheet.cost_per_unit) }}</div>
            </div>
            <div class="border-[1.5px] border-border bg-bg-subtle p-3">
                <div class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">Remaining WIP</div>
                <div class="mt-0.5 text-lg font-bold text-text-strong">{{ formatMoney(sheet.wip_balance) }}</div>
            </div>
        </div>

        <div class="mb-4 overflow-x-auto">
        <table class="w-full min-w-[480px] text-sm">
            <thead>
                <tr class="border-b border-border text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <th class="px-3 py-2">Material</th>
                    <th class="px-3 py-2 text-right">Issued</th>
                    <th class="px-3 py-2 text-right">Returned</th>
                    <th class="px-3 py-2 text-right">Net qty</th>
                    <th class="px-3 py-2 text-right">Net cost</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in sheet.materials" :key="row.item_id ?? index" class="border-b border-border-soft">
                    <td class="px-3 py-2">{{ row.item_name }}</td>
                    <td class="px-3 py-2 text-right">{{ formatQuantity(row.issued_quantity) }}</td>
                    <td class="px-3 py-2 text-right">{{ formatQuantity(row.returned_quantity) }}</td>
                    <td class="px-3 py-2 text-right">{{ formatQuantity(row.net_quantity) }}</td>
                    <td class="px-3 py-2 text-right">{{ formatMoney(row.net_cost) }}</td>
                </tr>
                <tr v-if="sheet.materials.length === 0">
                    <td colspan="5" class="px-3 py-4 text-center text-text-muted">No material issued yet.</td>
                </tr>
            </tbody>
        </table>
        </div>

        <div class="overflow-x-auto">
        <table class="w-full min-w-[320px] text-sm">
            <thead>
                <tr class="border-b border-border text-left text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
                    <th class="px-3 py-2">Direct cost type</th>
                    <th class="px-3 py-2 text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in sheet.direct_costs" :key="row.type" class="border-b border-border-soft">
                    <td class="px-3 py-2">{{ row.label }}</td>
                    <td class="px-3 py-2 text-right">{{ formatMoney(row.amount) }}</td>
                </tr>
                <tr v-if="sheet.direct_costs.length === 0">
                    <td colspan="2" class="px-3 py-4 text-center text-text-muted">No direct costs.</td>
                </tr>
            </tbody>
        </table>
        </div>
    </Card>
</template>
