<script setup>
import { Link } from '@inertiajs/vue3';
import Card from '@/components/ui/Card.vue';
import { formatMoney } from '@/lib/money.js';
import { usePermissions } from '@/composables/usePermissions';

/**
 * The dashboard's "Top 5 items / customers this month" cards. Render with
 * `v-if="topItemsThisMonth || topCustomersThisMonth"`: a null prop means the
 * user may not see that list.
 */
defineProps({
    topItemsThisMonth: { type: [Array, null], default: null },
    topCustomersThisMonth: { type: [Array, null], default: null },
});

const { can } = usePermissions();

function formatAmount(amount) {
    return formatMoney(amount ?? '0.00');
}
</script>

<template>
    <div class="grid grid-cols-1 gap-5" :class="{ 'md:grid-cols-2': topItemsThisMonth && topCustomersThisMonth }">
        <Card v-if="topItemsThisMonth" variant="panel" title="Top 5 Items This Month">
            <div v-if="topItemsThisMonth.length === 0" class="py-6 text-center text-sm text-text-muted">
                No sales this month yet.
                <Link v-if="can('sales.view')" href="/sales" class="font-semibold text-primary hover:underline">Record a sale</Link>
            </div>
            <ol v-else class="flex flex-col gap-3">
                <li v-for="(item, index) in topItemsThisMonth" :key="item.name" class="flex items-center justify-between text-sm">
                    <span class="flex items-center gap-2 text-text-base">
                        <span class="flex size-5 items-center justify-center rounded-full bg-primary-tint text-[11px] font-bold text-primary">
                            {{ index + 1 }}
                        </span>
                        {{ item.name }}
                    </span>
                    <span class="font-semibold text-text-strong">{{ formatAmount(item.total) }}</span>
                </li>
            </ol>
        </Card>

        <Card v-if="topCustomersThisMonth" variant="panel" title="Top 5 Customers This Month">
            <div v-if="topCustomersThisMonth.length === 0" class="py-6 text-center text-sm text-text-muted">
                No sales this month yet.
                <Link v-if="can('sales.view')" href="/sales" class="font-semibold text-primary hover:underline">Record a sale</Link>
            </div>
            <ol v-else class="flex flex-col gap-3">
                <li v-for="(customer, index) in topCustomersThisMonth" :key="customer.name" class="flex items-center justify-between text-sm">
                    <span class="flex items-center gap-2 text-text-base">
                        <span class="flex size-5 items-center justify-center rounded-full bg-primary-tint text-[11px] font-bold text-primary">
                            {{ index + 1 }}
                        </span>
                        {{ customer.name }}
                    </span>
                    <span class="font-semibold text-text-strong">{{ formatAmount(customer.total) }}</span>
                </li>
            </ol>
        </Card>
    </div>
</template>
