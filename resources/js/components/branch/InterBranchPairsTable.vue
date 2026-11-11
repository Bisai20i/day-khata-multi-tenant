<script setup>
import { formatMoney, isZeroMoney } from '@/lib/money.js';
import { useBranchLabel } from '@/composables/useBranchLabel';

/**
 * The "who owes whom" half of the inter-branch balances report (plan 12,
 * BR19): one row per branch pair with each side's own books in words, the
 * goods in transit between them and whether the two sides mirror; then the
 * unattributed clearing balances carried forward before branch tracking.
 *
 * Rows come from InterBranchBalancesReportController::pairs() and
 * ::unattributed(); every amount is an exact decimal string, only formatted
 * here.
 */
defineProps({
    pairs: { type: Array, required: true },
    unattributed: { type: Array, required: true },
});

const { label, plural } = useBranchLabel();

function sideText(side) {
    if (side === null) {
        return null;
    }

    return side.debtor === null ? 'Settled' : `${side.debtor} owes ${side.creditor} ${formatMoney(side.amount)}`;
}

const thClass = 'border-b-[1.5px] border-border px-2 py-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase';
</script>

<template>
    <div>
        <p v-if="pairs.length === 0 && unattributed.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
            No balances between {{ plural('lower') }} up to this date.
        </p>

        <table v-else class="w-full min-w-[860px] text-[13px] text-text-base">
            <thead>
                <tr>
                    <th :class="[thClass, 'text-left']">{{ plural() }}</th>
                    <th :class="[thClass, 'text-left']">First side's books</th>
                    <th :class="[thClass, 'text-left']">Second side's books</th>
                    <th :class="[thClass, 'text-right']">Goods in transit</th>
                    <th :class="[thClass, 'text-left']">Reconciliation</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="pair in pairs" :key="pair.key" class="border-b border-border/50 align-top">
                    <td class="px-2 py-2 font-semibold text-text-strong">{{ pair.first.name }} and {{ pair.second.name }}</td>
                    <td class="px-2 py-2">
                        <span class="text-[11px] text-text-faint">{{ pair.first.name }}:</span>
                        <span v-if="pair.firstView" class="ml-1">{{ sideText(pair.firstView) }}</span>
                        <span v-else class="ml-1 text-text-muted">Not visible to you</span>
                    </td>
                    <td class="px-2 py-2">
                        <span class="text-[11px] text-text-faint">{{ pair.second.name }}:</span>
                        <span v-if="pair.secondView" class="ml-1">{{ sideText(pair.secondView) }}</span>
                        <span v-else class="ml-1 text-text-muted">Not visible to you</span>
                    </td>
                    <td class="px-2 py-2 text-right tabular-nums">
                        {{ isZeroMoney(pair.inTransit) ? '-' : formatMoney(pair.inTransit) }}
                    </td>
                    <td class="px-2 py-2">
                        <span v-if="pair.status === 'matched'" class="text-success">Both sides agree</span>
                        <span v-else-if="pair.status === 'in_transit'" class="text-warning-text">
                            Differ by {{ formatMoney(pair.difference) }}, all of it goods in transit; this clears when the goods are received.
                        </span>
                        <span v-else-if="pair.status === 'mismatch'" class="font-semibold text-danger">
                            Difference of {{ formatMoney(pair.unexplained) }} not explained by goods in transit: the two
                            {{ plural('lower') }}' books do not mirror each other. Check the Inter-Branch Clearing entries
                            of both {{ plural('lower') }}.
                        </span>
                        <span v-else class="text-text-muted">
                            Only one side is visible to you, so it cannot be checked here.
                        </span>
                    </td>
                </tr>
                <tr v-for="row in unattributed" :key="`unattributed-${row.branchId}`" class="border-b border-border/50 align-top">
                    <td class="px-2 py-2">
                        <span class="font-semibold text-text-strong">{{ row.branchName }}</span>
                        <span class="block text-[11px] text-text-muted">Unattributed (before branch tracking)</span>
                    </td>
                    <td class="px-2 py-2" colspan="3">
                        {{ row.direction === 'receivable' ? 'Owed to' : 'Owed by' }} {{ row.branchName }}
                        {{ formatMoney(row.amount) }}, counterparty not recorded
                    </td>
                    <td class="px-2 py-2 text-text-muted">
                        Carried forward by a year-end close before {{ plural('lower') }} were tracked per pair; it cannot be
                        split by {{ label('lower') }}.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
