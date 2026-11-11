import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Regular English plural of the label words a company can choose (Branch,
 * Site, Outlet, Project). "y" after a consonant becomes "ies" so a future
 * label such as "Facility" still reads correctly.
 */
export function pluralizeLabel(word) {
    if (/[^aeiou]y$/i.test(word)) {
        return `${word.slice(0, -1)}ies`;
    }

    if (/(s|x|z|ch|sh)$/i.test(word)) {
        return `${word}es`;
    }

    return `${word}s`;
}

/**
 * What this company calls a branch, from the shared `branchLabel` prop
 * (company setting `branch_label`; "Branch" by default). Use it for every
 * user-facing word so a company that runs "Outlets" never sees "Branch".
 *
 * `label('lower')` / `plural('lower')` give the lowercase form for use inside
 * a sentence; `forCount(n)` picks singular or plural for a count.
 */
export function useBranchLabel() {
    const page = usePage();

    const singular = computed(() => page.props.branchLabel || 'Branch');
    const plural = computed(() => pluralizeLabel(singular.value));

    return {
        branchLabel: singular,
        branchLabelPlural: plural,
        label: (form = 'title') => (form === 'lower' ? singular.value.toLowerCase() : singular.value),
        plural: (form = 'title') => (form === 'lower' ? plural.value.toLowerCase() : plural.value),
        forCount: (count) => (String(count) === '1' ? singular.value : plural.value),
    };
}
