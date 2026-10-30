<script setup>
import { formatMoney, formatQuantity, formatRate } from '@/lib/money.js';

/**
 * Read-only lines of a material issue note: item, issued quantity, rate and
 * amount, all formatted from the server's exact strings.
 */
defineProps({
    lines: { type: Array, default: () => [] },
});
</script>

<template>
    <table class="w-full text-xs">
        <thead>
            <tr class="text-left text-text-muted">
                <th class="px-3 py-1">Item</th>
                <th class="px-3 py-1 text-right">Issued qty</th>
                <th class="px-3 py-1 text-right">Rate</th>
                <th class="px-3 py-1 text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="line in lines" :key="line.id" class="border-t border-border-soft">
                <td class="px-3 py-1">{{ line.item_name }}</td>
                <td class="px-3 py-1 text-right">{{ formatQuantity(line.quantity) }} {{ line.unit }}</td>
                <td class="px-3 py-1 text-right">{{ formatRate(line.rate) }}</td>
                <td class="px-3 py-1 text-right">{{ formatMoney(line.amount) }}</td>
            </tr>
        </tbody>
    </table>
</template>
