<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import FormSection from '@/components/ui/FormSection.vue';
import FormField from '@/components/ui/FormField.vue';
import FormActions from '@/components/ui/FormActions.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tenant: {
        type: Object,
        required: true,
    },
});

useLayoutChrome(() => `Edit ${props.tenant.company_name}`);

const form = useForm({
    company_name: props.tenant.company_name,
    contact_email: props.tenant.contact_email ?? '',
});

function submit() {
    form.put(`/tenants/${props.tenant.id}`);
}
</script>

<template>
    <div>
        <PageHeader
            :title="`Edit ${tenant.company_name}`"
            description="Update the company's name and contact details. Domains are managed on the tenant page. Fields marked * are required."
            :back-href="`/tenants/${tenant.id}`"
            :back-label="`Back to ${tenant.company_name}`"
        />

        <form class="flex flex-col gap-8" @submit.prevent="submit">
            <FormSection title="Company" description="The business name shown on the tenant's screens and invoices, and how to reach them about their account.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ describedBy }" label="Company name" for="company_name" required :error="form.errors.company_name">
                        <Input id="company_name" v-model="form.company_name" type="text" placeholder="e.g. Acme Traders" autocomplete="organization" required :aria-describedby="describedBy" />
                    </FormField>
                    <FormField
                        v-slot="{ describedBy }"
                        label="Contact email"
                        for="contact_email"
                        help="Optional. Used to reach the company about their account."
                        :error="form.errors.contact_email"
                    >
                        <Input id="contact_email" v-model="form.contact_email" type="email" placeholder="billing@acme.com" autocomplete="email" :aria-describedby="describedBy" />
                    </FormField>
                </div>
            </FormSection>

            <FormActions :status="form.isDirty ? 'Unsaved changes' : 'No changes yet'" :emphasize="form.isDirty">
                <Button :as="Link" :href="`/tenants/${tenant.id}`" variant="secondary" tone="neutral">Cancel</Button>
                <Button type="submit" variant="primary" tone="purple" :loading="form.processing" :disabled="!form.isDirty">
                    <Check class="size-4" />
                    Save changes
                </Button>
            </FormActions>
        </form>
    </div>
</template>
