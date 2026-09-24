import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * The invoice picker of the linked sales-return form: server-side search and
 * paging of posted sales, plus picking/clearing the selected sale.
 */
export function useSaleReturnCreateSalePicker(props) {
    const saleRows = computed(() => props.sales.data ?? []);
    const searchTerm = ref(props.saleSearch ?? '');
    const searching = ref(false);

    /**
     * The Index page keeps its own filters in the query string; a partial reload
     * for the picker must not drop them, so every navigation starts from what is
     * already in the URL.
     */
    function reload(params, only) {
        const current = Object.fromEntries(new URLSearchParams(window.location.search));

        router.get(
            window.location.pathname,
            { ...current, ...params },
            {
                only,
                preserveState: true,
                preserveScroll: true,
                onStart: () => (searching.value = true),
                onFinish: () => (searching.value = false),
            },
        );
    }

    function searchSales() {
        reload({ sale_search: searchTerm.value || undefined, sale_page: undefined }, ['sales']);
    }

    function goToPage(page) {
        reload({ sale_search: searchTerm.value || undefined, sale_page: page }, ['sales']);
    }

    function pickSale(sale) {
        reload({ sale_id: sale.id }, ['selectedSale']);
    }

    function clearSale() {
        reload({ sale_id: undefined }, ['selectedSale']);
    }

    return { saleRows, searchTerm, searching, searchSales, goToPage, pickSale, clearSale };
}
