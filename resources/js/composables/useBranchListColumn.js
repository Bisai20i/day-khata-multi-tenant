import { computed, h } from 'vue';
import { usePage } from '@inertiajs/vue3';
import BranchBadge from '@/components/branch/BranchBadge.vue';
import { useBranchLabel } from '@/composables/useBranchLabel';

/**
 * The branch column of a document list (plan 12, section 5.10, BR12).
 *
 * - `showBranchColumn`: true for a multi-branch company viewed as "All
 *   branches". Narrowed to one branch, the server lists only that branch's
 *   rows (BranchListFilter), so the column would only repeat the switcher.
 *   Mirrors `BranchListFilter::columnVisible()`, which also decides whether
 *   the rows carry their `branch` ({ id, name, code }).
 * - `branchColumns()`: a one-item array holding the DataTable column (a
 *   BranchBadge per row), or an empty array when the column is hidden, so a
 *   page can spread it into its column list: `[...before, ...branchColumns(), ...after]`.
 */
export function useBranchListColumn() {
    const page = usePage();
    const { label } = useBranchLabel();

    const showBranchColumn = computed(() => page.props.branch?.isMulti === true && page.props.branch?.isAll === true);

    function branchColumns() {
        if (!showBranchColumn.value) {
            return [];
        }

        return [
            {
                id: 'branch',
                header: label(),
                numeric: false,
                cell: ({ row }) => h(BranchBadge, { branch: row.original.branch ?? null, branchId: row.original.branch_id ?? null }),
            },
        ];
    }

    return { showBranchColumn, branchColumns };
}
