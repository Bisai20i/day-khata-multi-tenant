<script setup>
import { useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';

/**
 * Small "name it and carry on" modal for creating a master record (category,
 * brand, ...) from inside another form, without leaving that form.
 *
 * Posts `{ name, ...payload, quick_add: true }` to `url`. `quick_add` makes
 * the store endpoint redirect back to the current page instead of to its own
 * index, so the page's props reload with the new record while the outer form
 * keeps its state. `created` then hands back the name to find it by - see
 * findCreatedByName() in lib/formModal.js.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    url: { type: String, required: true },
    placeholder: { type: String, default: '' },
    payload: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:open', 'created']);

const formId = useId();
const form = useForm({ name: '' });

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
    },
);

function submit() {
    const name = form.name;

    form.transform((data) => ({ ...data, ...props.payload, is_active: true, quick_add: true })).post(props.url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            emit('created', name);
            emit('update:open', false);
        },
    });
}
</script>

<template>
    <Modal :open="open" :title="title" :description="description" size="compact" @update:open="(value) => emit('update:open', value)">
        <form :id="formId" @submit.prevent="submit">
            <FormField v-slot="{ describedBy }" label="Name" :for="`${formId}-name`" required :error="form.errors.name">
                <Input
                    :id="`${formId}-name`"
                    v-model="form.name"
                    type="text"
                    :placeholder="placeholder"
                    :aria-describedby="describedBy"
                    :aria-invalid="form.errors.name ? 'true' : undefined"
                    required
                    data-autofocus
                />
            </FormField>
        </form>

        <template #footer>
            <Button variant="secondary" tone="purple" type="button" @click="emit('update:open', false)">Cancel</Button>
            <Button variant="primary" tone="purple" type="submit" :form="formId" :disabled="form.processing">
                {{ form.processing ? 'Adding...' : 'Add' }}
            </Button>
        </template>
    </Modal>
</template>
