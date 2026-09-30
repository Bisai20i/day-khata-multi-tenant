/**
 * Filter tenant nav sections by permission. An item may carry `permission`
 * (one key) or `permissions` (any-of); items with neither are always shown.
 * Sections are `{ label, items }` or `{ label, categories: [{ label, items }] }`;
 * categories and sections left empty are dropped. Never mutates the input.
 *
 * @param {Array} sections
 * @param {(key: string) => boolean} can
 */
export function filterNavByPermission(sections, can) {
    const allowed = (item) => {
        if (typeof item.permission === 'string') {
            return can(item.permission);
        }
        if (Array.isArray(item.permissions)) {
            return item.permissions.some((key) => can(key));
        }
        return true;
    };

    const result = [];
    for (const section of sections) {
        if (Array.isArray(section.categories)) {
            const categories = section.categories
                .map((category) => ({ ...category, items: category.items.filter(allowed) }))
                .filter((category) => category.items.length > 0);
            if (categories.length > 0) {
                result.push({ ...section, categories });
            }
        } else if (Array.isArray(section.items)) {
            const items = section.items.filter(allowed);
            if (items.length > 0) {
                result.push({ ...section, items });
            }
        } else {
            result.push(section);
        }
    }
    return result;
}
