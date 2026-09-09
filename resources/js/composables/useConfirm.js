import { ref } from 'vue';

/**
 * Current confirmation request, or null when no dialog is open. Holding the
 * pending Promise's resolver here (rather than returning it to the caller)
 * lets a single <ConfirmDialog /> mounted once in the layout render whatever
 * request is active, mirroring the toasts/useToast singleton pattern.
 */
const request = ref(null);

/**
 * Imperatively ask the user to confirm an action, e.g. inside a delete
 * handler: `if (await confirm({ message: 'Delete this item?', tone: 'danger', confirmLabel: 'Delete' })) { ... }`.
 * Resolves `true` when the user confirms, `false` when they cancel or
 * dismiss the dialog (close button, overlay click, Escape).
 *
 * @param {object} options
 * @param {string} [options.title]
 * @param {string} [options.message]
 * @param {'purple'|'blue'|'danger'|'success'} [options.tone] Passed to the confirm button's `tone`.
 * @param {string} [options.confirmLabel]
 * @param {string} [options.cancelLabel]
 * @returns {Promise<boolean>}
 */
function confirm({ title = 'Are you sure?', message = '', tone = 'purple', confirmLabel = 'Confirm', cancelLabel = 'Cancel' } = {}) {
    return new Promise((resolve) => {
        request.value = { title, message, tone, confirmLabel, cancelLabel, resolve };
    });
}

function resolveConfirm(result) {
    if (!request.value) {
        return;
    }
    request.value.resolve(result);
    request.value = null;
}

export function useConfirm() {
    return { request, confirm, resolveConfirm };
}
