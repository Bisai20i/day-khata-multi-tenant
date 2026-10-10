<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle } from '@lucide/vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Modal from '@/components/ui/Modal.vue';
import Label from '@/components/ui/Label.vue';

/**
 * Changes one of a tenant's domains in place. Rendered by Show.vue; the admin
 * must type the current domain back before the change is allowed, and the
 * server enforces the same check.
 */
const props = defineProps({
    tenantId: { type: [String, Number], required: true },
    domain: { type: Object, default: null },
});

const open = defineModel('open', { type: Boolean, default: false });

const form = useForm({ domain: '', current_domain: '' });

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
    }
});

const newDomain = computed(() => form.domain.trim().toLowerCase());

const canSubmit = computed(
    () => props.domain !== null && newDomain.value !== '' && newDomain.value !== props.domain.domain && form.current_domain === props.domain.domain,
);

function submit() {
    if (!canSubmit.value) {
        return;
    }

    form.put(`/tenants/${props.tenantId}/domains/${props.domain.id}`, {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    });
}
</script>

<template>
    <Modal v-model:open="open" title="Change domain" size="compact">
        <form v-if="domain" id="edit-domain-form" class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="flex items-start gap-2">
                <AlertTriangle class="mt-0.5 size-4 shrink-0 text-danger" aria-hidden="true" />
                <p class="text-sm text-text-base">
                    This tenant will stop answering at
                    <span class="font-semibold break-all text-text-strong">{{ domain.domain }}</span>
                    immediately. Its users must use the new address and sign in again.
                </p>
            </div>

            <div>
                <Label for="edit_domain_new" class="mb-1.5">New domain</Label>
                <Input
                    id="edit_domain_new"
                    v-model="form.domain"
                    type="text"
                    placeholder="shop.example.com"
                    autocapitalize="none"
                    spellcheck="false"
                    :aria-describedby="form.errors.domain ? 'edit_domain_new-error' : undefined"
                />
                <p v-if="form.errors.domain" id="edit_domain_new-error" class="mt-1.5 text-sm text-danger" role="alert">{{ form.errors.domain }}</p>
            </div>

            <div>
                <Label for="edit_domain_current" class="mb-1.5">
                    Type the current domain to confirm
                </Label>
                <Input
                    id="edit_domain_current"
                    v-model="form.current_domain"
                    type="text"
                    :placeholder="domain.domain"
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    :aria-describedby="form.errors.current_domain ? 'edit_domain_current-error' : undefined"
                />
                <p v-if="form.errors.current_domain" id="edit_domain_current-error" class="mt-1.5 text-sm text-danger" role="alert">
                    {{ form.errors.current_domain }}
                </p>
            </div>
        </form>

        <template #footer>
            <Button variant="secondary" tone="neutral" @click="open = false">Keep domain</Button>
            <Button type="submit" form="edit-domain-form" variant="primary" tone="danger" :disabled="!canSubmit" :loading="form.processing">
                Change domain
            </Button>
        </template>
    </Modal>
</template>
