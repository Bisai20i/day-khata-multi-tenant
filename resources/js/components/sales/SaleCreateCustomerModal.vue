<script setup>
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import Label from '@/components/ui/Label.vue';

defineProps({
    open: { type: Boolean, default: false },
    customerForm: { type: Object, required: true },
});

defineEmits(['close', 'submit']);
</script>

<template>
    <!-- Quick "+ New customer" -->
    <Modal :open="open" title="New customer" size="compact" @update:open="(v) => (v ? null : $emit('close'))">
        <form class="flex flex-col gap-4" @submit.prevent="$emit('submit')">
            <div>
                <Label class="mb-1">Name</Label>
                <Input v-model="customerForm.name" type="text" placeholder="e.g. Ram Sharma" required />
                <p v-if="customerForm.errors.name" class="mt-1 text-sm text-danger">{{ customerForm.errors.name }}</p>
            </div>
            <div>
                <Label class="mb-1">Mobile No</Label>
                <Input v-model="customerForm.mobile_no" type="text" placeholder="98XXXXXXXX" />
                <p v-if="customerForm.errors.mobile_no" class="mt-1 text-sm text-danger">{{ customerForm.errors.mobile_no }}</p>
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" tone="purple" type="button" @click="$emit('close')">Cancel</Button>
            <Button variant="primary" tone="purple" type="button" :disabled="customerForm.processing" @click="$emit('submit')">
                Create customer
            </Button>
        </template>
    </Modal>
</template>
