<script setup>
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Modal from '@/components/ui/Modal.vue';
import Label from '@/components/ui/Label.vue';

defineProps({
    open: { type: Boolean, default: false },
    itemForm: { type: Object, required: true },
    itemCategoryOptions: { type: Array, default: () => [] },
});

defineEmits(['close', 'submit']);
</script>

<template>
    <!-- Quick "+ New item" -->
    <Modal :open="open" title="New item" size="compact" @update:open="(v) => (v ? null : $emit('close'))">
        <form class="flex flex-col gap-4" @submit.prevent="$emit('submit')">
            <div>
                <Label class="mb-1">Name <span class="text-danger">*</span></Label>
                <Input v-model="itemForm.name" type="text" placeholder="e.g. Coke 500ml" required />
                <p v-if="itemForm.errors.name" class="mt-1 text-sm text-danger">{{ itemForm.errors.name }}</p>
            </div>
            <div>
                <Label class="mb-1">Category <span class="text-danger">*</span></Label>
                <Combobox
                    :model-value="itemForm.item_category_id"
                    :options="itemCategoryOptions"
                    placeholder="Select category"
                    @update:model-value="(v) => (itemForm.item_category_id = v)"
                />
                <p v-if="itemForm.errors.item_category_id" class="mt-1 text-sm text-danger">{{ itemForm.errors.item_category_id }}</p>
            </div>
            <div>
                <Label class="mb-1">Unit <span class="text-danger">*</span></Label>
                <Input v-model="itemForm.unit" type="text" placeholder="e.g. pcs" required />
                <p v-if="itemForm.errors.unit" class="mt-1 text-sm text-danger">{{ itemForm.errors.unit }}</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <Label class="mb-1">Sale Rate</Label>
                    <Input v-model="itemForm.sale_rate" type="number" min="0" step="0.0001" placeholder="Optional" />
                    <p v-if="itemForm.errors.sale_rate" class="mt-1 text-sm text-danger">{{ itemForm.errors.sale_rate }}</p>
                </div>
                <div>
                    <Label class="mb-1">Purchase Rate</Label>
                    <Input v-model="itemForm.purchase_rate" type="number" min="0" step="0.0001" placeholder="Optional" />
                    <p v-if="itemForm.errors.purchase_rate" class="mt-1 text-sm text-danger">{{ itemForm.errors.purchase_rate }}</p>
                </div>
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" tone="purple" type="button" @click="$emit('close')">Cancel</Button>
            <Button variant="primary" tone="purple" type="button" :disabled="itemForm.processing" @click="$emit('submit')">
                Create item
            </Button>
        </template>
    </Modal>
</template>
