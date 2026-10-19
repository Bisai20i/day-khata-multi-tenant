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
 * dismiss the dialog (close button, overlay click, Escape), or when another
 * confirm() replaces it before it was answered.
 *
 * @param {object} options
 * @param {string} [options.title]
 * @param {string} [options.message]
 * @param {'purple'|'blue'|'danger'|'success'} [options.tone] Passed to the confirm button's `tone`.
 * @param {string} [options.confirmLabel]
 * @param {string} [options.cancelLabel]
 * @param {string[]} [options.details] Optional bullet list shown under the message.
 * @param {string} [options.detailsTitle] Lead-in line above `details`.
 * @param {string} [options.note] Small print under everything else.
 * @param {boolean} [options.safeDefault] Focus the cancel button on open, and ignore an Enter
 *   that is a key-repeat or lands in the first moments after opening, so the Enter that
 *   triggered the dialog (or a held key) can never answer it.
 * @param {Function} [options.returnFocus] Called instead of the default focus restore once
 *   the dialog has closed, e.g. to move on to the field the page would focus next. A request
 *   that replaces one still open keeps the dialog open, so the first request's target is kept.
 * @returns {Promise<boolean>}
 */
function confirm({
    title = 'Are you sure?',
    message = '',
    tone = 'purple',
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    details = [],
    detailsTitle = '',
    note = '',
    safeDefault = false,
    returnFocus = null,
} = {}) {
    return new Promise((resolve) => {
        // Only one dialog shows at a time: a request still open when another
        // arrives is answered "cancelled", so its caller never awaits forever.
        request.value?.resolve(false);
        request.value = { title, message, tone, confirmLabel, cancelLabel, details, detailsTitle, note, safeDefault, returnFocus, resolve };
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
