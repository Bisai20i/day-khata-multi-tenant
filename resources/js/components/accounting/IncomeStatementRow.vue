<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { compareMoney, formatMoney, subtractMoney } from '@/lib/money.js';
import { usePermissions } from '@/composables/usePermissions';
import BranchReportCells from '@/components/branch/BranchReportCells.vue';

/**
 * One line of the legacy-style income statement: a ledger account row, a
 * section line, a bold subtotal, or a highlighted result (gross / net profit).
 *
 * - `row`: a statement row { id, code, name, amount, computed, merged, by_branch }.
 *   Rows with an id link to the account ledger for the report's fiscal year
 *   and date range; computed rows (virtual stock) and rows merged from several
 *   accounts are never linked.
 * - without `row`, `label` + `amount` (+ optional `values`, the per-branch
 *   amounts) describe a section line, subtotal or result line.
 * - `variant`: 'account', 'section' (semi-bold caption that carries its
 *   section total), 'subtotal', or 'result'. A negative result is shown in
 *   parentheses, as the reference system does.
 * - `level`: indent step 0-4; `indent` is the older shorthand for level 1.
 * - `columns`: the by-branch columns; empty keeps the plain two-column line.
 * - `tone`: 'success' / 'danger' colours a result line.
 */
const props = defineProps({
    row: { type: [Object, null], default: null },
    label: { type: String, default: '' },
    amount: { type: String, default: '0.00' },
    values: { type: [Object, null], default: null },
    columns: { type: Array, default: () => [] },
    variant: { type: String, default: 'account' },
    tone: { type: String, default: '' },
    indent: { type: Boolean, default: false },
    level: { type: Number, default: 0 },
    fiscalYearId: { type: [Number, null], default: null },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    // Branch query string (no leading "?") appended to the ledger link.
    branchQuery: { type: String, default: '' },
    // Project id (0 or null: none) appended to the ledger link, which filters by it.
    projectId: { type: [Number, null], default: null },
});

const { can } = usePermissions();

const INDENTS = ['', 'pl-4', 'pl-8', 'pl-12', 'pl-16'];

const indentClass = computed(() => INDENTS[Math.min(props.level > 0 ? props.level : props.indent ? 1 : 0, INDENTS.length - 1)]);

const rawAmount = computed(() => props.row?.amount ?? props.amount);

// Sign tests run through the money helpers. Only a result line wraps a loss
// in parentheses; every other line keeps its plain figure.
const isResult = computed(() => props.variant === 'result');

function formatShown(value) {
    return isResult.value && compareMoney(value, '0.00') < 0 ? `(${formatMoney(subtractMoney('0.00', value))})` : formatMoney(value);
}

const shownAmount = computed(() => formatShown(rawAmount.value));

// Branch cells of a result line follow the same parentheses rule; a zero stays a dash.
function branchCell(column) {
    const value = shownValues.value?.[column.id];

    return value === undefined || value === null || compareMoney(value, '0.00') === 0 ? '-' : formatShown(value);
}
const shownValues = computed(() => props.values ?? props.row?.by_branch ?? null);

const ledgerUrl = computed(() =>
    props.row?.id && !props.row.computed && !props.row.merged
        ? `/reports/ledger?account_id=${props.row.id}&fiscal_year_id=${props.fiscalYearId ?? ''}&from=${props.from ?? ''}&to=${props.to ?? ''}${props.branchQuery ? `&${props.branchQuery}` : ''}${props.projectId ? `&project_id=${props.projectId}` : ''}`
        : null,
);

const lineClass = computed(() => {
    if (props.variant === 'result') {
        const color = props.tone === 'success' ? 'bg-success-bg text-success' : props.tone === 'danger' ? 'bg-danger-bg text-danger' : 'bg-bg-muted text-text-strong';

        return `mt-2 rounded px-3 py-2 text-[14px] font-bold ${color}`;
    }

    if (props.variant === 'subtotal') {
        return 'px-1 py-1.5 text-[13px] font-bold text-text-strong';
    }

    if (props.variant === 'section') {
        return 'px-1 py-1.5 text-[13px] font-semibold text-text-strong';
    }

    return 'px-1 py-1.5 text-[13px] text-text-base';
});
</script>

<template>
    <div class="flex items-center" :class="lineClass">
        <div class="flex-1" :class="indentClass">
            <template v-if="row">
                <Link v-if="ledgerUrl && can('account_ledger.view')" :href="ledgerUrl" class="text-primary hover:underline">{{ row.name }}</Link>
                <template v-else>{{ row.name }}</template>
                <span v-if="row.code" class="text-text-muted">· {{ row.code }}</span>
                <span v-if="row.computed" class="text-text-muted">· computed</span>
            </template>
            <template v-else>{{ label }}</template>
        </div>
        <div class="w-32 text-right">{{ shownAmount }}</div>
        <BranchReportCells v-if="columns.length > 0" :columns="columns" :values="shownValues" cell-class="w-32">
            <template v-if="isResult" #default="{ column }">{{ branchCell(column) }}</template>
        </BranchReportCells>
    </div>
</template>
