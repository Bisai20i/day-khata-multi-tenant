import { computed, toValue, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useBranchField } from '@/composables/useBranchField';

/**
 * Branch picker plus store dropdown of a document that moves stock (sale,
 * unlinked sales return, capital sale; plan 12, sections 5.5 and 5.10, BR12).
 * Built on `useBranchField`; the server's `OffersDocumentBranch` trait sends
 * the stores with their `branch_id` and `is_branch_default` for a
 * multi-branch company.
 *
 *     const { branchId, showPicker, withBranch, storeChoices } = useDocumentBranchStore({
 *         branchOptions: () => props.branchOptions,
 *         defaultBranchId: () => props.defaultBranchId,
 *         stores: () => props.stores,
 *         getStoreId: () => form.store_id,
 *         setStoreId: (id) => { form.store_id = id; },
 *         preferredStoreId: () => props.invoiceSettings.default_store_id,
 *     });
 *
 * - The store follows the branch: when the chosen store is not one of the
 *   branch's stores it is replaced by the preferred store (the company
 *   default) if it is in the branch, else the branch's default store, else
 *   the branch's first store. A store already in the branch is kept, so a
 *   store the user picked always wins.
 * - `adoptStoreBranch`: for a restored draft, whose store was the user's own
 *   choice, the branch starts on that store's branch instead of the default
 *   (DocumentBranch files a document in its store's branch).
 * - A multi-branch user limited to one branch sees no picker, and the server
 *   only sends that branch's stores; a default store outside them is swapped
 *   the same way, so the form never submits a store the posting would refuse.
 * - `storeChoices`: the stores to offer (the branch's, or all of them when
 *   the picker is hidden).
 *
 * A company that is not multi-branch gets none of this: no picker, no store
 * change, and `withBranch()` leaves the payload exactly as it was.
 */
export function useDocumentBranchStore({
    branchOptions,
    defaultBranchId,
    stores,
    getStoreId,
    setStoreId,
    preferredStoreId = null,
    adoptStoreBranch = false,
}) {
    const page = usePage();
    const field = useBranchField(branchOptions, defaultBranchId);
    const isMultiBranch = computed(() => page.props.branch?.isMulti === true);

    function pick(inBranch) {
        const preferred = toValue(preferredStoreId) ?? null;
        const chosen = inBranch.find((store) => store.id === preferred) ?? inBranch.find((store) => store.is_branch_default) ?? inBranch[0];

        return chosen?.id ?? null;
    }

    if (adoptStoreBranch && field.showPicker.value) {
        const restored = (toValue(stores) ?? []).find((store) => store.id === getStoreId());

        if (restored && field.branchOptions.value.some((option) => option.id === restored.branch_id)) {
            field.branchId.value = restored.branch_id;
        }
    }

    field.keepStoreInBranch(stores, getStoreId, setStoreId, pick);

    watch(
        () => toValue(stores) ?? [],
        (list) => {
            if (!isMultiBranch.value || field.showPicker.value) {
                return;
            }

            const current = getStoreId() ?? null;

            if (current !== null && !list.some((store) => store.id === current)) {
                setStoreId(pick(list));
            }
        },
        { immediate: true },
    );

    const storeChoices = computed(() => field.storesForBranch(stores));

    return { ...field, isMultiBranch, storeChoices };
}
