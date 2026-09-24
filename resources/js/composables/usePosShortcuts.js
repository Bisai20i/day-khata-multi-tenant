import { ref } from 'vue';

/**
 * POS keyboard shortcuts.
 *
 * Global while the page is mounted: the page calls `attachShortcuts()` from
 * onMounted and `detachShortcuts()` from onUnmounted. Matches legacy's F-key
 * scheme exactly (docs/pos_user_manual.md): F1 help, F2 search, F7 barcode,
 * F4 customer, F8 save & print, F9 hold, Esc clear. Legacy's "new cart"/"+"
 * action has no dedicated hotkey either.
 *
 * @param {object} options
 * @param {import('vue').Ref<boolean>[]} options.blockingModals Modal-open refs that suppress every shortcut while true.
 * @param {import('vue').Ref<HTMLElement|null>} options.searchFieldWrapper
 * @param {import('vue').Ref<HTMLElement|null>} options.barcodeFieldWrapper
 * @param {import('vue').Ref<HTMLElement|null>} options.customerFieldWrapper
 * @param {import('vue').ComputedRef<boolean>} options.canSubmit
 * @param {(action: string) => void} options.completeSale
 * @param {() => void} options.holdCarts
 */
export function usePosShortcuts({
    blockingModals,
    searchFieldWrapper,
    barcodeFieldWrapper,
    customerFieldWrapper,
    canSubmit,
    completeSale,
    holdCarts,
}) {
    const shortcutsOpen = ref(false);

    const shortcutList = [
        { key: 'F1', description: 'Open this shortcuts help' },
        { key: 'F2', description: 'Focus product search' },
        { key: 'F7', description: 'Focus barcode scan' },
        { key: 'F4', description: 'Focus customer field' },
        { key: 'F8', description: 'Save & print' },
        { key: 'F9', description: 'Hold carts (saved to this browser)' },
        { key: 'Esc', description: 'Clear focus' },
    ];

    function onGlobalKeydown(event) {
        if (shortcutsOpen.value || blockingModals.some((modalOpen) => modalOpen.value)) {
            return;
        }

        if (event.key === 'F1') {
            event.preventDefault();
            shortcutsOpen.value = true;
            return;
        }

        if (event.key === 'F2') {
            event.preventDefault();
            searchFieldWrapper.value?.querySelector('input')?.focus();
            return;
        }

        if (event.key === 'F7') {
            event.preventDefault();
            barcodeFieldWrapper.value?.querySelector('input')?.focus();
            return;
        }

        if (event.key === 'F4') {
            event.preventDefault();
            customerFieldWrapper.value?.querySelector('input')?.focus();
            return;
        }

        if (event.key === 'F8') {
            event.preventDefault();
            if (canSubmit.value) completeSale('print');
            return;
        }

        if (event.key === 'F9') {
            event.preventDefault();
            holdCarts();
            return;
        }

        if (event.key === 'Escape' && document.activeElement instanceof HTMLElement) {
            document.activeElement.blur();
        }
    }

    /** Registers the global key listener - call from onMounted. */
    function attachShortcuts() {
        window.addEventListener('keydown', onGlobalKeydown);
    }

    /** Removes the global key listener - call from onUnmounted. */
    function detachShortcuts() {
        window.removeEventListener('keydown', onGlobalKeydown);
    }

    return { shortcutsOpen, shortcutList, attachShortcuts, detachShortcuts };
}
