import { computed } from 'vue';
import { formatMoney } from '@/lib/money.js';
import { useBranchReportColumns } from '@/composables/useBranchReportColumns';

/**
 * The by-branch balance columns of the Debtors and Creditors reports (plan
 * 12, section 5.9, BR14), from the page's `branchReport` prop and `groups`.
 *
 * Each branch column shows the party balance in that branch, formatted like
 * the page's own balance cell. The server sends `columns` ([{ id, name,
 * code }]) and `totals` ({ "<branchId>": "0.00" }) only when "Show by branch"
 * is on; every row then has `by_branch` and every area group
 * `by_branch_totals`.
 *
 * - `branchColumns`: DataTable / AreaGroupedTable column definitions, ids
 *   `branch_<branchId>`; empty without branch columns.
 * - `branchSubtotalIds`: the same ids, for `AreaGroupedTable`'s
 *   `subtotal-columns`.
 * - `groupsWithBranchTotals`: `groups` with each group's `by_branch_totals`
 *   merged into its `totals` under those ids (the grouped table reads
 *   subtotals from `totals[columnId]`). Returned untouched without branch
 *   columns.
 * - `branchTotals`: [{ id, name, total }] for the footer total line.
 * - `minWidth(basePx, cellPx)`: inline style keeping the table wide enough,
 *   or undefined without branch columns.
 *
 * @param {() => object|null} getReport the `branchReport` prop
 * @param {() => object[]|null} getGroups the `groups` prop
 */
export function usePartyBranchColumns(getReport, getGroups) {
    const { byBranch, columns, minWidth } = useBranchReportColumns(getReport);

    const branchColumns = computed(() =>
        columns.value.map((branch) => ({
            id: `branch_${branch.id}`,
            header: branch.name,
            numeric: true,
            cell: ({ row }) => formatMoney(row.original?.by_branch?.[branch.id] ?? '0.00'),
        })),
    );

    const branchSubtotalIds = computed(() => branchColumns.value.map((column) => column.id));

    const groupsWithBranchTotals = computed(() => {
        const groups = getGroups() ?? [];

        if (!byBranch.value) {
            return groups;
        }

        return groups.map((group) => {
            const totals = { ...group.totals };

            for (const branch of columns.value) {
                totals[`branch_${branch.id}`] = group.by_branch_totals?.[branch.id] ?? '0.00';
            }

            return { ...group, totals };
        });
    });

    const branchTotals = computed(() =>
        columns.value.map((branch) => ({
            id: branch.id,
            name: branch.name,
            total: getReport()?.totals?.[branch.id] ?? '0.00',
        })),
    );

    return { hasBranchColumns: byBranch, branchColumns, branchSubtotalIds, groupsWithBranchTotals, branchTotals, minWidth };
}
