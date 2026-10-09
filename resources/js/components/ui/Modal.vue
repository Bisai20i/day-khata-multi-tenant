<script setup>
import { ref } from 'vue';
import { X } from '@lucide/vue';
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';
import { cn } from '@/lib/utils';
import { useConfirm } from '@/composables/useConfirm';

/**
 * Dialog with a fixed header and footer: only the body scrolls, so the title
 * and the Cancel/Save buttons stay in view however long the form is.
 *
 * Pick `size` by how much the modal holds - `compact` for a confirmation or
 * one or two fields, `default` for a short form, `lg`/`xl` for long ones.
 *
 * Pass `dirty` while the form inside has unsaved entries: closing via the [x]
 * button, the backdrop or Escape then asks before discarding them.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    size: { type: String, default: 'default' },
    dirty: { type: Boolean, default: false },
    class: { type: [String, Array, Object], default: '' },
});

const emit = defineEmits(['update:open']);

const { confirm } = useConfirm();

const sizeClasses = {
    compact: 'max-w-[380px]',
    default: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-4xl',
};

const body = ref(null);
let isConfirmingDiscard = false;

async function onOpenChange(value) {
    if (value || !props.dirty) {
        emit('update:open', value);

        return;
    }

    if (isConfirmingDiscard) return;

    isConfirmingDiscard = true;
    const shouldDiscard = await confirm({
        title: 'Discard changes?',
        message: 'You have unsaved entries in this form. Closing it now will lose them.',
        tone: 'danger',
        confirmLabel: 'Discard',
        cancelLabel: 'Keep editing',
    });
    isConfirmingDiscard = false;

    if (shouldDiscard) emit('update:open', false);
}

// The dialog would otherwise focus its first focusable element, which is the
// [x] button in the header. Start in the form instead: the field marked
// `data-autofocus`, else the first text control in the body.
function onOpenAutoFocus(event) {
    const target =
        body.value?.querySelector('[data-autofocus]') ??
        body.value?.querySelector('input:not([type="hidden"]):not([type="checkbox"]):not([type="file"]):not([disabled]), textarea:not([disabled])');
    if (!target) return;

    event.preventDefault();
    target.focus();
}
</script>

<template>
    <DialogRoot :open="open" @update:open="onOpenChange">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-50 flex items-center justify-center bg-overlay p-3 sm:p-4">
                <DialogContent
                    :class="cn(
                        'relative flex max-h-[calc(100dvh-1.5rem)] w-full flex-col overflow-hidden bg-bg-surface shadow-[0_10px_25px_rgba(0,0,0,.15)] outline-none sm:max-h-[85vh]',
                        sizeClasses[size] ?? sizeClasses.default,
                        props.class,
                    )"
                    @open-auto-focus="onOpenAutoFocus"
                >
                    <div class="flex shrink-0 items-start justify-between gap-4 border-b border-border px-5 py-3.5">
                        <div class="min-w-0">
                            <DialogTitle class="text-[14px] font-bold text-text-strong">{{ title }}</DialogTitle>
                            <DialogDescription v-if="description" class="mt-0.5 text-xs leading-relaxed text-text-muted">
                                {{ description }}
                            </DialogDescription>
                        </div>
                        <DialogClose
                            class="mt-0.5 shrink-0 text-text-muted transition-colors duration-150 hover:text-danger"
                            aria-label="Close"
                        >
                            <X class="h-4 w-4" />
                        </DialogClose>
                    </div>
                    <div ref="body" class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                        <slot />
                    </div>
                    <div
                        v-if="$slots.footer"
                        class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-border bg-bg-subtle px-5 py-3"
                    >
                        <slot name="footer" />
                    </div>
                </DialogContent>
            </DialogOverlay>
        </DialogPortal>
    </DialogRoot>
</template>
