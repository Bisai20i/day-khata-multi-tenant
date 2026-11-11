<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import Card from '@/components/ui/Card.vue';
import { formatMoney } from '@/lib/money.js';
import { useBranchLabel } from '@/composables/useBranchLabel';

/**
 * The dashboard's branch comparison widget (plan 12, BR16): this month's
 * sales and purchases for each branch the dashboard covers, from the
 * `branchComparison` prop (BranchDashboardComparison). Render only when that
 * prop is not null: the server leaves it null for a company that is not
 * multi-branch or a dashboard narrowed to one branch. Amounts are exact
 * decimal strings, formatted, never computed on.
 */
const props = defineProps({
    comparison: { type: Object, required: true },
});

const { label } = useBranchLabel();

const title = computed(() => `${label()} Comparison (This Month)`);

function branchName(branch) {
    return branch.code ? `${branch.name} (${branch.code})` : branch.name;
}

function formatAmount(amount) {
    return formatMoney(amount ?? '0.00');
}
</script>

<template>
    <div class="mb-5">
        <Card variant="panel" :title="title">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-soft text-left text-[11px] font-bold tracking-[.6px] text-text-muted uppercase">
                        <th class="pb-2 font-bold">{{ label() }}</th>
                        <th v-if="props.comparison.showSales" class="pb-2 text-right font-bold">Sales</th>
                        <th v-if="props.comparison.showPurchases" class="pb-2 text-right font-bold">Purchases</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="branch in props.comparison.branches" :key="branch.id" class="border-b border-border-soft last:border-0">
                        <td class="py-2 text-text-base">{{ branchName(branch) }}</td>
                        <td v-if="props.comparison.showSales" class="py-2 text-right font-semibold text-text-strong">
                            {{ formatAmount(branch.sales) }}
                        </td>
                        <td v-if="props.comparison.showPurchases" class="py-2 text-right font-semibold text-text-strong">
                            {{ formatAmount(branch.purchases) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <div v-if="props.comparison.canOpenReport" class="mt-3 text-right">
                <Link href="/reports/branch-comparison" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                    Open {{ label('lower') }} comparison
                    <ArrowRight class="size-3.5" aria-hidden="true" />
                </Link>
            </div>
        </Card>
    </div>
</template>
