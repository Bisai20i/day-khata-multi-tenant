import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Whether the company has an open fiscal year, shared on every tenant page
 * by HandleInertiaRequests. Every posting path needs one
 * (JournalVoucher::post()), so posting pages hide their create buttons while
 * this is false; AppLayout shows the "set up a fiscal year" banner.
 *
 * Defaults to true outside a tenant (central pages never post).
 */
export function useOpenFiscalYear() {
    const page = usePage();

    const hasOpenFiscalYear = computed(() => page.props.tenant?.has_open_fiscal_year ?? true);

    return { hasOpenFiscalYear };
}
