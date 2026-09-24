<script setup>
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Combobox from '@/components/ui/Combobox.vue';
import Modal from '@/components/ui/Modal.vue';

defineProps({
    supplierOpen: { type: Boolean, default: false },
    supplierForm: { type: Object, required: true },
    itemOpen: { type: Boolean, default: false },
    itemForm: { type: Object, required: true },
    itemCategoryOptions: { type: Array, default: () => [] },
});

defineEmits(['close-supplier', 'submit-supplier', 'close-item', 'submit-item']);
</script>

<template>
    <div>
        <!-- Quick "+ New supplier" -->
        <Modal :open="supplierOpen" title="New supplier" size="compact" @update:open="(v) => (v ? null : $emit('close-supplier'))">
            <form class="flex flex-col gap-4" @submit.prevent="$emit('submit-supplier')">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input v-model="supplierForm.name" type="text" placeholder="e.g. ABC Traders" required />
                    <p v-if="supplierForm.errors.name" class="mt-1 text-sm text-danger">{{ supplierForm.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Mobile No</label>
                    <Input v-model="supplierForm.mobile_no" type="text" placeholder="98XXXXXXXX" />
                    <p v-if="supplierForm.errors.mobile_no" class="mt-1 text-sm text-danger">{{ supplierForm.errors.mobile_no }}</p>
                </div>
            </form>
            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="$emit('close-supplier')">Cancel</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="supplierForm.processing" @click="$emit('submit-supplier')">
                    Create supplier
                </Button>
            </template>
        </Modal>

        <!-- Quick "+ New item" (item 9) -->
        <Modal :open="itemOpen" title="New item" size="compact" @update:open="(v) => (v ? null : $emit('close-item'))">
            <form class="flex flex-col gap-4" @submit.prevent="$emit('submit-item')">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                    <Input v-model="itemForm.name" type="text" placeholder="e.g. Coke 500ml" required />
                    <p v-if="itemForm.errors.name" class="mt-1 text-sm text-danger">{{ itemForm.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Category</label>
                    <Combobox
                        :model-value="itemForm.item_category_id"
                        :options="itemCategoryOptions"
                        placeholder="Select category"
                        @update:model-value="(v) => (itemForm.item_category_id = v)"
                    />
                    <p v-if="itemForm.errors.item_category_id" class="mt-1 text-sm text-danger">{{ itemForm.errors.item_category_id }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold text-text-base">Unit</label>
                    <Input v-model="itemForm.unit" type="text" placeholder="e.g. pcs" required />
                    <p v-if="itemForm.errors.unit" class="mt-1 text-sm text-danger">{{ itemForm.errors.unit }}</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Purchase Rate</label>
                        <Input v-model="itemForm.purchase_rate" type="number" min="0" step="0.0001" placeholder="Optional" />
                        <p v-if="itemForm.errors.purchase_rate" class="mt-1 text-sm text-danger">{{ itemForm.errors.purchase_rate }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-text-base">Sale Rate</label>
                        <Input v-model="itemForm.sale_rate" type="number" min="0" step="0.0001" placeholder="Optional" />
                        <p v-if="itemForm.errors.sale_rate" class="mt-1 text-sm text-danger">{{ itemForm.errors.sale_rate }}</p>
                    </div>
                </div>
            </form>
            <template #footer>
                <Button variant="secondary" tone="purple" type="button" @click="$emit('close-item')">Cancel</Button>
                <Button variant="primary" tone="purple" type="button" :disabled="itemForm.processing" @click="$emit('submit-item')">
                    Create item
                </Button>
            </template>
        </Modal>
    </div>
</template>
