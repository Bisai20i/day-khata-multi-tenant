import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const PENDING_CUSTOMER_KEY = 'pos-pending-customer';

/**
 * Quick "+ New customer" bridge. POST /customers always redirects to the
 * customers index, so the created customer's name/mobile is stashed in
 * sessionStorage, the page bounces back to /pos and best-effort auto-selects
 * the new customer by matching against the freshly reloaded customers list
 * (the endpoint replies with a redirect, not JSON, so no id comes back).
 */
export function usePosCustomerBridge({ props, form, toast }) {
    const customerModalOpen = ref(false);

    function openCustomerModal() {
        customerModalOpen.value = true;
    }

    function onCustomerCreated(pending) {
        sessionStorage.setItem(PENDING_CUSTOMER_KEY, JSON.stringify(pending));
        customerModalOpen.value = false;
        router.visit('/pos', { onSuccess: applyPendingCustomer });
    }

    function applyPendingCustomer() {
        const raw = sessionStorage.getItem(PENDING_CUSTOMER_KEY);
        if (!raw) return;
        sessionStorage.removeItem(PENDING_CUSTOMER_KEY);

        try {
            const pending = JSON.parse(raw);
            const matches = props.customers.filter(
                (c) => c.name === pending.name && (pending.mobile_no ? c.mobile_no === pending.mobile_no : true),
            );
            const match = matches.sort((a, b) => b.id - a.id)[0];
            if (match) form.customer_id = match.id;
            toast({ message: 'Customer added.', variant: 'success' });
        } catch {
            // malformed sessionStorage payload - nothing to recover, ignore.
        }
    }

    return { customerModalOpen, openCustomerModal, onCustomerCreated, applyPendingCustomer };
}
