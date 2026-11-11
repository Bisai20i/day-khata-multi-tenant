/**
 * The label of a store in a dropdown or list (plan 12, section 5.10, BR12):
 * "Store (Branch)" when the store came with its branch, else just the name.
 *
 * The server attaches `branch` ({ id, name, code }) to a store only for a
 * multi-branch company (BranchStoreOptions::apply(..., withBranch: true)),
 * so a single-branch company sees exactly the plain store names it saw before.
 */
export function storeLabel(store) {
    if (!store) {
        return '';
    }

    return store.branch?.name ? `${store.name} (${store.branch.name})` : store.name;
}
