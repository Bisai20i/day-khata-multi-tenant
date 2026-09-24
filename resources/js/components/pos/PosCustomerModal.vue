<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';

/**
 * Quick "+ New customer" modal. Reuses the existing POST /customers endpoint
 * exactly as Tenant/Parties/Customers/Index.vue does (same fields, same
 * route); on success it emits `created` with the submitted name/mobile so the
 * page can bounce back to /pos and auto-select the new customer.
 */
const open = defineModel('open', { type: Boolean, default: false });
const emit = defineEmits(['created']);

const customerForm = useForm({ name: '', mobile_no: '' });

watch(open, () => {
    customerForm.reset();
    customerForm.clearErrors();
});

function closeModal() {
    open.value = false;
}

function submitCustomer() {
    const pending = { name: customerForm.name, mobile_no: customerForm.mobile_no };

    customerForm.post('/customers', {
        onSuccess: () => emit('created', pending),
    });
}
</script>

<template>
    <Modal :open="open" title="New customer" size="compact" @update:open="(v) => (v ? null : closeModal())">
        <form class="flex flex-col gap-4" @submit.prevent="submitCustomer">
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Name</label>
                <Input v-model="customerForm.name" type="text" placeholder="e.g. Ram Sharma" required />
                <p v-if="customerForm.errors.name" class="mt-1 text-sm text-danger">{{ customerForm.errors.name }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold text-text-base">Mobile No</label>
                <Input v-model="customerForm.mobile_no" type="text" placeholder="98XXXXXXXX" />
                <p v-if="customerForm.errors.mobile_no" class="mt-1 text-sm text-danger">{{ customerForm.errors.mobile_no }}</p>
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" tone="purple" type="button" @click="closeModal">Cancel</Button>
            <Button variant="primary" tone="purple" type="button" :disabled="customerForm.processing" @click="submitCustomer">
                Create customer
            </Button>
        </template>
    </Modal>
</template>
