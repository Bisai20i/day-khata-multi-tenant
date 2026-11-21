<script setup>
import TrialBalanceRow from '@/components/accounting/TrialBalanceRow.vue';

/**
 * One trial balance head (Assets, Liabilities ...) laid out as
 * Head -> Group -> Sub-group -> Ledger, with a bold "<name> Total" line after
 * every sub-group and group that has accounts and a "Total <head>" line.
 *
 * - `head`: { name, totals, by_branch, groups[] { name, totals, by_branch,
 *   accounts[], subgroups[] { name, totals, by_branch, accounts[] } } }; every
 *   account carries the six figures and `by_branch`.
 * - `columns`: by-branch columns; `hasBranchColumns` guards the extra cells.
 */
const props = defineProps({
    head: { type: Object, required: true },
    columns: { type: Array, default: () => [] },
    hasBranchColumns: { type: Boolean, default: false },
    canLedger: { type: Boolean, default: false },
    fiscalYearId: { type: [Number, null], default: null },
    from: { type: [String, null], default: null },
    to: { type: [String, null], default: null },
    // Branch scope query string (no leading "?") appended to ledger links.
    ledgerQuery: { type: String, default: '' },
});

function subgroupHasAccounts(subgroup) {
    return (subgroup.accounts ?? []).length > 0;
}

function groupHasAccounts(group) {
    return (group.accounts ?? []).length > 0 || (group.subgroups ?? []).some(subgroupHasAccounts);
}

// The server prunes children at the Group and Sub group levels. A node left with
// no children is one line carrying its own figures (no heading + total pair).
function isSummaryLine(node) {
    return (node.accounts ?? []).length === 0 && (node.subgroups ?? []).length === 0 && Boolean(node.totals);
}

function groupHasTotal(group) {
    return groupHasAccounts(group) || (group.subgroups ?? []).length > 0;
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
        <TrialBalanceRow :label="head.name" variant="heading" :level="0" />
        <template v-for="group in head.groups ?? []" :key="group.name">
            <TrialBalanceRow
                v-if="isSummaryLine(group)"
                :label="group.name"
                variant="total"
                :level="1"
                :totals="group.totals"
                :values="group.by_branch"
                :columns="cellColumns()"
            />
            <template v-else>
                <TrialBalanceRow :label="group.name" variant="heading" :level="1" />
                <TrialBalanceRow
                    v-for="account in group.accounts"
                    :key="accountKey(account)"
                    :account="account"
                    :level="2"
                    :columns="cellColumns()"
                    :can-ledger="canLedger"
                    :fiscal-year-id="fiscalYearId"
                    :from="from"
                    :to="to"
                    :ledger-query="ledgerQuery"
                />
                <template v-for="subgroup in group.subgroups" :key="subgroup.name">
                    <TrialBalanceRow
                        v-if="isSummaryLine(subgroup)"
                        :label="subgroup.name"
                        variant="total"
                        :level="2"
                        :totals="subgroup.totals"
                        :values="subgroup.by_branch"
                        :columns="cellColumns()"
                    />
                    <template v-else>
                        <TrialBalanceRow :label="subgroup.name" variant="heading" :level="2" />
                        <TrialBalanceRow
                            v-for="account in subgroup.accounts"
                            :key="accountKey(account)"
                            :account="account"
                            :level="3"
                            :columns="cellColumns()"
                            :can-ledger="canLedger"
                            :fiscal-year-id="fiscalYearId"
                            :from="from"
                            :to="to"
                            :ledger-query="ledgerQuery"
                        />
                        <TrialBalanceRow
                            v-if="subgroupHasAccounts(subgroup)"
                            :label="`${subgroup.name} Total`"
                            variant="total"
                            :level="2"
                            :totals="subgroup.totals"
                            :values="subgroup.by_branch"
                            :columns="cellColumns()"
                        />
                    </template>
                </template>
                <TrialBalanceRow
                    v-if="groupHasTotal(group)"
                    :label="`${group.name} Total`"
                    variant="total"
                    :level="1"
                    :totals="group.totals"
                    :values="group.by_branch"
                    :columns="cellColumns()"
                />
            </template>
        </template>
        <TrialBalanceRow
            v-if="(head.groups ?? []).some((group) => groupHasTotal(group) || isSummaryLine(group))"
            :label="`Total ${head.name}`"
            variant="total"
            :level="0"
            :totals="head.totals"
            :values="head.by_branch"
            :columns="cellColumns()"
        />
    </div>
</template>
