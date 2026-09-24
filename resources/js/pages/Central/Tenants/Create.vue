<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

defineOptions({ layout: AppLayout });
useLayoutChrome('New Tenant');

const form = useForm({
    company_name: '',
    subdomain: '',
    contact_email: '',
    admin_name: '',
    admin_email: '',
    admin_password: '',
});

function submit() {
    form.post('/tenants');
}
</script>

<template>
    <div>
        <PageHeader
            title="New tenant"
            description="Create a company workspace with its own domain and first admin user. Fields marked * are required."
            back-href="/tenants"
            back-label="All tenants"
        />

        <form class="flex max-w-lg flex-col gap-4" @submit.prevent="submit">
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
                </div>
            </Card>

            <Card variant="panel" title="Domain">
                <div>
                    <label for="subdomain" class="mb-1 block text-sm font-semibold text-text-base">Subdomain <span class="text-danger">*</span></label>
                    <div class="flex items-center gap-1.5">
                        <Input id="subdomain" v-model="form.subdomain" type="text" placeholder="acme" required class="flex-1" :aria-describedby="form.errors.subdomain ? 'subdomain-error' : 'subdomain-help'" />
                        <span class="text-sm text-text-muted">.localhost</span>
                    </div>
                    <p v-if="form.errors.subdomain" id="subdomain-error" class="mt-1 text-sm text-danger">{{ form.errors.subdomain }}</p>
                    <p v-else id="subdomain-help" class="mt-1 text-xs text-text-muted">
                        The tenant's address becomes <span class="font-semibold">{{ form.subdomain || 'acme' }}.localhost</span>. It must be unique.
                    </p>
                </div>
            </Card>

            <Card variant="panel" title="First admin user">
                <div class="flex flex-col gap-4">
                    <div>
                        <label for="admin_name" class="mb-1 block text-sm font-semibold text-text-base">Full name <span class="text-danger">*</span></label>
                        <Input id="admin_name" v-model="form.admin_name" type="text" placeholder="e.g. Jane Doe" required :aria-describedby="form.errors.admin_name ? 'admin_name-error' : undefined" />
                        <p v-if="form.errors.admin_name" id="admin_name-error" class="mt-1 text-sm text-danger">{{ form.errors.admin_name }}</p>
                    </div>

                    <div>
                        <label for="admin_email" class="mb-1 block text-sm font-semibold text-text-base">Email <span class="text-danger">*</span></label>
                        <Input id="admin_email" v-model="form.admin_email" type="email" placeholder="you@example.com" required :aria-describedby="form.errors.admin_email ? 'admin_email-error' : 'admin_email-help'" />
                        <p v-if="form.errors.admin_email" id="admin_email-error" class="mt-1 text-sm text-danger">{{ form.errors.admin_email }}</p>
                        <p v-else id="admin_email-help" class="mt-1 text-xs text-text-muted">This is the login email for the tenant's first admin.</p>
                    </div>

                    <div>
                        <label for="admin_password" class="mb-1 block text-sm font-semibold text-text-base">Password <span class="text-danger">*</span></label>
                        <Input id="admin_password" v-model="form.admin_password" type="password" placeholder="Enter a password" required :aria-describedby="form.errors.admin_password ? 'admin_password-error' : 'admin_password-help'" />
                        <p v-if="form.errors.admin_password" id="admin_password-error" class="mt-1 text-sm text-danger">{{ form.errors.admin_password }}</p>
                        <p v-else id="admin_password-help" class="mt-1 text-xs text-text-muted">Use a strong password. Share it with the admin securely; they can change it after signing in.</p>
                    </div>
                </div>
            </Card>

            <div class="flex gap-2">
                <Button type="submit" variant="primary" tone="purple" :loading="form.processing">
                    <Plus class="size-4" />
                    Create tenant
                </Button>
                <Button :as="Link" href="/tenants" variant="secondary" tone="purple">Cancel</Button>
            </div>
        </form>
    </div>
</template>
