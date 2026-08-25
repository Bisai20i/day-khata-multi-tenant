<script setup>
import { X } from '@lucide/vue';
import { DialogClose, DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';
import { cn } from '@/lib/utils';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    size: { type: String, default: 'default' },
    class: { type: [String, Array, Object], default: '' },
});

const emit = defineEmits(['update:open']);

function onOpenChange(value) {
    emit('update:open', value);
}
</script>

<template>
    <DialogRoot :open="open" @update:open="onOpenChange">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-50 flex items-center justify-center bg-overlay p-4">
                <DialogContent
                    :class="cn(
                        'relative w-full max-h-[85vh] overflow-y-auto bg-bg-surface p-5 shadow-[0_10px_25px_rgba(0,0,0,.15)] outline-none',
                        size === 'compact' ? 'max-w-[380px]' : 'max-w-lg',
                        props.class,
                    )"
                >
                    <div class="mb-4 flex items-center justify-between gap-4">
                        <DialogTitle class="text-[14px] font-bold text-text-strong">{{ title }}</DialogTitle>
                        <DialogClose class="text-text-muted transition-colors duration-150 hover:text-danger">
                            <X class="h-4 w-4" />
                        </DialogClose>
                    </div>
                    <div>
                        <slot />
                    </div>
                    <div v-if="$slots.footer" class="mt-5 flex items-center justify-end gap-2">
                        <slot name="footer" />
                    </div>
                </DialogContent>
            </DialogOverlay>
        </DialogPortal>
    </DialogRoot>
</template>
