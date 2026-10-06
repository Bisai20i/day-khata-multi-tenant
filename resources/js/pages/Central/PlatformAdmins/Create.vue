<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import { UserPlus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import FormSection from '@/components/ui/FormSection.vue';
import FormField from '@/components/ui/FormField.vue';
import FormActions from '@/components/ui/FormActions.vue';

defineOptions({ layout: AppLayout });
useLayoutChrome('New Platform Admin');

const roleOptions = [
    { value: 'owner', label: 'Owner' },
    { value: 'support', label: 'Support' },
];

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'support',
});

function submit() {
    form.post('/platform-admins');
}
</script>

<template>
    <div>
        <PageHeader
            title="Add platform admin"
            description="Create an account that can sign in to this platform panel and manage tenants. Fields marked * are required."
            back-href="/platform-admins"
            back-label="All platform admins"
        />

        <form class="flex flex-col gap-8" @submit.prevent="submit">
            <FormSection title="Account details" description="Who this admin is and what they can do. Owners can manage platform admins and all tenants; Support staff have more limited access.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ describedBy }" label="Full name" for="name" required :error="form.errors.name">
                        <Input id="name" v-model="form.name" type="text" placeholder="e.g. Jane Doe" autocomplete="off" required :aria-describedby="describedBy" />
                    </FormField>
                    <FormField v-slot="{ describedBy }" label="Email address" for="email" required help="They will use this email to sign in." :error="form.errors.email">
                        <Input id="email" v-model="form.email" type="email" placeholder="jane@example.com" autocomplete="off" required :aria-describedby="describedBy" />
                    </FormField>
                    <FormField label="Role" for="role" required :error="form.errors.role">
                        <Select id="role" v-model="form.role" :options="roleOptions" />
                    </FormField>
                </div>
            </FormSection>

            <FormSection title="Password" description="Share the password with them securely. They can change it after signing in.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ describedBy }" label="Password" for="password" required help="Use at least 8 characters." :error="form.errors.password">
                        <Input id="password" v-model="form.password" type="password" placeholder="At least 8 characters" autocomplete="new-password" required :aria-describedby="describedBy" />
                    </FormField>
                    <FormField label="Confirm password" for="password_confirmation" required>
                        <Input id="password_confirmation" v-model="form.password_confirmation" type="password" placeholder="Re-enter the password" autocomplete="new-password" required />
                    </FormField>
                </div>
            </FormSection>

            <FormActions>
                <Button :as="Link" href="/platform-admins" variant="secondary" tone="neutral">Cancel</Button>
                <Button type="submit" variant="primary" tone="purple" :loading="form.processing">
                    <UserPlus class="size-4" />
                    Create admin
                </Button>
            </FormActions>
        </form>
    </div>
</template>
