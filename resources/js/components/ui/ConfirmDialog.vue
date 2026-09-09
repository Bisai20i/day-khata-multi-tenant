<script setup>
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';
import { useConfirm } from '@/composables/useConfirm';

const { request, resolveConfirm } = useConfirm();

function onOpenChange(open) {
    // Closing via the [x] button, overlay click, or Escape all count as "cancel".
    if (!open) {
        resolveConfirm(false);
    }
}
</script>

<template>
    <Modal :open="!!request" :title="request?.title ?? ''" size="compact" @update:open="onOpenChange">
        <p v-if="request?.message" class="text-[13px] text-text-muted">{{ request.message }}</p>

        <template #footer>
            <Button variant="secondary" tone="purple" type="button" @click="resolveConfirm(false)">
                {{ request?.cancelLabel ?? 'Cancel' }}
            </Button>
            <Button variant="primary" :tone="request?.tone ?? 'purple'" type="button" @click="resolveConfirm(true)">
                {{ request?.confirmLabel ?? 'Confirm' }}
            </Button>
        </template>
    </Modal>
</template>
