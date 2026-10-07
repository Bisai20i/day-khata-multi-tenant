<script setup>
import { ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Send } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import FormSection from '@/components/ui/FormSection.vue';
import FormField from '@/components/ui/FormField.vue';
import FormActions from '@/components/ui/FormActions.vue';
import { useToast } from '@/composables/useToast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Platform Settings');

// Update/test-email both redirect back to this same route, and Inertia
// patches the already-mounted instance rather than remounting it - watch
// (not onMounted) so the toast fires on every redirect back here, not just
// the first page load.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const form = useForm({
    mail_mailer: props.settings.mail_mailer ?? '',
    mail_host: props.settings.mail_host ?? '',
    mail_port: props.settings.mail_port ?? '',
    mail_username: props.settings.mail_username ?? '',
    mail_password: '',
    mail_encryption: props.settings.mail_encryption ?? '',
    mail_from_address: props.settings.mail_from_address ?? '',
    mail_from_name: props.settings.mail_from_name ?? '',
    platform_name: props.settings.platform_name ?? '',
    support_email: props.settings.support_email ?? '',
    default_trial_days: props.settings.default_trial_days,
    default_grace_period_days: props.settings.default_grace_period_days,
});

function submit() {
    form.put('/settings');
}

function discard() {
    form.reset();
    form.clearErrors();
}

const sendingTestEmail = ref(false);

function sendTestEmail() {
    router.post(
        '/settings/test-email',
        {},
        { preserveScroll: true, onStart: () => (sendingTestEmail.value = true), onFinish: () => (sendingTestEmail.value = false) },
    );
}
</script>

<template>
    <div>
        <PageHeader
            title="Platform settings"
            description="Branding, default trial and grace periods for new tenants, and the email account the platform sends from."
        />

        <form class="flex flex-col gap-8" @submit.prevent="submit">
            <FormSection title="Branding" description="How the platform introduces itself in emails and page titles.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ describedBy }" label="Platform name" for="platform_name" help="Shown in emails and page titles." :error="form.errors.platform_name">
                        <Input id="platform_name" v-model="form.platform_name" type="text" placeholder="e.g. Acme Cloud" :aria-describedby="describedBy" />
                    </FormField>
                    <FormField v-slot="{ describedBy }" label="Support email" for="support_email" help="Where tenants are told to write for help." :error="form.errors.support_email">
                        <Input id="support_email" v-model="form.support_email" type="email" placeholder="support@example.com" :aria-describedby="describedBy" />
                    </FormField>
                </div>
            </FormSection>

            <FormSection title="Tenant lifecycle defaults" description="Applied to every new tenant. Existing tenants keep their own dates, which you can change on each tenant's page.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ describedBy }" label="Free trial length (days)" for="default_trial_days" required help="How long a new tenant can use the platform free before it must subscribe." :error="form.errors.default_trial_days">
                        <Input id="default_trial_days" v-model="form.default_trial_days" type="number" min="0" max="365" inputmode="numeric" required :aria-describedby="describedBy" />
                    </FormField>
                    <FormField v-slot="{ describedBy }" label="Grace period after expiry (days)" for="default_grace_period_days" required help="Days a tenant keeps access after its plan expires before being suspended." :error="form.errors.default_grace_period_days">
                        <Input id="default_grace_period_days" v-model="form.default_grace_period_days" type="number" min="0" max="365" inputmode="numeric" required :aria-describedby="describedBy" />
                    </FormField>
                </div>
            </FormSection>

            <FormSection title="Email (SMTP)" description="The mail account the platform sends welcome, password reset and notification emails from. Save first, then send a test email to check it works.">
                <template #aside>
                    <Button class="mt-3 w-full sm:w-auto" variant="secondary" tone="purple" type="button" :loading="sendingTestEmail" @click="sendTestEmail">
                        <Send class="size-4" />
                        Send test email
                    </Button>
                </template>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <FormField v-slot="{ describedBy }" label="Mail driver" for="mail_mailer" help="Usually smtp." :error="form.errors.mail_mailer">
                        <Input id="mail_mailer" v-model="form.mail_mailer" type="text" placeholder="smtp" :aria-describedby="describedBy" />
                    </FormField>
                    <FormField v-slot="{ describedBy }" label="Connection security" for="mail_encryption" help="tls or ssl; match your mail provider." :error="form.errors.mail_encryption">
                        <Input id="mail_encryption" v-model="form.mail_encryption" type="text" placeholder="tls" :aria-describedby="describedBy" />
                    </FormField>
                    <FormField v-slot="{ describedBy }" label="SMTP server" for="mail_host" :error="form.errors.mail_host">
                        <Input id="mail_host" v-model="form.mail_host" type="text" placeholder="smtp.example.com" :aria-describedby="describedBy" />
                    </FormField>
                    <FormField v-slot="{ describedBy }" label="SMTP port" for="mail_port" :error="form.errors.mail_port">
                        <Input id="mail_port" v-model="form.mail_port" type="number" placeholder="587" min="1" max="65535" inputmode="numeric" :aria-describedby="describedBy" />
                    </FormField>
                    <FormField v-slot="{ describedBy }" label="SMTP username" for="mail_username" :error="form.errors.mail_username">
                        <Input id="mail_username" v-model="form.mail_username" type="text" placeholder="you@example.com" autocomplete="off" :aria-describedby="describedBy" />
                    </FormField>
                    <FormField
                        v-slot="{ describedBy }"
                        label="SMTP password"
                        for="mail_password"
                        :help="settings.mail_password_set ? 'A password is saved. Leave blank to keep it.' : ''"
                        :error="form.errors.mail_password"
                    >
                        <Input
                            id="mail_password"
                            v-model="form.mail_password"
                            type="password"
                            :placeholder="settings.mail_password_set ? '••••••••' : 'Enter the SMTP password'"
                            autocomplete="new-password"
                            :aria-describedby="describedBy"
                        />
                    </FormField>
                    <FormField v-slot="{ describedBy }" label="Sender email address" for="mail_from_address" help="Address recipients see as the sender." :error="form.errors.mail_from_address">
                        <Input id="mail_from_address" v-model="form.mail_from_address" type="email" placeholder="noreply@example.com" :aria-describedby="describedBy" />
                    </FormField>
                    <FormField v-slot="{ describedBy }" label="Sender name" for="mail_from_name" :error="form.errors.mail_from_name">
                        <Input id="mail_from_name" v-model="form.mail_from_name" type="text" placeholder="e.g. Support Team" :aria-describedby="describedBy" />
                    </FormField>
                </div>
            </FormSection>

            <FormActions :status="form.isDirty ? 'Unsaved changes' : 'All changes saved'" :emphasize="form.isDirty">
                <Button variant="secondary" tone="neutral" type="button" :disabled="!form.isDirty || form.processing" @click="discard">Discard</Button>
                <Button variant="primary" tone="purple" type="submit" :loading="form.processing" :disabled="!form.isDirty">
                    <Check class="size-4" />
                    Save changes
                </Button>
            </FormActions>
        </form>
    </div>
</template>
