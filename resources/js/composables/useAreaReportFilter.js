import { ref } from 'vue';

/**
 * The Area filter state every area-aware report page keeps next to its own
 * filters (fiscal year, as-of date, store): the dropdown value and the
 * "Group by area" tick, seeded from what the server applied.
 *
 * `toQuery()` returns the two query params, omitting the defaults so a plain
 * report keeps a clean URL; spread it into the page's own query. The export
 * link uses `toQuery(applied)` with the props the server confirmed, not the
 * unsaved dropdown, so the file always matches the table on screen.
 *
 * @param {{ area?: string, groupByArea?: boolean }} initial the server's applied filter
 */
export function useAreaReportFilter(initial) {
    const area = ref(initial.area ?? '');
    const groupByArea = ref(Boolean(initial.groupByArea));

    /**
     * @param {{ area?: string, groupByArea?: boolean }} [source] defaults to the current (possibly unsaved) selection
     * @returns {{ area?: string, group_by_area?: number }}
     */
    function toQuery(source = { area: area.value, groupByArea: groupByArea.value }) {
        const query = {};

        if (source.area) query.area = source.area;
        if (source.groupByArea) query.group_by_area = 1;

        return query;
    }

    return { area, groupByArea, toQuery };
}
