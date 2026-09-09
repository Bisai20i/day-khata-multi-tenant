<script setup>
import { ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Building2, Check, History, LayoutDashboard, Send, Settings as SettingsIcon, ShieldCheck } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const { toast } = useToast();

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

const navItems = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard },
    { label: 'Tenants', href: '/tenants', icon: Building2 },
    { label: 'Activity log', href: '/activity-log', icon: History },
    { label: 'Settings', href: '/settings', icon: SettingsIcon },
    { label: 'Platform admins', href: '/platform-admins', icon: ShieldCheck },
];

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
    <AppLayout title="Platform Settings" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">Platform Settings</h2>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <Card variant="panel">
                <h3 class="mb-3 text-sm font-bold text-text-strong">Branding</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="platform_name" class="mb-1 block text-sm font-semibold text-text-base">Platform name</label>
                        <Input id="platform_name" v-model="form.platform_name" type="text" placeholder="e.g. Acme Cloud" />
                        <p v-if="form.errors.platform_name" class="mt-1 text-sm text-danger">{{ form.errors.platform_name }}</p>
                    </div>
                    <div>
                        <label for="support_email" class="mb-1 block text-sm font-semibold text-text-base">Support email</label>
                        <Input id="support_email" v-model="form.support_email" type="email" placeholder="you@example.com" />
                        <p v-if="form.errors.support_email" class="mt-1 text-sm text-danger">{{ form.errors.support_email }}</p>
                    </div>
                </div>
            </Card>

            <Card variant="panel">
                <h3 class="mb-3 text-sm font-bold text-text-strong">Tenant lifecycle defaults</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="default_trial_days" class="mb-1 block text-sm font-semibold text-text-base">Default trial days <span class="text-danger">*</span></label>
                        <Input id="default_trial_days" v-model="form.default_trial_days" type="number" min="0" max="365" required />
                        <p v-if="form.errors.default_trial_days" class="mt-1 text-sm text-danger">{{ form.errors.default_trial_days }}</p>
                    </div>
                    <div>
                        <label for="default_grace_period_days" class="mb-1 block text-sm font-semibold text-text-base">
                            Default grace period days <span class="text-danger">*</span>
                        </label>
                        <Input
                            id="default_grace_period_days"
                            v-model="form.default_grace_period_days"
                            type="number"
                            min="0"
                            max="365"
                            required
                        />
                        <p v-if="form.errors.default_grace_period_days" class="mt-1 text-sm text-danger">
                            {{ form.errors.default_grace_period_days }}
                        </p>
                    </div>
                </div>
            </Card>

            <Card variant="panel">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-text-strong">Mail</h3>
                    <Button variant="secondary" tone="purple" type="button" :loading="sendingTestEmail" @click="sendTestEmail">
                        <Send class="size-4" />
                        Send test email
                    </Button>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="mail_mailer" class="mb-1 block text-sm font-semibold text-text-base">Mailer</label>
                        <Input id="mail_mailer" v-model="form.mail_mailer" type="text" placeholder="smtp" />
                        <p v-if="form.errors.mail_mailer" class="mt-1 text-sm text-danger">{{ form.errors.mail_mailer }}</p>
                    </div>
                    <div>
                        <label for="mail_encryption" class="mb-1 block text-sm font-semibold text-text-base">Encryption</label>
                        <Input id="mail_encryption" v-model="form.mail_encryption" type="text" placeholder="tls" />
                        <p v-if="form.errors.mail_encryption" class="mt-1 text-sm text-danger">{{ form.errors.mail_encryption }}</p>
                    </div>
                    <div>
                        <label for="mail_host" class="mb-1 block text-sm font-semibold text-text-base">Host</label>
                        <Input id="mail_host" v-model="form.mail_host" type="text" placeholder="smtp.example.com" />
                        <p v-if="form.errors.mail_host" class="mt-1 text-sm text-danger">{{ form.errors.mail_host }}</p>
                    </div>
                    <div>
                        <label for="mail_port" class="mb-1 block text-sm font-semibold text-text-base">Port</label>
                        <Input id="mail_port" v-model="form.mail_port" type="number" min="1" max="65535" placeholder="587" />
                        <p v-if="form.errors.mail_port" class="mt-1 text-sm text-danger">{{ form.errors.mail_port }}</p>
                    </div>
                    <div>
                        <label for="mail_username" class="mb-1 block text-sm font-semibold text-text-base">Username</label>
                        <Input id="mail_username" v-model="form.mail_username" type="text" placeholder="you@example.com" />
                        <p v-if="form.errors.mail_username" class="mt-1 text-sm text-danger">{{ form.errors.mail_username }}</p>
                    </div>
                    <div>
                        <label for="mail_password" class="mb-1 block text-sm font-semibold text-text-base">
                            Password
                            <span v-if="settings.mail_password_set" class="font-normal text-text-muted">(leave blank to keep current)</span>
                        </label>
                        <Input
                            id="mail_password"
                            v-model="form.mail_password"
                            type="password"
                            placeholder="Leave blank to keep current"
                            autocomplete="new-password"
                        />
                        <p v-if="form.errors.mail_password" class="mt-1 text-sm text-danger">{{ form.errors.mail_password }}</p>
                    </div>
                    <div>
                        <label for="mail_from_address" class="mb-1 block text-sm font-semibold text-text-base">From address</label>
                        <Input id="mail_from_address" v-model="form.mail_from_address" type="email" placeholder="noreply@example.com" />
                        <p v-if="form.errors.mail_from_address" class="mt-1 text-sm text-danger">{{ form.errors.mail_from_address }}</p>
                    </div>
                    <div>
                        <label for="mail_from_name" class="mb-1 block text-sm font-semibold text-text-base">From name</label>
                        <Input id="mail_from_name" v-model="form.mail_from_name" type="text" placeholder="e.g. Support Team" />
                        <p v-if="form.errors.mail_from_name" class="mt-1 text-sm text-danger">{{ form.errors.mail_from_name }}</p>
                    </div>
                </div>
            </Card>

            <div class="flex items-center justify-end gap-2">
                <Button variant="primary" tone="purple" type="submit" :loading="form.processing">
                    <Check class="size-4" />
                    Save changes
                </Button>
            </div>
        </form>
    </AppLayout>
</template>
