<script setup>
import { nextTick, ref, watch } from 'vue';
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import { useConfirm } from '@/composables/useConfirm';

const { request, resolveConfirm } = useConfirm();

// An Enter this soon after a `safeDefault` dialog opens is the tail of the
// keystroke that opened it (or a held key), not an answer.
const ENTER_GUARD_MS = 400;

const cancelButton = ref(null);
let openedAt = 0;
// Kept past resolveConfirm(), which clears the request before the dialog has
// finished closing and asks where focus should go.
let pendingReturnFocus = null;

watch(request, (current, previous) => {
    if (!current) return;

    openedAt = Date.now();

    if (!previous) {
        pendingReturnFocus = current.returnFocus ?? null;

        return;
    }

    // A request replacing one still open: the dialog never closed, so focus
    // goes back where the first request said once it does, and the dialog's
    // open-auto-focus doesn't run again - a safe default is focused here.
    if (current.safeDefault) {
        nextTick(() => cancelButton.value?.$el?.focus());
    }
});

function onOpenChange(open) {
    // Closing via the [x] button, overlay click, or Escape all count as "cancel".
    if (!open) {
        resolveConfirm(false);
    }
}

function guardEnter(event) {
    if (request.value?.safeDefault && (event.repeat || Date.now() - openedAt < ENTER_GUARD_MS)) {
        event.preventDefault();
    }
}

function onCloseAutoFocus(event) {
    const returnFocus = pendingReturnFocus;
    pendingReturnFocus = null;
    if (!returnFocus) return;

    event.preventDefault();
    returnFocus();
}
</script>

<template>
    <Modal :open="!!request" :title="request?.title ?? ''" size="compact" @update:open="onOpenChange" @close-auto-focus="onCloseAutoFocus">
        <p v-if="request?.message" class="text-[13px] text-text-muted">{{ request.message }}</p>
        <template v-if="request?.details?.length">
            <p v-if="request.detailsTitle" class="mt-3 text-[13px] text-text-muted">{{ request.detailsTitle }}</p>
            <ul class="mt-1 list-disc pl-5 text-[13px] text-text-base">
                <li v-for="(detail, index) in request.details" :key="index">{{ detail }}</li>
            </ul>
        </template>
        <p v-if="request?.note" class="mt-3 text-xs text-text-faint">{{ request.note }}</p>

        <template #footer>
            <!-- With safeDefault the cancel button takes focus on open (Modal
                 looks for data-autofocus in the footer too), so an accidental
                 Enter picks the harmless answer. -->
            <Button
                ref="cancelButton"
                variant="secondary"
                tone="purple"
                type="button"
                :data-autofocus="request?.safeDefault ? '' : undefined"
                @keydown.enter="guardEnter"
                @click="resolveConfirm(false)"
            >
                {{ request?.cancelLabel ?? 'Cancel' }}
            </Button>
            <Button variant="primary" :tone="request?.tone ?? 'purple'" type="button" @keydown.enter="guardEnter" @click="resolveConfirm(true)">
                {{ request?.confirmLabel ?? 'Confirm' }}
            </Button>
        </template>
    </Modal>
</template>
