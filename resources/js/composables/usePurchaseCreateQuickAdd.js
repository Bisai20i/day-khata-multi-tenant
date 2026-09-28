import { computed, onMounted, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useToast } from '@/composables/useToast';

// --- Inline "+ New supplier" / "quick add item" (item 9) ----------------
// Mirrors useSaleCreateCustomer(): POST /suppliers and POST /items both
// redirect to their own index pages (neither has a JSON mode), so the form's
// in-progress draft is stashed in sessionStorage before the post, the
// browser bounces back to /purchases, and Purchases/Index.vue hands the
// draft back in through Create.vue's initialDraft prop. The new record is
// then picked out of the freshly reloaded props by name.
const DRAFT_KEY = 'purchases-create-draft';
const PENDING_SUPPLIER_KEY = 'purchases-create-pending-supplier';
const PENDING_ITEM_KEY = 'purchases-create-pending-item';

/**
 * @param {object} form the purchase useForm()
 * @param {object} props Create.vue's props (suppliers, items, itemCategories)
 * @param {{ selectSupplier: Function, stageItem: Function }} actions
 */
export function usePurchaseCreateQuickAdd(form, props, { selectSupplier, stageItem }) {
    const { toast } = useToast();

    function stashDraft(pendingKey, pending) {
        try {
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify(form.data()));
            sessionStorage.setItem(pendingKey, JSON.stringify(pending));
        } catch {
            // Storage unavailable - the modal still worked, the draft just
            // won't survive the bounce back to /purchases.
        }
    }

    function takePending(pendingKey) {
        let raw;
        try {
            raw = sessionStorage.getItem(pendingKey);
        } catch {
            return null;
        }
        if (!raw) return null;

        try {
            sessionStorage.removeItem(pendingKey);

            return JSON.parse(raw);
        } catch {
            // malformed sessionStorage payload - nothing to recover, ignore.
            return null;
        }
    }

    const supplierModalOpen = ref(false);
    const supplierForm = useForm({ name: '', mobile_no: '' });

    function openSupplierModal() {
        supplierForm.reset();
        supplierForm.clearErrors();
        supplierModalOpen.value = true;
    }

    function closeSupplierModal() {
        supplierModalOpen.value = false;
        supplierForm.reset();
        supplierForm.clearErrors();
    }

    function submitSupplier() {
        const pendingSupplier = { name: supplierForm.name, mobile_no: supplierForm.mobile_no };

        supplierForm.post('/suppliers', {
            onSuccess: () => {
                stashDraft(PENDING_SUPPLIER_KEY, pendingSupplier);
                supplierModalOpen.value = false;
                router.visit('/purchases');
            },
        });
    }

    const itemModalOpen = ref(false);
    const itemForm = useForm({ item_category_id: null, name: '', unit: '', purchase_rate: '', sale_rate: '' });
    const itemCategoryOptions = computed(() => props.itemCategories.map((c) => ({ value: c.id, label: c.name })));

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
                stashDraft(PENDING_ITEM_KEY, pendingItem);
                itemModalOpen.value = false;
                router.visit('/purchases');
            },
        });
    }

    onMounted(() => {
        const pendingSupplier = takePending(PENDING_SUPPLIER_KEY);
        if (pendingSupplier) {
            const match = props.suppliers
                .filter((s) => s.name === pendingSupplier.name && (pendingSupplier.mobile_no ? s.mobile_no === pendingSupplier.mobile_no : true))
                .sort((a, b) => b.id - a.id)[0];
            if (match) selectSupplier(match.id);
            toast({ message: 'Supplier added.', variant: 'success' });
        }

        // Matched case-insensitively since ItemController::uniqueNameRule()
        // itself is case-insensitive (item 6). The new item lands in the
        // "Add item" row, ready for its quantity - not straight on the bill.
        const pendingItem = takePending(PENDING_ITEM_KEY);
        if (pendingItem) {
            const match = props.items
                .filter((i) => i.name.toLowerCase() === String(pendingItem.name).toLowerCase())
                .sort((a, b) => b.id - a.id)[0];
            if (match) stageItem(match.id);
            toast({ message: 'Item added.', variant: 'success' });
        }
    });

    return {
        supplierModalOpen,
        supplierForm,
        openSupplierModal,
        closeSupplierModal,
        submitSupplier,
        itemModalOpen,
        itemForm,
        itemCategoryOptions,
        openItemModal,
        closeItemModal,
        submitItem,
    };
}
