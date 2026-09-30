/**
 * Pure selection logic for the role editor's permission matrix
 * (Tenant/Admin/Roles). Kept out of the components so it can be unit tested
 * with node --test.
 *
 * Dependency rule (ROUTE-MAP "Shared lookup endpoints" item 7): the create
 * form for sales, purchases, returns and so on lives on the index page, so
 * `X.create` is useless without `X.view`. Ticking any non-view key of a
 * resource therefore also ticks that resource's `.view` key when the matrix
 * offers one, and unticking `.view` unticks the resource's other keys. This
 * is a convenience only: the server does not enforce it.
 *
 * A key's resource is the part before the first dot (`sales.create` ->
 * `sales`). Every function returns a fresh array in the order of `available`
 * (the matrix display order), never mutating its inputs.
 */

/**
 * @param {string} key
 * @returns {string}
 */
export function resourceOf(key) {
    const dot = key.indexOf('.');

    return dot === -1 ? key : key.slice(0, dot);
}

/**
 * @param {string} key
 * @returns {string}
 */
export function viewKeyOf(key) {
    return `${resourceOf(key)}.view`;
}

/**
 * @param {string} key
 * @returns {boolean}
 */
export function isViewKey(key) {
    return key === viewKeyOf(key);
}

/**
 * Every permission key in a module tree, in display order.
 *
 * @param {Array<{groups: Array<{permissions: Array<{key: string}>}>}>} modules
 * @returns {string[]}
 */
export function keysOf(modules) {
    const keys = [];
    for (const module of Array.isArray(modules) ? modules : []) {
        for (const group of module.groups ?? []) {
            for (const permission of group.permissions ?? []) {
                keys.push(permission.key);
            }
        }
    }

    return keys;
}

/**
 * Tick or untick several keys at once (a single checkbox, or select-all for
 * a group or module), applying the view dependency rule. Keys not in
 * `available` are ignored, and anything in `selected` that is not available
 * is dropped from the result.
 *
 * @param {string[]} selected  Currently ticked keys.
 * @param {string[]} keys  Keys to change.
 * @param {boolean} checked  Tick (true) or untick (false).
 * @param {string[]} available  Every key the matrix shows, in display order.
 * @returns {string[]}
 */
export function setKeys(selected, keys, checked, available) {
    const offered = new Set(available);
    const next = new Set((selected ?? []).filter((key) => offered.has(key)));

    for (const key of keys) {
        if (!offered.has(key)) {
            continue;
        }

        if (checked) {
            next.add(key);
            const view = viewKeyOf(key);
            if (offered.has(view)) {
                next.add(view);
            }
            continue;
        }

        next.delete(key);
        if (isViewKey(key)) {
            const resource = resourceOf(key);
            for (const other of available) {
                if (resourceOf(other) === resource) {
                    next.delete(other);
                }
            }
        }
    }

    return available.filter((key) => next.has(key));
}

/**
 * @param {string[]} selected
 * @param {string} key
 * @param {boolean} checked
 * @param {string[]} available
 * @returns {string[]}
 */
export function toggleKey(selected, key, checked, available) {
    return setKeys(selected, [key], checked, available);
}

/**
 * How many of `keys` are ticked, for a select-all checkbox: 'all', 'some'
 * (render as indeterminate) or 'none'. An empty list is 'none'.
 *
 * @param {string[]} selected
 * @param {string[]} keys
 * @returns {'all'|'some'|'none'}
 */
export function selectionState(selected, keys) {
    if (keys.length === 0) {
        return 'none';
    }

    const ticked = new Set(selected);
    const count = keys.filter((key) => ticked.has(key)).length;

    if (count === 0) {
        return 'none';
    }

    return count === keys.length ? 'all' : 'some';
}

/**
 * Keys whose ticked state differs from what was saved, so the matrix can
 * highlight them and the page can count unsaved changes.
 *
 * @param {string[]} original
 * @param {string[]} selected
 * @returns {Set<string>}
 */
export function changedKeys(original, selected) {
    const before = new Set(original ?? []);
    const after = new Set(selected ?? []);
    const changed = new Set();

    for (const key of before) {
        if (!after.has(key)) {
            changed.add(key);
        }
    }
    for (const key of after) {
        if (!before.has(key)) {
            changed.add(key);
        }
    }

    return changed;
}
