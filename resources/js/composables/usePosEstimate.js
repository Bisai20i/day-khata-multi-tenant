import { toSalePayload } from '@/composables/usePosCheckout';
import { useSaleEstimate } from '@/composables/useSaleEstimate';

/**
 * Estimate preview for the active cart.
 *
 * The shared preview (useSaleEstimate) fed the same payload checkout would
 * post (toSalePayload) and pointed at `POST /pos/estimate`, the route a
 * cashier holding only pos.view can reach.
 */
export function usePosEstimate({ form, totals, previewError, resolvedPaymentMode, toast }) {
    return useSaleEstimate({
        form,
        totals,
        previewError,
        toast,
        url: '/pos/estimate',
        buildPayload: () => toSalePayload(form.data(), resolvedPaymentMode.value),
    });
}
