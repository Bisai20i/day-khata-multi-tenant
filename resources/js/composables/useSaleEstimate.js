import { onUnmounted, ref } from 'vue';

/** Laravel's XSRF-TOKEN cookie, which a plain fetch() POST has to echo back itself. */
function xsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Estimate preview for a sale still being entered, shared by the sales entry
 * page and the POS (usePosEstimate).
 *
 * Posts `buildPayload()` - the same payload the page would submit to
 * `POST /sales` - to `url`, which renders it through the invoice's own PDF
 * views without saving anything, and holds the returned PDF as an object URL
 * for the modal to show and print. Nothing on the estimate is worked out in
 * the browser.
 */
export function useSaleEstimate({ form, totals, previewError, toast, url, buildPayload }) {
    const estimateOpen = ref(false);
    const estimateUrl = ref('');
    const estimateLoading = ref(false);

    function releaseEstimate() {
        if (estimateUrl.value) URL.revokeObjectURL(estimateUrl.value);
        estimateUrl.value = '';
    }

    function closeEstimate() {
        estimateOpen.value = false;
        releaseEstimate();
    }

    async function openEstimate() {
        if (estimateLoading.value || form.lines.length === 0) return;

        if (!form.customer_id) {
            toast({ message: 'Select a customer to prepare the estimate.', variant: 'danger' });
            return;
        }

        if (!totals.value) {
            toast({ message: previewError.value ?? 'Fix the highlighted line details to prepare the estimate.', variant: 'danger' });
            return;
        }

        releaseEstimate();
        estimateOpen.value = true;
        estimateLoading.value = true;

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/pdf, application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify(buildPayload()),
            });

            if (!response.ok) {
                const body = await response.json().catch(() => null);
                const firstError = body?.errors ? Object.values(body.errors)[0]?.[0] : null;

                throw new Error(firstError ?? body?.message ?? 'The estimate could not be prepared.');
            }

            const pdf = await response.blob();

            // Closed while the request was in flight: nothing left to show.
            if (!estimateOpen.value) return;

            estimateUrl.value = URL.createObjectURL(pdf);
        } catch (error) {
            closeEstimate();
            toast({ message: error.message || 'The estimate could not be prepared.', variant: 'danger' });
        } finally {
            estimateLoading.value = false;
        }
    }

    onUnmounted(releaseEstimate);

    return { estimateOpen, estimateUrl, estimateLoading, openEstimate, closeEstimate };
}
