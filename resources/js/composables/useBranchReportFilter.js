import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { ALL_BRANCHES } from '@/composables/useBranch';

/** Choice for a branch list the URL carried (`branches[]`) that one dropdown entry cannot name. */
export const SELECTED_BRANCHES = 'selected';

/**
 * The branch filter state of an accounting report page (plan 12, section
 * 5.9, BR13), seeded from the page's `branchReport` prop.
 *
 *     const branchFilter = useBranchReportFilter(() => props.branchReport, { byBranch: true });
 *
 * - `choice`: what the dropdown shows. A branch id, 'all', 'selected' (a
 *   branch list from the URL) or null (a restricted user who has not picked:
 *   the dropdown then shows the server's scope label). Assigning to it marks
 *   the choice as explicit.
 * - `byBranch`: the "Show by branch" tick (reports with branch columns).
 * - `toQuery()`: the query params to merge into the page's own: `branch` /
 *   `branches` only once the choice is explicit (picked here, or already in
 *   the URL), so an untouched page keeps following the top-bar switcher;
 *   `by_branch=1` when ticked. An empty object for a tenant that is not
 *   multi-branch (`branchReport` null), so its URLs do not change.
 * - `appendTo(params)`: the same params added to a URLSearchParams, for the
 *   Print and Export links (the server ignores `by_branch` there).
 *
 * Re-seeds when the server's applied choice changes (an Inertia visit, the
 * top-bar switcher). UI only; the server re-checks every id.
 *
 * @param {() => object|null} getReport
 * @param {{ byBranch?: boolean }} [options] byBranch: the report has branch columns
 */
export function useBranchReportFilter(getReport, options = {}) {
    const page = usePage();
    const report = computed(() => getReport() ?? null);

    function urlHasChoice() {
        const query = String(page.url ?? '').split('#')[0].split('?')[1] ?? '';

        for (const key of new URLSearchParams(query).keys()) {
            if (key === 'branch' || key === 'branches' || key.startsWith('branches[')) {
                return true;
            }
        }

        return false;
    }

    function choiceFrom(current) {
        const selected = current?.selected ?? null;

        if (selected === null || selected.length === 0) {
            return current?.restricted === true ? null : ALL_BRANCHES;
        }

        if (selected.length === 1) {
            return selected[0];
        }

        // A restricted user looking at every branch they may see is on
        // "all of mine", which the dropdown offers as its way back.
        const optionCount = (current?.options ?? []).length;

        return current?.restricted === true && optionCount > 0 && selected.length >= optionCount ? ALL_BRANCHES : SELECTED_BRANCHES;
    }

    const rawChoice = ref(choiceFrom(report.value));
    const explicit = ref(urlHasChoice());
    const byBranch = ref(report.value?.byBranch === true);

    watch(
        () => JSON.stringify([report.value?.selected ?? null, report.value?.byBranch === true]),
        () => {
            rawChoice.value = choiceFrom(report.value);
            explicit.value = urlHasChoice();
            byBranch.value = report.value?.byBranch === true;
        },
    );

    const choice = computed({
        get: () => rawChoice.value,
        set: (value) => {
            rawChoice.value = value;
            explicit.value = true;
        },
    });

    function toQuery() {
        if (report.value === null) {
            return {};
        }

        const query = {};

        if (rawChoice.value === SELECTED_BRANCHES) {
            query.branches = [...(report.value.selected ?? [])];
        } else if (explicit.value && rawChoice.value === ALL_BRANCHES) {
            query.branch = ALL_BRANCHES;
        } else if (explicit.value && typeof rawChoice.value === 'number') {
            query.branch = rawChoice.value;
        }

        if (options.byBranch === true && byBranch.value) {
            query.by_branch = 1;
        }

        return query;
    }

    /**
     * @param {URLSearchParams} params
     * @returns {URLSearchParams} the same instance
     */
    function appendTo(params) {
        for (const [key, value] of Object.entries(toQuery())) {
            if (Array.isArray(value)) {
                value.forEach((item) => params.append(`${key}[]`, String(item)));
            } else {
                params.set(key, String(value));
            }
        }

        return params;
    }

    return { choice, byBranch, toQuery, appendTo };
}
