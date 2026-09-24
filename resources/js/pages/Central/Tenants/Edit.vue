<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

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

        <form class="max-w-lg" @submit.prevent="submit">
            <Card variant="panel" title="Company">
                <div class="flex flex-col gap-4">
                    <div>
                        <label for="company_name" class="mb-1 block text-sm font-semibold text-text-base">Company name <span class="text-danger">*</span></label>
                        <Input id="company_name" v-model="form.company_name" type="text" placeholder="e.g. Acme Traders" required :aria-describedby="form.errors.company_name ? 'company_name-error' : undefined" />
                        <p v-if="form.errors.company_name" id="company_name-error" class="mt-1 text-sm text-danger">{{ form.errors.company_name }}</p>
                    </div>

                    <div>
                        <label for="contact_email" class="mb-1 block text-sm font-semibold text-text-base">Contact email</label>
                        <Input id="contact_email" v-model="form.contact_email" type="email" placeholder="you@example.com" :aria-describedby="form.errors.contact_email ? 'contact_email-error' : 'contact_email-help'" />
                        <p v-if="form.errors.contact_email" id="contact_email-error" class="mt-1 text-sm text-danger">{{ form.errors.contact_email }}</p>
                        <p v-else id="contact_email-help" class="mt-1 text-xs text-text-muted">Optional. Used to reach the company about their account.</p>
                    </div>

                    <div class="flex gap-2">
                        <Button type="submit" variant="primary" tone="purple" :loading="form.processing">
                            <Check class="size-4" />
                            Save changes
                        </Button>
                        <Button :as="Link" :href="`/tenants/${tenant.id}`" variant="secondary" tone="purple">Cancel</Button>
                    </div>
                </div>
            </Card>
        </form>
    </div>
</template>
