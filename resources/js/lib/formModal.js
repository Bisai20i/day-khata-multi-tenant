/**
 * Pure helpers behind the add/edit form modals (useCrudModal, QuickAddModal,
 * ItemsIndexFormModal). Kept free of Vue and of the "@/" alias so they run
 * under `node --test` like the rest of resources/js/lib.
 */

/**
 * Stable string for a form's data, used to tell whether the user changed
 * anything since the modal opened. A chosen File has no JSON form of its own
 * (it stringifies to "{}"), so it is replaced by its name/size/mtime.
 *
 * @param {Record<string, unknown>} data
 * @returns {string}
 */
export function formSnapshot(data) {
    return JSON.stringify(data, (key, value) => {
        if (typeof File !== 'undefined' && value instanceof File) {
            return `file:${value.name}:${value.size}:${value.lastModified}`;
        }

        return value;
    });
}

/**
 * True when any of the given fields has a server validation error.
 *
 * @param {Record<string, string>} errors
 * @param {string[]} fields
 * @returns {boolean}
 */
export function hasAnyError(errors, fields) {
    return fields.some((field) => Boolean(errors?.[field]));
}

/**
 * True when the record carries a real value in any of the given fields. null,
 * undefined and '' count as empty; 0 and false are values.
 *
 * @param {Record<string, unknown>|null} record
 * @param {string[]} fields
 * @returns {boolean}
 */
export function hasAnyValue(record, fields) {
    return fields.some((field) => {
        const value = record?.[field];

        return value !== null && value !== undefined && value !== '';
    });
}

/**
 * The record a quick-add just created, picked out of freshly reloaded props.
 * The store endpoints redirect rather than return the new row, so it is
 * matched by name (trimmed, case-insensitive) plus any scoping fields, e.g.
 * `{ item_category_id: 3 }` for a subcategory. The highest id wins if an
 * older row happens to match too.
 *
 * @param {Array<Record<string, unknown>>} records
 * @param {string} name
 * @param {Record<string, unknown>} [scope]
 * @returns {Record<string, unknown>|null}
 */
export function findCreatedByName(records, name, scope = {}) {
    const wanted = String(name ?? '').trim().toLowerCase();
    if (wanted === '') return null;

    const matches = (records ?? []).filter(
        (record) =>
            String(record.name ?? '').trim().toLowerCase() === wanted &&
            Object.entries(scope).every(([field, value]) => record[field] === value),
    );

    return matches.sort((a, b) => b.id - a.id)[0] ?? null;
}
