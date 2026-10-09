import { computed, nextTick, ref } from 'vue';
import { formSnapshot } from '@/lib/formModal.js';

/**
 * State and actions for an add/edit modal on a master-data Index page: one
 * useForm() shared by "New" and "Edit", posted to `url` or put to `url/{id}`.
 *
 * On top of the plain open/close/submit every Index page used to repeat:
 * - `isDirty` for Modal's `dirty` prop, so an accidental Escape or backdrop
 *   click asks before throwing the entries away;
 * - `addAnother`, which keeps the modal open after a create and clears it for
 *   the next entry, with `savedNotice` confirming what was just saved;
 * - a failed save scrolls to and focuses the first field with an error.
 *
 * Mark the field to focus on open with `data-autofocus`.
 *
 * @param {object} options
 * @param {object} options.form the page's useForm()
 * @param {string} options.url collection URL, e.g. '/stores'
 * @param {string} options.formId id of the <form> element inside the modal
 * @param {(record: object) => void} options.fill copies a record into the form for editing
 * @param {(lastCreated: object|null, context: { addingAnother: boolean }) => object} [options.createDefaults]
 *   field overrides for a blank form, given the data of the last record created on this page
 * @param {(data: object) => string} [options.savedLabel] what to call the saved record in `savedNotice`
 * @param {() => void} [options.onReset] clears state living outside the form, e.g. an image preview
 */
export function useCrudModal({ form, url, formId, fill, createDefaults = () => ({}), savedLabel = (data) => data.name, onReset = () => {} }) {
    const showModal = ref(false);
    const editing = ref(null);
    const addAnother = ref(false);
    const savedNotice = ref('');
    const lastCreated = ref(null);
    const openedSnapshot = ref('');

    const isDirty = computed(() => showModal.value && formSnapshot(form.data()) !== openedSnapshot.value);

    function startBlank(context) {
        form.reset();
        form.clearErrors();
        Object.assign(form, createDefaults(lastCreated.value, context));
        onReset();
        openedSnapshot.value = formSnapshot(form.data());
    }

    function openCreate() {
        editing.value = null;
        savedNotice.value = '';
        startBlank({ addingAnother: false });
        showModal.value = true;
    }

    function openEdit(record) {
        editing.value = record;
        savedNotice.value = '';
        form.clearErrors();
        fill(record);
        openedSnapshot.value = formSnapshot(form.data());
        showModal.value = true;
    }

    function closeModal() {
        showModal.value = false;
        editing.value = null;
        savedNotice.value = '';
        form.reset();
        form.clearErrors();
        onReset();
    }

    function onModalOpenChange(value) {
        if (!value) closeModal();
    }

    function formElement() {
        return document.getElementById(formId);
    }

    function focusFirstField() {
        nextTick(() => formElement()?.querySelector('[data-autofocus]')?.focus());
    }

    function focusFirstError() {
        nextTick(() => {
            const field = formElement()?.querySelector('[role="alert"]')?.closest('[data-form-field]');
            if (!field) return;

            field.scrollIntoView({ block: 'center', behavior: 'smooth' });
            field.querySelector('input, textarea, button')?.focus({ preventScroll: true });
        });
    }

    function submit() {
        const record = editing.value;
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                if (record) {
                    closeModal();

                    return;
                }

                const saved = form.data();
                lastCreated.value = saved;

                if (!addAnother.value) {
                    closeModal();

                    return;
                }

                startBlank({ addingAnother: true });
                savedNotice.value = `"${savedLabel(saved)}" saved. Add the next one.`;
                focusFirstField();
            },
            onError: focusFirstError,
        };

        if (record) {
            form.put(`${url}/${record.id}`, options);
        } else {
            form.post(url, options);
        }
    }

    return { showModal, editing, addAnother, savedNotice, isDirty, openCreate, openEdit, closeModal, onModalOpenChange, submit };
}
