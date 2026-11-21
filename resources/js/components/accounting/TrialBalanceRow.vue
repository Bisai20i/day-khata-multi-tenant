<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatMoney } from '@/lib/money.js';
import BranchReportCells from '@/components/branch/BranchReportCells.vue';

/**
 * One line of the trial balance: a head / group / sub-group heading (no
 * figures), a ledger account row, or a bold total line.
 *
 * - `variant`: 'heading' (label only), 'account' (name + figures, linked to the
 *   ledger when `account.id` is set and `canLedger`), 'total' (bold figures).
 * - `level`: 0-4, the indent step (static classes so Tailwind keeps them).
 * - `totals`: { openingDebit, openingCredit, periodDebit, periodCredit,
 *   closingDebit, closingCredit } for a total line; `values` is its by_branch map.
 * - `columns`: by-branch columns (empty keeps the plain line); branch cells show
 *   the net closing balance as Dr / Cr.
 */
const props = defineProps({
    label: { type: String, default: '' },
    account: { type: [Object, null], default: null },
    variant: { type: String, default: 'account' },
    level: { type: Number, default: 0 },
    totals: { type: [Object, null], default: null },
    values: { type: [Object, null], default: null },
    columns: { type: Array, default: () => [] },
    canLedger: { type: Boolean, default: false },
    fiscalYearId: { type: [Number, null], default: null },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    // Branch scope query string (no leading "?") so the ledger total matches this row.
    ledgerQuery: { type: String, default: '' },
});

const INDENTS = ['pl-1', 'pl-4', 'pl-8', 'pl-12', 'pl-16'];
const FIELDS = ['openingDebit', 'openingCredit', 'periodDebit', 'periodCredit', 'closingDebit', 'closingCredit'];

const lineClass = computed(() => {
    const indent = INDENTS[Math.max(0, Math.min(props.level, INDENTS.length - 1))];
    const weight = props.variant === 'total' ? 'font-bold text-text-strong' : props.variant === 'heading' ? 'font-semibold text-text-base' : 'text-text-base';

    return `${indent} ${weight}`;
});

const figures = computed(() => props.account ?? props.totals ?? {});

const ledgerUrl = computed(() => {
    if (!props.account?.id || !props.canLedger) {
        return null;
    }

    const params = new URLSearchParams({ account_id: String(props.account.id) });
    if (props.fiscalYearId !== null) {
        params.set('fiscal_year_id', String(props.fiscalYearId));
    }
    if (props.from) {
        params.set('from', props.from);
    }
    if (props.to) {
        params.set('to', props.to);
    }

    const query = params.toString();

    return `/reports/ledger?${query}${props.ledgerQuery ? `&${props.ledgerQuery}` : ''}`;
});
</script>

<template>
    <div class="flex items-center py-1 pr-1 text-[13px]" :class="lineClass">
        <div class="flex-1">
            <template v-if="account">
                <Link v-if="ledgerUrl" :href="ledgerUrl" class="text-primary hover:underline">{{ account.name }}</Link>
                <template v-else>{{ account.name }}</template>
                <span class="font-normal text-text-muted"> · {{ account.code ?? '-' }}</span>
            </template>
            <template v-else>{{ label }}</template>
        </div>
        <template v-if="variant !== 'heading'">
            <div v-for="field in FIELDS" :key="field" class="w-28 text-right">{{ formatMoney(figures[field]) }}</div>
            <BranchReportCells v-if="columns.length > 0" :columns="columns" :values="account?.by_branch ?? values" mode="drcr" />
        </template>
    </div>
</template>
