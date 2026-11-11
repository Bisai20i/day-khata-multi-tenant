<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import NepaliDateInput from '@/components/ui/NepaliDateInput.vue';
import Label from '@/components/ui/Label.vue';
import BranchReportNotes from '@/components/branch/BranchReportNotes.vue';
import { formatMoney, compareMoney } from '@/lib/money.js';
import { formatBsDate } from '@/lib/format.js';
import { useBranchLabel } from '@/composables/useBranchLabel';
import { ALL_BRANCHES } from '@/composables/useBranch';

/**
 * Branch comparison (plan 12, BR16): sales, purchases, gross profit, expenses
 * and net profit per branch, read from the income statement's own by-branch
 * columns (BranchComparisonReportController). Every figure arrives as an
 * exact decimal string and is only formatted here; the bar widths are whole
 * percentages computed on the server, so the page does no money arithmetic.
 */
defineOptions({ layout: AppLayout });

const props = defineProps({
    fiscalYears: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    rows: { type: Array, default: () => [] },
    // Sum of the rows; null for a user limited to some branches.
    totals: { type: [Object, null], default: null },
    // 'company' (every branch) or 'selected' (an unrestricted user's subset); null without totals.
    totalsScope: { type: [String, null], default: null },
    // BranchFilter payload; null when the company is not multi-branch.
    branchReport: { type: [Object, null], default: null },
});

const { label, plural } = useBranchLabel();

useLayoutChrome(`${label()} Comparison`);

const fiscalYearOptions = computed(() =>
    props.fiscalYears.map((fiscalYear) => ({
        value: fiscalYear.id,
        label: fiscalYear.status === 'open' ? `${fiscalYear.name} (open)` : fiscalYear.name,
    })),
);

const fiscalYear = ref(props.fiscalYearId);
const from = ref(props.from);
const to = ref(props.to);

const branchOptions = computed(() => props.branchReport?.options ?? []);
const isFixedScope = computed(() => branchOptions.value.length <= 1);

function seedSelection() {
    const selected = props.branchReport?.selected ?? null;

    return selected === null ? branchOptions.value.map((branch) => branch.id) : [...selected];
}

const selectedIds = ref(seedSelection());

watch(
    () => JSON.stringify(props.branchReport?.selected ?? null),
    () => {
        selectedIds.value = seedSelection();
    },
);

function toggleBranch(id, checked, event) {
    if (checked) {
        selectedIds.value = [...selectedIds.value, id];
    } else if (selectedIds.value.length > 1) {
        // The last ticked branch stays ticked: a comparison of nothing is empty.
        selectedIds.value = selectedIds.value.filter((selected) => selected !== id);
    } else if (event) {
        // Vue sees no state change here, so put the box back by hand.
        event.target.checked = true;
    }
}

function branchQuery() {
    const ids = branchOptions.value.map((branch) => branch.id).filter((id) => selectedIds.value.includes(id));

    // Every branch the user may see: "all" (their own set for a restricted user).
    return ids.length === 0 || ids.length === branchOptions.value.length ? { branch: ALL_BRANCHES } : { branches: ids };
}

watch(fiscalYear, (value) => {
    const chosen = props.fiscalYears.find((year) => year.id === value);
    from.value = chosen?.startDate ?? null;
    to.value = chosen?.endDate ?? null;
    apply();
});

function apply() {
    router.get(
        window.location.pathname,
        {
            fiscal_year_id: fiscalYear.value ?? undefined,
            from: from.value ?? undefined,
            to: to.value ?? undefined,
            ...branchQuery(),
        },
        { preserveState: true, preserveScroll: true },
    );
}

function exportUrl() {
    const params = new URLSearchParams({ fiscal_year_id: fiscalYear.value ?? '', from: from.value ?? '', to: to.value ?? '' });

    for (const [key, value] of Object.entries(branchQuery())) {
        if (Array.isArray(value)) {
            value.forEach((id) => params.append(`${key}[]`, String(id)));
        } else {
            params.set(key, String(value));
        }
    }

    return `/reports/branch-comparison/export?${params.toString()}`;
}

