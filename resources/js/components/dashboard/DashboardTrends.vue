<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import { formatMoney, compareMoney, parseMoney } from '@/lib/money.js';

/**
 * The dashboard's 7-day sales and purchase trend strips. Render with
 * `v-if="salesTrend || purchaseTrend"`: a null prop means the user may not
 * see that trend, and its card is left out.
 */
const props = defineProps({
    salesTrend: { type: [Array, null], default: null },
    purchaseTrend: { type: [Array, null], default: null },
});

/**
 * Tallest bar in the two trend strips, picked by exact money comparison.
 * Only the bar GEOMETRY below leaves the decimal world - the same narrow
 * exemption CONTRACTS C1 grants Money::toFloat() for chart data. No amount
 * a user reads is ever produced this way.
 */
const maxTrendTotal = computed(() => {
    let max = '0.00';

    for (const day of [...(props.salesTrend ?? []), ...(props.purchaseTrend ?? [])]) {
        if (compareMoney(day.total, max) > 0) {
            max = day.total;
        }
    }

    return max;
});

function trendBarWidth(total) {
    const max = parseMoney(maxTrendTotal.value);
    const value = parseMoney(total ?? '0.00');

    // parseMoney returns canonical strings, so a zero maximum must be caught
    // with an exact comparison: dividing by it gave "NaN%", which the browser
    // ignored, drawing every bar full width on a week with no sales.
    if (!max.ok || !value.ok || compareMoney(max.value, '0.00') <= 0) {
        return '0%';
    }

    return `${Math.min(100, (Number(value.value) / Number(max.value)) * 100)}%`;
}

function trendDayLabel(dateString) {
    return new Date(`${dateString}T00:00:00`).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' });
}

function formatAmount(amount) {
    return formatMoney(amount ?? '0.00');
}
</script>

<template>
    <div class="mb-5 grid grid-cols-1 gap-5" :class="{ 'md:grid-cols-2': salesTrend && purchaseTrend }">
        <Card v-if="salesTrend" variant="panel" title="Sales Trend (Last 7 Days)">
            <div v-if="salesTrend.length === 0" class="py-6 text-center text-sm text-text-muted">
                No sales in the last 7 days.
                <Link href="/sales" class="font-semibold text-primary hover:underline">Record a sale</Link>
            </div>
            <ul v-else class="flex flex-col gap-2">
                <li v-for="day in salesTrend" :key="day.date" class="flex items-center gap-3 text-sm">
                    <span class="w-14 shrink-0 text-xs text-text-muted">{{ trendDayLabel(day.date) }}</span>
                    <span class="h-2 flex-1 overflow-hidden rounded-full bg-border-soft">
                        <span class="block h-full bg-primary" :style="{ width: trendBarWidth(day.total) }" />
                    </span>
                    <span class="w-20 shrink-0 text-right font-semibold text-text-strong">{{ formatAmount(day.total) }}</span>
                </li>
            </ul>
        </Card>

        <Card v-if="purchaseTrend" variant="panel" title="Purchase Trend (Last 7 Days)">
            <div v-if="purchaseTrend.length === 0" class="py-6 text-center text-sm text-text-muted">
                No purchases in the last 7 days.
                <Link href="/purchases" class="font-semibold text-primary hover:underline">Record a purchase</Link>
            </div>
            <ul v-else class="flex flex-col gap-2">
                <li v-for="day in purchaseTrend" :key="day.date" class="flex items-center gap-3 text-sm">
                    <span class="w-14 shrink-0 text-xs text-text-muted">{{ trendDayLabel(day.date) }}</span>
                    <span class="h-2 flex-1 overflow-hidden rounded-full bg-border-soft">
                        <span class="block h-full bg-[#F59E0B]" :style="{ width: trendBarWidth(day.total) }" />
                    </span>
                    <span class="w-20 shrink-0 text-right font-semibold text-text-strong">{{ formatAmount(day.total) }}</span>
                </li>
            </ul>
        </Card>
    </div>
</template>
