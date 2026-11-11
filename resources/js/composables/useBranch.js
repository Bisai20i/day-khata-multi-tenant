import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';

/** The "All branches" selection (read-only views; forms fall back to the user's default). */
export const ALL_BRANCHES = 'all';

/**
 * The signed-in user's branch context, from the shared `branch` prop set by
 * HandleInertiaRequests (plan 12, section 5.10).
 *
 * - `current`: the selected branch id, `'all'`, or null (not multi-branch, or
 *   no usable branch). A user who may view every branch and has no default
 *   branch counts as viewing 'all' until they pick one.
 * - `branches`: the active branches the user may use (id, name, code, is_main).
 * - `canViewAll`: whether "All branches" may be chosen (unrestricted users).
 * - `isMultiBranch`: the `branches` module is on and more than one active
 *   branch exists. Otherwise every branch control stays hidden, so a
 *   Main-only company looks unchanged.
 * - `switching`: true while a switch request is in flight.
 *
 * UI only. The server re-checks the selection on every request and decides
 * what the user may read or post.
 */
export function useBranch() {
    const page = usePage();
    const { toast } = useToast();
    const switching = ref(false);

    const branchProps = computed(() => page.props.branch ?? null);

    const branches = computed(() => branchProps.value?.allowed ?? []);
    const current = computed(() => branchProps.value?.current ?? null);
    const canViewAll = computed(() => branchProps.value?.canViewAll === true);
    const isMultiBranch = computed(() => branchProps.value?.isMulti === true);
    const isAllSelected = computed(() => branchProps.value?.isAll === true);
    const currentBranch = computed(() => branches.value.find((branch) => branch.id === current.value) ?? null);

    /**
     * Select a branch id or 'all'. Saved in the session by the server, which
     * then redirects back, so the current page reloads for the new branch.
     * State is not preserved: page-local state seeded from props (branch
     * selectors, filters) must re-seed for the new branch. A refused switch
     * (stale list, branch just deactivated) shows the server's message.
     */
    function switchBranch(branch, options = {}) {
        if (switching.value || branch === current.value) {
            return;
        }

        router.put('/active-branch', { branch }, {
            preserveScroll: true,
            preserveState: false,
            onStart: () => {
                switching.value = true;
            },
            onFinish: () => {
                switching.value = false;
            },
            onError: (errors) => toast({
                message: errors.branch ?? Object.values(errors)[0] ?? 'Could not switch branch.',
                variant: 'danger',
            }),
            ...options,
        });
    }

    return {
        branches,
        current,
        currentBranch,
        canViewAll,
        isMultiBranch,
        isAllSelected,
        switching,
        switchBranch,
    };
}
