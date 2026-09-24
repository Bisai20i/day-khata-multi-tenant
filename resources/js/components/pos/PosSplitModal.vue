<script setup>
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';

defineProps({
    open: { type: Boolean, default: false },
    lineIndex: { type: Number, default: null },
    lines: { type: Array, default: () => [] },
    itemsById: { type: Object, required: true },
});

const quantity = defineModel('quantity', { type: String, default: '' });
const emit = defineEmits(['close', 'confirm']);
</script>

<template>
    <Modal :open="open" title="Split into a new cart" size="compact" @update:open="(v) => (v ? null : emit('close'))">
        <div v-if="lineIndex !== null" class="flex flex-col gap-3 text-sm">
            <p class="text-text-muted">
                {{ itemsById[lines[lineIndex]?.item_id]?.name ?? 'Item' }} - current quantity
                {{ lines[lineIndex]?.quantity }}
            </p>
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Quantity to move to a new cart</label>
                <Input v-model="quantity" type="number" min="0" step="0.0001" placeholder="e.g. 1" />
            </div>
        </div>
        <template #footer>
            <Button variant="secondary" tone="purple" type="button" @click="emit('close')">Cancel</Button>
            <Button variant="primary" tone="purple" type="button" @click="emit('confirm')">Split</Button>
        </template>
    </Modal>
</template>