const allColumns = [
    { key: 'sales', label: 'Sales' },
    { key: 'purchases', label: 'Purchases' },
    { key: 'netTransfers', label: 'Net stock transfers' },
    { key: 'stockChange', label: 'Stock change' },
    { key: 'grossProfit', label: 'Gross profit' },
    { key: 'otherIncome', label: 'Other income' },
    { key: 'expenses', label: 'Expenses' },
    { key: 'netProfit', label: 'Net profit' },
];

// Net stock transfers only appear once some row has a transfer figure.
const columns = computed(() =>
    allColumns.filter((column) => column.key !== 'netTransfers' || props.rows.some((row) => compareMoney(row.netTransfers, '0.00') !== 0)),
);

const isNegative = (value) => compareMoney(value, '0.00') < 0;
const branchName = (row) => (row.code ? `${row.name} (${row.code})` : row.name);
const totalLabel = computed(() => (props.totalsScope === 'selected' ? `Total of selected ${plural('lower')}` : 'Company total'));

const thClass = 'border-b-[1.5px] border-border px-2 py-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase';
</script>

<template>
    <div>
        <PageHeader
            :title="`${label()} Comparison`"
            :description="`Sales, purchases, gross profit, expenses and net profit side by side for each ${label('lower')}.`"
        >
            <a v-if="fiscalYearId !== null && branchReport" :href="exportUrl()"><Button variant="secondary" tone="purple">Export</Button></a>
        </PageHeader>

        <p v-if="!branchReport" class="px-1 py-6 text-center text-[13px] text-text-muted">
            This report compares {{ plural('lower') }}. It is available once the company has more than one active
            {{ label('lower') }}.
        </p>

        <p v-else-if="fiscalYearId === null" class="px-1 py-6 text-center text-[13px] text-text-muted">
            No fiscal year has been created yet.
        </p>

        <template v-else>
            <Card variant="panel" class="mb-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="w-56">
                        <Label class="mb-1">Fiscal year</Label>
                        <Select v-model="fiscalYear" :options="fiscalYearOptions" />
                    </div>
                    <div>
                        <Label class="mb-1">From date (BS)</Label>
                        <NepaliDateInput v-model="from" />
                    </div>
                    <div>
                        <Label class="mb-1">To date (BS)</Label>
                        <NepaliDateInput v-model="to" />
                    </div>
                    <Button variant="primary" tone="purple" @click="apply">Generate report</Button>
                </div>

                <fieldset v-if="!isFixedScope" class="mt-3">
                    <legend class="mb-1 text-[12px] font-semibold text-text-base">{{ plural() }} to compare</legend>
                    <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                        <label
                            v-for="branch in branchOptions"
                            :key="branch.id"
                            class="flex cursor-pointer items-center gap-1.5 text-[13px] text-text-base"
                        >
                            <input
                                type="checkbox"
                                class="size-4 border-[1.5px] border-border"
                                :checked="selectedIds.includes(branch.id)"
                                @change="toggleBranch(branch.id, $event.target.checked, $event)"
                            />
                            {{ branch.code ? `${branch.name} (${branch.code})` : branch.name }}
                            <span v-if="branch.is_active === false" class="text-text-muted">(inactive)</span>
                        </label>
                    </div>
                </fieldset>

                <p v-if="from && to" class="mt-2 text-[12px] text-text-muted">
                    Showing report for {{ formatBsDate(from) }} to {{ formatBsDate(to) }} BS
                    <span class="text-text-muted">({{ from }} to {{ to }})</span>
                </p>
                <BranchReportNotes :report="branchReport" />
                <p v-if="branchReport.restricted" class="mt-1 text-[12px] text-text-muted">
                    No total row: totals are limited to your {{ plural('lower') }}.
                </p>
            </Card>

            <Card variant="panel" class="mb-4 overflow-x-auto">
                <p v-if="rows.length === 0" class="px-1 py-6 text-center text-[13px] text-text-muted">
                    No figures in this period. Try widening the date range.
                </p>
                <table v-else class="w-full min-w-[960px] text-[13px] text-text-base">
                    <thead>
                        <tr>
                            <th :class="[thClass, 'text-left']">{{ label() }}</th>
                            <th v-for="column in columns" :key="column.key" :class="[thClass, 'text-right']">{{ column.label }}</th>
                            <th :class="[thClass, 'text-right']">Net margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-border/50">
                            <td class="px-2 py-2">
                                <span class="font-semibold text-text-strong">{{ branchName(row) }}</span>
                                <span v-if="!row.isActive" class="text-text-muted"> (inactive)</span>
                            </td>
                            <td
                                v-for="column in columns"
                                :key="column.key"
                                class="px-2 py-2 text-right tabular-nums"
                                :class="{ 'text-danger': isNegative(row[column.key]), 'font-semibold': column.key === 'netProfit' }"
                            >
                                {{ formatMoney(row[column.key]) }}
                            </td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ row.margin === null ? '-' : `${row.margin}%` }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="totals">
                        <tr class="font-bold text-text-strong">
                            <td class="border-t-[1.5px] border-border px-2 py-2">{{ totalLabel }}</td>
                            <td
                                v-for="column in columns"
                                :key="column.key"
                                class="border-t-[1.5px] border-border px-2 py-2 text-right tabular-nums"
                                :class="{ 'text-danger': isNegative(totals[column.key]) }"
                            >
                                {{ formatMoney(totals[column.key]) }}
                            </td>
                            <td class="border-t-[1.5px] border-border px-2 py-2 text-right tabular-nums">
                                {{ totals.margin === null ? '-' : `${totals.margin}%` }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <p class="mt-2 text-[12px] text-text-muted">
                    Each {{ label('lower') }}'s figures are its column on the Income Statement with "Show by
                    {{ label('lower') }}" ticked. Gross profit = Sales + Stock change - Purchases (plus Net stock transfers when shown); Net profit = Gross
                    profit + Other income - Expenses. Purchases include Cost of Goods Sold of manufactured goods.
                    <template v-if="columns.some((column) => column.key === 'netTransfers')">
                        Stock sent to other {{ plural('lower') }} is not counted as Sales and stock received from them is
                        not counted as Purchases; the difference is shown as Net stock transfers (stock sent less stock
                        received) and adds to Gross profit, so the identity becomes Gross profit = Sales + Net stock
                        transfers + Stock change - Purchases. Across all {{ plural('lower') }} the transfers cancel out.
                    </template>
                </p>
            </Card>

            <Card v-if="rows.length > 1" variant="panel" title="Net profit">
                <ul class="space-y-2">
                    <li v-for="row in rows" :key="`bar-${row.id}`" class="flex items-center gap-3 text-[13px]">
                        <span class="w-40 shrink-0 truncate text-text-base" :title="branchName(row)">{{ branchName(row) }}</span>
                        <span class="h-3 flex-1 bg-bg-subtle" aria-hidden="true">
                            <span
                                class="block h-full"
                                :class="isNegative(row.netProfit) ? 'bg-danger' : 'bg-success'"
                                :style="{ width: `${row.bar}%` }"
                            />
                        </span>
                        <span class="w-32 shrink-0 text-right tabular-nums" :class="{ 'text-danger': isNegative(row.netProfit) }">
                            {{ formatMoney(row.netProfit) }}
                        </span>
                    </li>
                </ul>
                <p class="mt-2 text-[12px] text-text-muted">Bar length is relative to the largest profit or loss; red is a loss.</p>
            </Card>
        </template>
    </div>
</template>
