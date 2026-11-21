<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatMoney } from '@/lib/money.js';
import BranchReportCells from '@/components/branch/BranchReportCells.vue';

/**
 * One line of the balance sheet: a head / group / sub-group heading (no
 * amount), a ledger account row, or a bold "<name> Total" line.
 *
 * - `variant`: 'heading' (label only), 'account' (label + amount, linked to the
 *   ledger when `account.id` is set and `canLedger`), 'total' (bold amount).
 * - A synthetic account (`account.synthetic`, no id) is shown like a ledger without a link.
 * - `level`: 0-4, the indent step (static classes so Tailwind keeps them).
 * - `amount` / `values`: the signed statement-side amount and the per-branch
 *   map ({ "<branchId>": decimal string }); `columns` empty keeps the plain line.
 */
const props = defineProps({
    label: { type: String, default: '' },
    account: { type: [Object, null], default: null },
    variant: { type: String, default: 'account' },
    level: { type: Number, default: 0 },
    amount: { type: String, default: '0.00' },
    values: { type: [Object, null], default: null },
    columns: { type: Array, default: () => [] },
    canLedger: { type: Boolean, default: false },
    fiscalYearId: { type: [Number, null], default: null },
    to: { type: [String, null], default: null },
    // Branch query string (no leading "?") appended to the ledger link.
    branchQuery: { type: String, default: '' },
});

const INDENTS = ['pl-1', 'pl-4', 'pl-8', 'pl-12', 'pl-16'];

const lineClass = computed(() => {
    const indent = INDENTS[Math.max(0, Math.min(props.level, INDENTS.length - 1))];
    const weight = props.variant === 'total' ? 'font-bold text-text-strong' : props.variant === 'heading' ? 'font-semibold text-text-base' : 'text-text-base';

    return `${indent} ${weight}`;
});

// The synthetic Current Year Profit / (Loss) row has no id, so it never links to a ledger.
const ledgerUrl = computed(() =>
    props.account?.id && !props.account.synthetic && props.canLedger
        ? `/reports/ledger?account_id=${props.account.id}&fiscal_year_id=${props.fiscalYearId ?? ''}&to=${props.to ?? ''}${props.branchQuery ? `&${props.branchQuery}` : ''}`
        : null,
);
</script>

<template>
    <div class="flex items-center py-1 pr-1 text-[13px]" :class="lineClass">
        <div class="min-w-0 flex-1 break-words pr-2">
            <template v-if="account">
                <Link v-if="ledgerUrl" :href="ledgerUrl" class="text-primary hover:underline">{{ account.name }}</Link>
                <template v-else>{{ account.name }}</template>
            </template>
            <template v-else>{{ label }}</template>
        </div>
        <template v-if="variant !== 'heading'">
            <div class="text-right tabular-nums" :class="columns.length > 0 ? 'w-32' : 'w-28'">{{ formatMoney(account?.amount ?? amount) }}</div>
            <BranchReportCells v-if="columns.length > 0" :columns="columns" :values="account?.by_branch ?? values" cell-class="w-32" />
        </template>
    </div>
</template>
