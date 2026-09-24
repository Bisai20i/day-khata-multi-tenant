import { onMounted, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';

// --- Inline "+ New customer" ------------------------------------------
// Ports Pos.vue's openCustomerModal()/submitCustomer() pattern: POST
// /customers always redirects to the customers index, so this bounces back
// to /sales and best-effort auto-selects the new customer by matching
// name/mobile against the freshly reloaded customers list. Unlike Pos.vue
// (a standalone page whose in-progress cart already survives a remount via
// localStorage), the sale form is a child of Sales/Index.vue that unmounts
// entirely while showCreateForm is false - so the in-progress draft is
// stashed alongside the pending-customer marker and handed back in via the
// initialDraft prop once Index.vue reopens this form (see its onMounted).
const DRAFT_KEY = 'sales-create-draft';
const PENDING_CUSTOMER_KEY = 'sales-create-pending-customer';

export function useSaleCreateCustomer(form, props) {
    const { toast } = useToast();
    const customerModalOpen = ref(false);
    const customerForm = useForm({ name: '', mobile_no: '' });

    function openCustomerModal() {
        customerForm.reset();
        customerForm.clearErrors();
        customerModalOpen.value = true;
    }

    function closeCustomerModal() {
        customerModalOpen.value = false;
        customerForm.reset();
        customerForm.clearErrors();
    }

    function submitCustomer() {
        const pendingCustomer = { name: customerForm.name, mobile_no: customerForm.mobile_no };

        customerForm.post('/customers', {
            onSuccess: () => {
                try {
                    sessionStorage.setItem(DRAFT_KEY, JSON.stringify(form.data()));
                    sessionStorage.setItem(PENDING_CUSTOMER_KEY, JSON.stringify(pendingCustomer));
                } catch {
                    // Storage unavailable - the modal still worked, the draft just
                    // won't survive the bounce back to /sales.
                }
                customerModalOpen.value = false;
                router.visit('/sales');
            },
        });
    }

    function applyPendingCustomer() {
        let raw;
        try {
            raw = sessionStorage.getItem(PENDING_CUSTOMER_KEY);
        } catch {
            return;
        }
        if (!raw) return;

        try {
            sessionStorage.removeItem(PENDING_CUSTOMER_KEY);
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

    onMounted(() => applyPendingCustomer());

    return { customerModalOpen, customerForm, openCustomerModal, closeCustomerModal, submitCustomer };
}
