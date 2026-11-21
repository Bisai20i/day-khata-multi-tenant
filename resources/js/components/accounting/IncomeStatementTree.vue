<script setup>
import { computed } from 'vue';
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { formatMoney } from '@/lib/money.js';
import { groupKey, subgroupKey } from '@/lib/incomeStatementTree.js';
import BranchReportCells from '@/components/branch/BranchReportCells.vue';
import IncomeStatementRow from '@/components/accounting/IncomeStatementRow.vue';

/**
 * One section of the income statement (Sales, Purchases, Direct Expenses,
 * Other Business Income, Expenses) as an expandable Group -> Sub-group ->
 * Ledger tree. A closed group shows only its name and total; clicking it (or
 * "Expand all" on the page) opens its ledgers and sub-groups.
 *
 * - `groups`: the server's nodes { name, total, by_branch, rows, subgroups[] }.
 * - `section`: key prefix that keeps this section's nodes apart from the others.
 * - `expanded`: the page's list of open node keys; the tree only asks to
 *   toggle one (`toggle` event), it never owns the state.
 * - `columns`: by-branch columns; empty keeps the plain amount column.
 */
const props = defineProps({
    groups: { type: Array, default: () => [] },
    section: { type: String, required: true },
    expanded: { type: Array, default: () => [] },
    columns: { type: Array, default: () => [] },
    fiscalYearId: { type: [Number, null], default: null },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    // Branch query string (no leading "?") appended to ledger links.
    branchQuery: { type: String, default: '' },
    // Project id (0 or null: none) carried to the ledger links.
    projectId: { type: [Number, null], default: null },
});

const emit = defineEmits(['toggle']);

// A Set, so each open-state check in the template is O(1) however many nodes
// "Expand all" has opened.
const openKeys = computed(() => new Set(props.expanded));
const isOpen = (key) => openKeys.value.has(key);

function rowKey(row) {
    return `${row.id ?? row.code ?? 'x'}-${row.name}`;
}
</script>

<template>
    <div>
        <template v-for="group in groups" :key="group.name">
            <div
                role="button"
                tabindex="0"
                class="flex cursor-pointer items-center px-1 py-1.5 pl-4 text-[13px] text-text-base hover:bg-bg-muted"
                :aria-expanded="isOpen(groupKey(section, group))"
                @click="emit('toggle', groupKey(section, group))"
                @keydown.enter.prevent="emit('toggle', groupKey(section, group))"
                @keydown.space.prevent="emit('toggle', groupKey(section, group))"
            >
                <div class="flex flex-1 items-center gap-1.5 font-semibold">
                    <component :is="isOpen(groupKey(section, group)) ? ChevronDown : ChevronRight" class="size-3.5 shrink-0 text-text-muted" />
                    {{ group.name }}
                </div>
                <div class="w-32 text-right">{{ formatMoney(group.total) }}</div>
                <BranchReportCells v-if="columns.length > 0" :columns="columns" :values="group.by_branch" cell-class="w-32" />
            </div>

            <template v-if="isOpen(groupKey(section, group))">
                <IncomeStatementRow
                    v-for="row in group.rows"
                    :key="rowKey(row)"
                    :row="row"
                    :columns="columns"
                    :level="3"
                    :fiscal-year-id="fiscalYearId"
                    :from="from"
                    :to="to"
                    :branch-query="branchQuery"
                    :project-id="projectId"
                />

                <template v-for="subgroup in group.subgroups" :key="subgroup.name">
                    <div
                        role="button"
                        tabindex="0"
                        class="flex cursor-pointer items-center px-1 py-1.5 pl-8 text-[13px] text-text-base hover:bg-bg-muted"
                        :aria-expanded="isOpen(subgroupKey(section, group, subgroup))"
                        @click="emit('toggle', subgroupKey(section, group, subgroup))"
                        @keydown.enter.prevent="emit('toggle', subgroupKey(section, group, subgroup))"
                        @keydown.space.prevent="emit('toggle', subgroupKey(section, group, subgroup))"
                    >
                        <div class="flex flex-1 items-center gap-1.5 font-medium">
                            <component :is="isOpen(subgroupKey(section, group, subgroup)) ? ChevronDown : ChevronRight" class="size-3.5 shrink-0 text-text-muted" />
                            {{ subgroup.name }}
                        </div>
                        <div class="w-32 text-right">{{ formatMoney(subgroup.total) }}</div>
                        <BranchReportCells v-if="columns.length > 0" :columns="columns" :values="subgroup.by_branch" cell-class="w-32" />
                    </div>

                    <template v-if="isOpen(subgroupKey(section, group, subgroup))">
                        <IncomeStatementRow
                            v-for="row in subgroup.rows"
                            :key="rowKey(row)"
                            :row="row"
                            :columns="columns"
                            :level="4"
                            :fiscal-year-id="fiscalYearId"
                            :from="from"
                            :to="to"
                            :branch-query="branchQuery"
                            :project-id="projectId"
                        />
                    </template>
                </template>
            </template>
        </template>
    </div>
</template>
