<script setup>
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
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

const props = defineProps({
    admin: {
        type: Object,
        required: true,
    },
});

useLayoutChrome(() => `Edit ${props.admin.name}`);

const roleOptions = [
    { value: 'owner', label: 'Owner' },
    { value: 'support', label: 'Support' },
];

// Select's modelValue only accepts String/Number/null, so is_active (a
// boolean on the form) is bridged through 1/0 here rather than passed
// straight through - avoids a Vue prop-type warning on every keystroke
// (same bridge Tenant/Admin/Users.vue already uses for the same reason).
const statusOptions = [
    { value: 1, label: 'Active' },
    { value: 0, label: 'Inactive' },
];

const form = useForm({
    name: props.admin.name,
    email: props.admin.email,
    password: '',
    password_confirmation: '',
    role: props.admin.role,
    is_active: props.admin.is_active,
});

const isActiveOption = computed({
    get: () => (form.is_active ? 1 : 0),
    set: (value) => {
        form.is_active = value === 1;
    },
});

function submit() {
    form.put(`/platform-admins/${props.admin.id}`);
}
</script>

<template>
    <div>
        <PageHeader
            title="Edit platform admin"
            :description="`Update details, role and access for ${admin.name}. Set status to Inactive to block sign-in.`"
            back-href="/platform-admins"
            back-label="All platform admins"
        />

        <form class="flex flex-col gap-8" @submit.prevent="submit">
            <FormSection title="Account details" description="Name and sign-in email for this admin.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ describedBy }" label="Name" for="name" required :error="form.errors.name">
                        <Input id="name" v-model="form.name" type="text" placeholder="e.g. Jane Doe" required :aria-describedby="describedBy" />
                    </FormField>
                    <FormField v-slot="{ describedBy }" label="Email" for="email" required :error="form.errors.email">
                        <Input id="email" v-model="form.email" type="email" placeholder="you@example.com" required :aria-describedby="describedBy" />
                    </FormField>
                </div>
            </FormSection>

            <FormSection title="Access" description="Owners can manage platform admins and all tenants. Inactive admins cannot sign in; admins are never deleted.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField label="Role" for="role" required :error="form.errors.role">
                        <Select id="role" v-model="form.role" :options="roleOptions" />
                    </FormField>
                    <FormField label="Status" for="is_active" required :error="form.errors.is_active">
                        <Select id="is_active" v-model="isActiveOption" :options="statusOptions" />
                    </FormField>
                </div>
            </FormSection>

            <FormSection title="Change password" description="Optional. Leave both fields blank to keep the current password.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ describedBy }" label="New password" for="password" :error="form.errors.password">
                        <Input id="password" v-model="form.password" type="password" placeholder="Leave blank to keep current" autocomplete="new-password" :aria-describedby="describedBy" />
                    </FormField>
                    <FormField label="Confirm password" for="password_confirmation">
                        <Input id="password_confirmation" v-model="form.password_confirmation" type="password" placeholder="Confirm new password" autocomplete="new-password" />
                    </FormField>
                </div>
            </FormSection>

            <FormActions :status="form.isDirty ? 'Unsaved changes' : 'No changes yet'" :emphasize="form.isDirty">
                <Button :as="Link" href="/platform-admins" variant="secondary" tone="neutral">Cancel</Button>
                <Button type="submit" variant="primary" tone="purple" :loading="form.processing" :disabled="!form.isDirty">
                    <Check class="size-4" />
                    Save changes
                </Button>
            </FormActions>
        </form>
    </div>
</template>
