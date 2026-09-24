<script setup>
import Button from '@/components/ui/Button.vue';
import Modal from '@/components/ui/Modal.vue';

defineProps({
    open: { type: Boolean, default: false },
    carts: { type: Array, required: true },
    activeCartIndex: { type: Number, required: true },
    cartLabel: { type: Function, required: true },
    cartLineCount: { type: Function, required: true },
});

const emit = defineEmits(['close', 'merge']);
</script>

<template>
    <Modal :open="open" title="Merge a cart" size="compact" @update:open="(v) => (v ? null : emit('close'))">
        <div class="flex flex-col gap-1">
            <p v-if="carts.length === 1" class="py-2 text-center text-sm text-text-faint">No other carts to merge.</p>
            <template v-else>
                <div
                    v-for="(cart, index) in carts"
                    v-show="index !== activeCartIndex"
                    :key="cart.id"
                    class="flex items-center justify-between gap-3 border-b border-border py-2 last:border-0"
                >
                    <p class="text-sm font-semibold text-text-strong">
                        {{ cartLabel(cart, index) }}
                        <span class="font-normal text-text-muted">({{ cartLineCount(cart, index) }} item{{ cartLineCount(cart, index) === 1 ? '' : 's' }})</span>
                    </p>
                    <Button variant="secondary" tone="purple" type="button" class="!px-2.5 !py-1 !text-[11px]" @click="emit('merge', index)">
                        Merge in
                    </Button>
                </div>
            </template>
        </div>
        <template #footer>
            <Button variant="secondary" tone="purple" type="button" @click="emit('close')">Close</Button>
        </template>
    </Modal>
</template>
