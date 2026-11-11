import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useBranchLabel } from '@/composables/useBranchLabel';

/**
 * Labels an on-hand stock figure with what it covers (plan 12, BR22 C1), from
 * the page's server-sent `stockScope` prop (BranchStockDisplay::scope()):
 *
 * - null / missing: not a multi-branch company, labels stay exactly as they
 *   were (no suffix);
 * - 'all': the company-wide total, for an unrestricted user on all branches;
 * - 'branch': one branch's stores;
 * - 'branches': a restricted user's several branches.
 *
 * Read from the page props rather than threaded through every component, so
 * a nested line table or tile panel labels its figure the same way as the
 * page that rendered it. Uses the company's own word for a branch.
 */
export function useStockScopeLabel() {
    const page = usePage();
    const { label, plural } = useBranchLabel();

    const stockScopeSuffix = computed(() => {
        switch (page.props.stockScope) {
            case 'all':
                return ` (all ${plural('lower')})`;
            case 'branch':
                return ` (this ${label('lower')})`;
            case 'branches':
                return ` (your ${plural('lower')})`;
            default:
                return '';
        }
    });

    return {
        stockScopeSuffix,
        /** "In stock" -> "In stock (this branch)"; unchanged when not multi-branch. */
        withStockScope: (text) => `${text}${stockScopeSuffix.value}`,
    };
}
