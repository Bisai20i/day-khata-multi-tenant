<script setup>
import Modal from '@/components/ui/Modal.vue';
import Button from '@/components/ui/Button.vue';

/**
 * Shared shell for the production action dialogs: title, a form body in the
 * default slot, an inline server-error banner, and Back / submit buttons.
 * The parent owns the Inertia form and passes its first error as `error`, so
 * a domain rejection (over-return, closed year, wrong status) is always
 * visible inside the dialog that caused it.
 */
defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    submitLabel: { type: String, default: 'Save' },
    processing: { type: Boolean, default: false },
    error: { type: String, default: '' },
    danger: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'submit']);
</script>

<template>
    <Modal :open="open" :title="title" size="compact" @update:open="(value) => !value && emit('close')">
        <form class="flex flex-col gap-4" @submit.prevent="emit('submit')">
            <p v-if="error" class="border-[1.5px] border-danger bg-danger-bg px-3 py-2 text-sm text-danger" role="alert">{{ error }}</p>
            <slot />
        </form>

        <template #footer>
            <Button variant="secondary" tone="neutral" type="button" @click="emit('close')">Back</Button>
            <Button variant="primary" :tone="danger ? 'danger' : 'purple'" type="button" :disabled="processing" @click="emit('submit')">
                {{ submitLabel }}
            </Button>
        </template>
    </Modal>
</template>
