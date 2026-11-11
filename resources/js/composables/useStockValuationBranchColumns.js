import { computed, h } from 'vue';
import { formatBranchAmount } from '@/lib/branchReport.js';
import { formatBranchQuantity } from '@/lib/stockBranchReport.js';
import { useBranchReportColumns } from '@/composables/useBranchReportColumns';

/**
 * The by-branch columns of the Stock Valuation report (plan 12, section 5.9,
 * BR15), from the page's `branchReport` prop. The server sends `columns`
 * ([{ id, name, code }]) and `totals` ({ "<branchId>": "value" }) only when
 * "Show by branch" is on; each row then has `byBranch`
 * ({ "<branchId>": { quantity, value } }).
 *
 * - `branchColumns`: DataTable column definitions, ids `branch_<branchId>`;
 *   each cell is two lines, quantity above value. Empty without branch columns.
 * - `hasBranchColumns`: true when the extra columns are shown.
 * - `columnsKey`: changes whenever the set of branch columns does; use it as
 *   the DataTable `:key` because that table reads its columns once.
 * - `branchTotals`: [{ id, name, total }] for the footer total line.
 * - `minWidth(basePx, cellPx)`: inline style keeping the table wide enough.
 *
 * @param {() => object|null} getReport the `branchReport` prop
 */
export function useStockValuationBranchColumns(getReport) {
    const { byBranch, columns, minWidth } = useBranchReportColumns(getReport);

    const branchColumns = computed(() =>
        columns.value.map((branch) => ({
            id: `branch_${branch.id}`,
            header: branch.name,
            numeric: true,
            cell: ({ row }) => {
                const figures = row.original?.byBranch?.[branch.id];

                return h('div', { class: 'leading-tight' }, [
                    h('div', formatBranchQuantity(figures?.quantity)),
                    h('div', { class: 'text-text-muted' }, formatBranchAmount(figures?.value)),
                ]);
            },
        })),
    );

    const columnsKey = computed(() => branchColumns.value.map((column) => column.id).join(','));

    const branchTotals = computed(() =>
        columns.value.map((branch) => ({
            id: branch.id,
            name: branch.name,
            total: getReport()?.totals?.[branch.id] ?? '0.00',
        })),
    );

    return { hasBranchColumns: byBranch, branchColumns, columnsKey, branchTotals, minWidth };
}
