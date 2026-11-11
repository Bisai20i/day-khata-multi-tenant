import { computed } from 'vue';

/**
 * The by-branch columns of the trial balance, income statement and balance
 * sheet (plan 12, section 5.9, BR13), from the page's `branchReport` prop.
 *
 * - `byBranch`: true only when the server sent at least one column (the
 *   "Show by branch" tick was on and the report supports it). Every page
 *   guards its extra cells with it, so without it the page is unchanged.
 * - `columns`: [{ id, name, code }], display order.
 * - `totalValues(key)`: { "<branchId>": totals[branchId][key] }, to feed a
 *   `BranchReportCells` totals row (zero where the server sent nothing).
 * - `totalOf(branchId)`: the raw per-branch totals object, or null.
 * - `unbalancedIds`: columns whose `inBalance` / `balanced` total is false,
 *   for the warning marker in the column header.
 * - `minWidth(basePx, cellPx)`: an inline style that keeps a table wide
 *   enough for its columns (inside a horizontally scrolling box), or
 *   undefined when there are no branch columns.
 *
 * @param {() => object|null} getReport the `branchReport` prop
 */
export function useBranchReportColumns(getReport) {
    const report = computed(() => getReport() ?? null);

    const columns = computed(() => {
        const current = report.value;

        return current?.byBranch === true && Array.isArray(current.columns) ? current.columns : [];
    });

    const byBranch = computed(() => columns.value.length > 0);
    const totals = computed(() => report.value?.totals ?? {});

    function totalOf(branchId) {
        return totals.value[branchId] ?? null;
    }

    function totalValues(key) {
        const values = {};

        for (const column of columns.value) {
            values[column.id] = totalOf(column.id)?.[key] ?? '0.00';
        }

        return values;
    }

    const unbalancedIds = computed(() =>
        columns.value
            .filter((column) => {
                const columnTotals = totalOf(column.id);

                return columnTotals?.inBalance === false || columnTotals?.balanced === false;
            })
            .map((column) => column.id),
    );

    function minWidth(basePx, cellPx) {
        return byBranch.value ? { minWidth: `${basePx + columns.value.length * cellPx}px` } : undefined;
    }

    return { byBranch, columns, totalValues, totalOf, unbalancedIds, minWidth };
}
