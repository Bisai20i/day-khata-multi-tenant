/**
 * Pure permission helpers shared by the usePermissions composable and the nav
 * filter. The UI only hides things; the server gate is the authority.
 */

/**
 * @param {string[]|null|undefined} list  `auth.can` from the shared props.
 * @param {boolean} [isOwner]  `auth.isOwner`, passed through untouched (the
 *   list already reflects the owner's entitlements, so it grants nothing).
 */
export function createPermissionChecker(list, isOwner = false) {
    const granted = new Set(Array.isArray(list) ? list : []);

    return {
        isOwner: Boolean(isOwner),
        can: (key) => granted.has(key),
        canAny: (keys) => Array.isArray(keys) && keys.some((key) => granted.has(key)),
    };
}
