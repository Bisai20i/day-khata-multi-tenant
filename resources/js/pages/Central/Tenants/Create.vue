<script setup>
import { computed, ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { Eye, EyeOff, Plus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import FormSection from '@/components/ui/FormSection.vue';
import FormField from '@/components/ui/FormField.vue';
import FormActions from '@/components/ui/FormActions.vue';
import ModuleSelector from './ModuleSelector.vue';

defineOptions({ layout: AppLayout });
useLayoutChrome('New Tenant');

const props = defineProps({
    moduleCatalog: { type: Array, default: () => [] },
    defaultModules: { type: Array, default: () => [] },
    tenantBaseDomain: { type: String, default: 'localhost' },
});

const form = useForm({
    company_name: '',
    subdomain: '',
    contact_email: '',
    admin_name: '',
    admin_email: '',
    admin_password: '',
    // Always sent explicitly, so the server never falls back to its default.
    enabled_modules: [...props.defaultModules],
});

const showPassword = ref(false);

// Per-item errors (enabled_modules.2) are folded into one line under the list.
const modulesError = computed(
    () => form.errors.enabled_modules ?? Object.entries(form.errors).find(([key]) => key.startsWith('enabled_modules.'))?.[1] ?? '',
);

const previewAddress = computed(() => `${(form.subdomain || 'acme').toLowerCase()}.${props.tenantBaseDomain}`);

function submit() {
    form.post('/tenants');
}
</script>

<template>
    <div>
        <PageHeader
            title="New tenant"
            description="Create a company workspace with its own web address and first admin user. Fields marked * are required."
            back-href="/tenants"
            back-label="All tenants"
        />

        <form class="flex flex-col gap-8" @submit.prevent="submit">
            <FormSection title="Company" description="The business this workspace is for. The name appears on the tenant's screens and invoices.">
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

            <FormSection title="Web address" description="Where the tenant's staff sign in. It must be unique. You can add or remove domains later from the tenant page.">
                <FormField label="Subdomain" for="subdomain" required :error="form.errors.subdomain">
                    <template #default>
                        <Input
                            id="subdomain"
                            v-model="form.subdomain"
                            type="text"
                            placeholder="acme"
                            autocomplete="off"
                            autocapitalize="none"
                            spellcheck="false"
                            required
                            :aria-describedby="form.errors.subdomain ? 'subdomain-error' : 'subdomain-help'"
                        >
                            <template #addon>
                                <span class="max-w-[50vw] truncate bg-bg-muted px-3 text-[13px] text-text-muted sm:max-w-none">.{{ tenantBaseDomain }}</span>
                            </template>
                        </Input>
                        <p v-if="!form.errors.subdomain" id="subdomain-help" class="mt-1.5 text-xs text-text-muted">
                            Letters, numbers and hyphens only. The address will be
                            <span class="font-semibold break-all text-text-strong">{{ previewAddress }}</span>
                        </p>
                    </template>
                </FormField>
            </FormSection>

            <FormSection title="First admin user" description="This person becomes the company owner and can invite the rest of their staff.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ describedBy }" label="Full name" for="admin_name" required :error="form.errors.admin_name">
                        <Input id="admin_name" v-model="form.admin_name" type="text" placeholder="e.g. Jane Doe" autocomplete="off" required :aria-describedby="describedBy" />
                    </FormField>
                    <FormField
                        v-slot="{ describedBy }"
                        label="Email"
                        for="admin_email"
                        required
                        help="Their login email."
                        :error="form.errors.admin_email"
                    >
                        <Input id="admin_email" v-model="form.admin_email" type="email" placeholder="jane@acme.com" autocomplete="off" required :aria-describedby="describedBy" />
                    </FormField>
                    <FormField
                        v-slot="{ describedBy }"
                        label="Password"
                        for="admin_password"
                        required
                        help="Share it securely. They can change it after signing in."
                        :error="form.errors.admin_password"
                        class="sm:col-span-2 lg:col-span-1"
                    >
                        <Input
                            id="admin_password"
                            v-model="form.admin_password"
                            :type="showPassword ? 'text' : 'password'"
                            placeholder="At least 8 characters"
                            autocomplete="new-password"
                            required
                            :aria-describedby="describedBy"
                        >
                            <template #addon>
                                <button
                                    type="button"
                                    class="flex h-full w-10 cursor-pointer items-center justify-center text-text-muted hover:text-text-strong focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary"
                                    :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                    :aria-pressed="showPassword"
                                    @click="showPassword = !showPassword"
                                >
                                    <EyeOff v-if="showPassword" class="size-4" />
                                    <Eye v-else class="size-4" />
                                </button>
                            </template>
                        </Input>
                    </FormField>
                </div>
            </FormSection>

            <FormSection title="Modules" description="Features this company may use. Its owner can only give staff access to ticked modules. You can change this later.">
                <ModuleSelector v-model="form.enabled_modules" :catalog="moduleCatalog" id-prefix="create-module" :error="modulesError" />
            </FormSection>

            <FormActions status="The workspace is set up in the background; it shows as Provisioning until ready.">
                <Button :as="Link" href="/tenants" variant="secondary" tone="neutral">Cancel</Button>
                <Button type="submit" variant="primary" tone="purple" :loading="form.processing">
                    <Plus class="size-4" />
                    Create tenant
                </Button>
            </FormActions>
        </form>
    </div>
</template>
