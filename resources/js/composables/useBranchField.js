import { computed, ref, toValue, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * The branch field of a document create form (plan 12, section 5.10, BR12).
 *
 * Pass the page's `branchOptions` and `defaultBranchId` props (from
 * `BranchFormOptions::props()` on the server) as refs, getters or plain
 * values:
 *
 *     const { branchId, showPicker, withBranch } = useBranchField(
 *         () => props.branchOptions,
 *         () => props.defaultBranchId,
 *     );
 *
 * - `branchId`: ref holding the chosen branch id. Starts on the default and
 *   goes back to it whenever the default or the top-bar switcher's branch
 *   changes (the switcher reloads the page without preserving state, so a
 *   page that stays mounted still re-seeds), or when the chosen id is no
 *   longer offered.
 * - `showPicker`: true only for a multi-branch company and a user with more
 *   than one branch to choose from. Bind `BranchSelect` to it.
 * - `withBranch(data)`: returns `data` with `branch_id` added when the picker
 *   shows, and without any `branch_id` key otherwise, so a single-branch
 *   company submits exactly the payload it did before branches existed. Use
 *   it inside `form.transform()`.
 * - `storesForBranch(stores)`: the stores of the chosen branch (each store
 *   needs `branch_id`); all of them when the picker is hidden.
 * - `keepStoreInBranch(stores, getStoreId, setStoreId, pick?)`: watches the
 *   branch and, when the chosen store is not one of the branch's stores,
 *   replaces it with `pick(storesOfBranch)` (default: the first one, else
 *   null). Call once in setup.
 *
 * UI only: the server resolves and checks the branch (DocumentBranch).
 */
export function useBranchField(options, defaultId) {
    const page = usePage();

    const branchOptions = computed(() => toValue(options) ?? []);
    const defaultBranchId = computed(() => toValue(defaultId) ?? null);
    const isMultiBranch = computed(() => page.props.branch?.isMulti === true);
    const showPicker = computed(() => isMultiBranch.value && branchOptions.value.length > 1);

    const branchId = ref(defaultBranchId.value);

    function isOffered(id) {
        return branchOptions.value.some((option) => option.id === id);
    }

    // Two sources, compared one by one: every Inertia visit (a failed submit
    // included) replaces page.props, and a single array getter would see a
    // "new" value each time and throw away the user's choice.
    watch([defaultBranchId, () => page.props.branch?.current ?? null], () => {
        branchId.value = defaultBranchId.value;
    });

    watch(branchOptions, () => {
        if (branchId.value !== null && !isOffered(branchId.value)) {
            branchId.value = defaultBranchId.value;
        }
    });

    function withBranch(data) {
        const rest = { ...data };
        delete rest.branch_id;

        return showPicker.value ? { ...rest, branch_id: branchId.value } : rest;
    }

    function storesForBranch(stores) {
        const list = toValue(stores) ?? [];

        if (!showPicker.value || branchId.value === null) {
            return list;
        }

        return list.filter((store) => store.branch_id === branchId.value);
    }

    function keepStoreInBranch(stores, getStoreId, setStoreId, pick = (inBranch) => inBranch[0]?.id ?? null) {
        watch(
            branchId,
            () => {
                if (!showPicker.value) {
                    return;
                }

                const inBranch = storesForBranch(stores);

                if (!inBranch.some((store) => store.id === getStoreId())) {
                    setStoreId(pick(inBranch));
                }
            },
            { immediate: true },
        );
    }

    return {
        branchId,
        branchOptions,
        defaultBranchId,
        showPicker,
        withBranch,
        storesForBranch,
        keepStoreInBranch,
    };
}
