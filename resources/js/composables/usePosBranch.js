import { computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useBranchField } from '@/composables/useBranchField';
import { useBranchLabel } from '@/composables/useBranchLabel';

/**
 * The branch of a POS terminal (plan 12, sections 5.10 and BR12).
 *
 * A terminal sells into ONE branch at a time, shared by every held cart: the
 * top-bar switcher's branch, else the user's default branch
 * (`BranchFormOptions::defaultId()`). A user who may use several branches of
 * a multi-branch company can change it from the checkout panel; a user with
 * one branch, or a company that is not multi-branch, sees nothing new.
 *
 * Carts are kept in localStorage and may have been filled under another
 * branch (before a switch, or in an earlier session). Items, customers and
 * prices are shared masters, so a cart's lines stay valid in any branch;
 * only its store is branch-bound. `revalidateCarts()` therefore clears every
 * held cart's store that is not one of the terminal branch's stores (the
 * posting would refuse it), and the sale then takes the branch's default
 * store on the server (DocumentBranch). It runs whenever the branch changes
 * and once after the carts are restored from storage.
 *
 * Not multi-branch: no picker, no cart is touched, and `withBranch()` leaves
 * the /sales payload exactly as it was.
 *
 * @param {{
 *   props: { branchOptions: Array, defaultBranchId: number|null, stores: Array },
 *   form: object,
 *   carts: import('vue').Ref<Array>,
 *   activeCartIndex: import('vue').Ref<number>,
 *   toast: Function,
 * }} options
 */
export function usePosBranch({ props, form, carts, activeCartIndex, toast }) {
    const page = usePage();
    const { label } = useBranchLabel();
    const { branchId, showPicker, withBranch, storesForBranch } = useBranchField(
        () => props.branchOptions,
        () => props.defaultBranchId,
    );
    const isMultiBranch = computed(() => page.props.branch?.isMulti === true);

    const storeOptions = computed(() => storesForBranch(() => props.stores).map((store) => ({ value: store.id, label: store.name })));

    function isOutside(storeId, offered) {
        return storeId !== null && storeId !== undefined && !offered.has(storeId);
    }

    /** Clears held stores outside the terminal's branch; returns how many it cleared. */
    function revalidateCarts() {
        if (!isMultiBranch.value) {
            return 0;
        }

        const offered = new Set(storesForBranch(() => props.stores).map((store) => store.id));
        let cleared = 0;

        if (isOutside(form.store_id, offered)) {
            form.store_id = null;
            cleared += 1;
        }

        carts.value.forEach((cart, index) => {
            if (index !== activeCartIndex.value && isOutside(cart.store_id, offered)) {
                cart.store_id = null;
                cleared += 1;
            }
        });

        return cleared;
    }

    function revalidateAndTell() {
        if (revalidateCarts() > 0) {
            toast({
                message: `Held sales now use the default store of this ${label('lower')}.`,
                variant: 'default',
            });
        }
    }

    watch(branchId, revalidateAndTell);

    /**
     * Whether a tile's stock figure may hard-block adding the item. The tiles
     * show the top-bar scope's stock (BR22 C1); when the checkout picker lets
     * the cashier sell from another branch, that figure may not be the chosen
     * branch's, so Out/Low badges and the overstock warning stay advisory
     * (Sale::post enforces stock per store). No picker or no scope: unchanged.
     */
    const stockBlocksSale = computed(() => !(showPicker.value && (page.props.stockScope ?? null) !== null));

    return { branchId, showPicker, withBranch, storeOptions, revalidateAndTell, stockBlocksSale };
}
