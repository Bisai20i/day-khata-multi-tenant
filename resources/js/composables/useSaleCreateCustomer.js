import { computed, onMounted, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';

// --- Inline "+ New customer" & "+ New item" ----------------------------
// Ports Pos.vue's openCustomerModal()/submitCustomer() pattern: POST
// /customers and POST /items redirect to their index, so this bounces back
// to /sales and best-effort auto-selects the new record by matching
// against the freshly reloaded list. Unlike Pos.vue (a standalone page whose
// in-progress cart already survives a remount via localStorage), the sale
// form is a child of Sales/Index.vue that unmounts entirely while showCreateForm
// is false - so the in-progress draft is stashed alongside the pending marker
// and handed back in via the initialDraft prop once Index.vue reopens this form.
const DRAFT_KEY = 'sales-create-draft';
const PENDING_CUSTOMER_KEY = 'sales-create-pending-customer';
const PENDING_ITEM_KEY = 'sales-create-pending-item';

export function useSaleCreateCustomer(form, props, { stageItem } = {}) {
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
                    // Storage unavailable
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
            // malformed sessionStorage payload
        }
    }

    const itemModalOpen = ref(false);
    const itemForm = useForm({ item_category_id: null, name: '', unit: '', purchase_rate: '', sale_rate: '' });
    const itemCategoryOptions = computed(() => (props.itemCategories ?? []).map((c) => ({ value: c.id, label: c.name })));

    function openItemModal() {
        itemForm.reset();
        itemForm.clearErrors();
        itemModalOpen.value = true;
    }

    function closeItemModal() {
        itemModalOpen.value = false;
        itemForm.reset();
        itemForm.clearErrors();
    }

    function submitItem() {
        const pendingItem = { name: itemForm.name };

        itemForm.transform((data) => ({
            ...data,
            purchase_rate: data.purchase_rate === '' ? null : data.purchase_rate,
            sale_rate: data.sale_rate === '' ? null : data.sale_rate,
        })).post('/items', {
            onSuccess: () => {
                try {
                    sessionStorage.setItem(DRAFT_KEY, JSON.stringify(form.data()));
                    sessionStorage.setItem(PENDING_ITEM_KEY, JSON.stringify(pendingItem));
                } catch {
                    // Storage unavailable
                }
                itemModalOpen.value = false;
                router.visit('/sales');
            },
        });
    }

    function applyPendingItem() {
        let raw;
        try {
            raw = sessionStorage.getItem(PENDING_ITEM_KEY);
        } catch {
            return;
        }
        if (!raw) return;

        try {
            sessionStorage.removeItem(PENDING_ITEM_KEY);
            const pending = JSON.parse(raw);
            const match = props.items
                .filter((i) => i.name.toLowerCase() === String(pending.name).toLowerCase())
                .sort((a, b) => b.id - a.id)[0];
            if (match && stageItem) stageItem(match.id);
            toast({ message: 'Item added.', variant: 'success' });
        } catch {
            // malformed sessionStorage payload
        }
    }

    onMounted(() => {
        applyPendingCustomer();
        applyPendingItem();
    });

    return {
        customerModalOpen,
        customerForm,
        openCustomerModal,
        closeCustomerModal,
        submitCustomer,
        itemModalOpen,
        itemForm,
        itemCategoryOptions,
        openItemModal,
        closeItemModal,
        submitItem,
    };
}
