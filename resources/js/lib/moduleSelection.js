/**
 * Pure dependency logic for the central module checkboxes (tenant create and
 * tenant show pages). Mirrors PermissionCatalog::resolveModules() on the
 * server so what the admin sees ticked is what will be stored. The server
 * still resolves requirements itself on every save: this only keeps the
 * checkboxes honest, it is not trusted.
 *
 * A catalog is the list the controller sends, in config order:
 * [{ key, label, always_on, requires: [keys] }].
 */

function indexCatalog(catalog) {
    const byKey = new Map();
    for (const module of Array.isArray(catalog) ? catalog : []) {
        byKey.set(module.key, module);
    }

    return byKey;
}

/**
 * Keys in catalog order, keeping only those in the given set. Unknown keys
 * drop out here, as they do on the server.
 */
function inCatalogOrder(catalog, keySet) {
    return (Array.isArray(catalog) ? catalog : []).map((module) => module.key).filter((key) => keySet.has(key));
}

/**
 * The selection plus every always_on module plus everything those require,
 * transitively (cycle safe), in catalog order.
 */
export function resolveModules(catalog, selected) {
    const byKey = indexCatalog(catalog);
    const pending = (Array.isArray(selected) ? selected : []).filter((key) => byKey.has(key));
    for (const module of byKey.values()) {
        if (module.always_on) {
            pending.push(module.key);
        }
    }

    const resolved = new Set();
    while (pending.length > 0) {
        const key = pending.pop();
        if (resolved.has(key) || !byKey.has(key)) {
            continue;
        }
        resolved.add(key);
        pending.push(...(byKey.get(key).requires ?? []));
    }

    return inCatalogOrder(catalog, resolved);
}

/**
 * Every module that needs the given one, directly or through a chain
 * (agents needs sales, so switching sales off must switch agents off too).
 * The module itself is not included.
 */
export function dependentsOf(catalog, key) {
    const list = Array.isArray(catalog) ? catalog : [];
    // Requirement closures only: with always_on cleared, a module's closure
    // is itself plus what it needs, not every always_on module as well.
    const requiresOnly = list.map((entry) => ({ ...entry, always_on: false }));
    const dependents = new Set();

    for (const module of list) {
        if (module.key === key || module.always_on) {
            continue;
        }
        if (resolveModules(requiresOnly, [module.key]).includes(key)) {
            dependents.add(module.key);
        }
    }

    return inCatalogOrder(catalog, dependents);
}

/**
 * Ticks or unticks one module and returns the next selection.
 *
 * Ticking adds the module and everything it requires. Unticking removes it
 * and every selected module that depends on it, and reports those dependents
 * in `removed` so the UI can say why they went away. always_on modules
 * cannot be unticked. The returned selection is always resolved (always_on
 * included) and in catalog order.
 *
 * @returns {{ selected: string[], removed: string[] }}
 */
export function toggleModule(catalog, selected, key, checked) {
    const current = resolveModules(catalog, selected);
    const module = indexCatalog(catalog).get(key);

    if (module === undefined) {
        return { selected: current, removed: [] };
    }

    if (checked) {
        return { selected: resolveModules(catalog, [...current, key]), removed: [] };
    }

    if (module.always_on) {
        return { selected: current, removed: [] };
    }

    const dependents = dependentsOf(catalog, key);
    const removed = current.filter((candidate) => dependents.includes(candidate));
    const drop = new Set([key, ...removed]);

    return {
        selected: resolveModules(
            catalog,
            current.filter((candidate) => !drop.has(candidate)),
        ),
        removed,
    };
}

/**
 * Labels of the modules a module requires, for the "Needs ..." hint.
 */
export function requirementLabels(catalog, key) {
    const byKey = indexCatalog(catalog);

    return (byKey.get(key)?.requires ?? []).filter((required) => byKey.has(required)).map((required) => byKey.get(required).label);
}
