<script setup>
import BalanceSheetAccountRow from '@/components/accounting/BalanceSheetAccountRow.vue';

/**
 * One balance sheet head (Assets, Capital, Liabilities ...) laid out as
 * Group -> Sub-group -> Ledger, with a bold "<name> Total" line after every
 * sub-group and group that has at least one account.
 *
 * - `head`: { name, total, by_branch, groups[] { name, total, by_branch,
 *   accounts[], subgroups[] { name, total, by_branch, accounts[] } } }; every
 *   account carries the signed statement-side `amount` (and `by_branch`).
 * - `baseLevel`: indent step of the groups (0 under a card titled with the
 *   head, 1 when the head name is printed as a heading here).
 * - `showHeading` / `showHeadTotal`: print the head name and a "<head> Total".
 * - `columns`: by-branch columns; `hasBranchColumns` guards the extra cells.
 */
const props = defineProps({
    head: { type: Object, required: true },
    baseLevel: { type: Number, default: 0 },
    showHeading: { type: Boolean, default: false },
    showHeadTotal: { type: Boolean, default: false },
    columns: { type: Array, default: () => [] },
    hasBranchColumns: { type: Boolean, default: false },
    canLedger: { type: Boolean, default: false },
    fiscalYearId: { type: [Number, null], default: null },
    to: { type: [String, null], default: null },
    // Branch query string (no leading "?") appended to ledger links.
    branchQuery: { type: String, default: '' },
});

function subgroupHasAccounts(subgroup) {
    return (subgroup.accounts ?? []).length > 0;
}

function groupHasAccounts(group) {
    return (group.accounts ?? []).length > 0 || (group.subgroups ?? []).some(subgroupHasAccounts);
}

function cellColumns() {
    return props.hasBranchColumns ? props.columns : [];
}

function accountKey(account) {
    return `acc-${account.id ?? 'x'}-${account.name}`;
}
</script>

<template>
    <div class="divide-y divide-border">
        <BalanceSheetAccountRow v-if="showHeading" :label="head.name" variant="heading" :level="baseLevel - 1" />
        <template v-for="group in head.groups ?? []" :key="group.name">
            <BalanceSheetAccountRow :label="group.name" variant="heading" :level="baseLevel" />
            <BalanceSheetAccountRow
                v-for="account in group.accounts"
                :key="accountKey(account)"
                :account="account"
                :level="baseLevel + 1"
                :columns="cellColumns()"
                :can-ledger="canLedger"
                :fiscal-year-id="fiscalYearId"
                :to="to"
                :branch-query="branchQuery"
            />
            <template v-for="subgroup in group.subgroups" :key="subgroup.name">
                <BalanceSheetAccountRow :label="subgroup.name" variant="heading" :level="baseLevel + 1" />
                <BalanceSheetAccountRow
                    v-for="account in subgroup.accounts"
                    :key="accountKey(account)"
                    :account="account"
                    :level="baseLevel + 2"
                    :columns="cellColumns()"
                    :can-ledger="canLedger"
                    :fiscal-year-id="fiscalYearId"
                    :to="to"
                    :branch-query="branchQuery"
                />
                <BalanceSheetAccountRow
                    v-if="subgroupHasAccounts(subgroup)"
                    :label="`${subgroup.name} Total`"
                    variant="total"
                    :level="baseLevel + 1"
                    :amount="subgroup.total"
                    :values="subgroup.by_branch"
                    :columns="cellColumns()"
                />
            </template>
            <BalanceSheetAccountRow
                v-if="groupHasAccounts(group)"
                :label="`${group.name} Total`"
                variant="total"
                :level="baseLevel"
                :amount="group.total"
                :values="group.by_branch"
                :columns="cellColumns()"
            />
        </template>
        <BalanceSheetAccountRow
            v-if="showHeadTotal && (head.groups ?? []).some(groupHasAccounts)"
            :label="`${head.name} Total`"
            variant="total"
            :level="baseLevel - 1"
            :amount="head.total"
            :values="head.by_branch"
            :columns="cellColumns()"
        />
    </div>
</template>
