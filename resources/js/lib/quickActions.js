/**
 * The layout's "create something" shortcuts, in one list that drives both the
 * header "+" Quick Create menu and the command palette's "Quick Actions"
 * group, so the two can never drift apart or disagree on who sees what.
 *
 * Icon-free on purpose (`icon` is a name the layout maps to a component) so
 * this module stays plain data and can be unit tested under `node --test`
 * without loading Vue.
 *
 * `permissions` lists catalog keys that must ALL be held. The UI only hides
 * the shortcut; each target route is still gated server-side by `can:`.
 */
export const QUICK_CREATE_ACTIONS = Object.freeze([
    { label: 'New sale', href: '/sales', icon: 'sale', permissions: ['sales.create'] },
    { label: 'New purchase', href: '/purchases', icon: 'purchase', permissions: ['purchases.create'] },
    { label: 'New quotation', href: '/quotations', icon: 'quotation', permissions: ['quotations.create'] },
    { label: 'New customer', href: '/customers', icon: 'customer', permissions: ['customers.create'] },
    { label: 'New item', href: '/items', icon: 'item', permissions: ['items.create'] },
]);

/** The header POS shortcut's requirement. */
export const POS_PERMISSIONS = Object.freeze(['pos.view']);

/**
 * Whether every key in `permissions` is granted. Fails closed: an action that
 * forgot to declare its keys (missing or empty list) is hidden, not shown.
 *
 * @param {string[]|undefined} permissions
 * @param {(key: string) => boolean} can
 */
export function holdsAll(permissions, can) {
    return Array.isArray(permissions) && permissions.length > 0 && permissions.every((key) => can(key));
}

/**
 * The actions the user may use, in their original order. Never mutates the
 * input.
 *
 * @template {{ permissions?: string[] }} T
 * @param {readonly T[]} actions
 * @param {(key: string) => boolean} can
 * @returns {T[]}
 */
export function allowedActions(actions, can) {
    return actions.filter((action) => holdsAll(action.permissions, can));
}
