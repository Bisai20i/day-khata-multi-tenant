<script setup>
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';

defineProps({
    shortcutList: { type: Array, default: () => [] },
});

const open = defineModel('open', { type: Boolean, default: false });
</script>

<template>
    <Modal :open="open" title="Keyboard shortcuts" size="compact" @update:open="(v) => (open = v)">
        <div class="flex flex-col gap-2 text-sm">
            <div v-for="shortcut in shortcutList" :key="shortcut.key" class="flex items-center justify-between gap-4">
                <span class="text-text-muted">{{ shortcut.description }}</span>
                <kbd class="border-[1.5px] border-border bg-bg-subtle px-1.5 py-0.5 text-xs font-bold whitespace-nowrap text-text-strong">
                    {{ shortcut.key }}
                </kbd>
            </div>
        </div>
        <template #footer>
            <Button variant="primary" tone="purple" type="button" @click="open = false">Close</Button>
        </template>
    </Modal>
</template>
