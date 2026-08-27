<script setup>
import { ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Building2, LayoutDashboard } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps({
    enabled: { type: Boolean, required: true },
    pendingSecret: { type: String, default: null },
    qrCodeDataUri: { type: String, default: null },
    recoveryCodes: { type: Array, default: null },
});

const page = usePage();
const { toast } = useToast();

// This page is reached via redirect()/Inertia::render() to the same route
// repeatedly (generate -> confirm -> disable), so Inertia patches the
// existing instance rather than remounting it. onMounted would only ever
// fire once; watch the flash prop instead (see mem.md's documented gotcha).
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) {
            toast({ message: status, variant: 'success' });
        }
    },
    { immediate: true },
);

const navItems = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard },
    { label: 'Tenants', href: '/tenants', icon: Building2 },
];

const generateForm = useForm({});
const confirmForm = useForm({ code: '' });
const disableForm = useForm({ password: '' });

function generate() {
    generateForm.post('/two-factor');
}

function confirm() {
    confirmForm.post('/two-factor/confirm', {
        onSuccess: () => confirmForm.reset(),
    });
}

function disable() {
    disableForm.delete('/two-factor', {
        onSuccess: () => disableForm.reset(),
    });
}

const acknowledgedRecoveryCodes = ref(false);
</script>

<template>
    <AppLayout title="Two-Factor Authentication" :nav-items="navItems">
        <Card variant="panel" title="Two-Factor Authentication" class="max-w-lg">
            <!-- Just confirmed: show the one-time recovery codes. -->
            <template v-if="recoveryCodes && !acknowledgedRecoveryCodes">
                <p class="mb-3 text-sm text-text-base">
                    Two-factor authentication is enabled. Save these recovery codes somewhere safe — each
                    one can be used once if you lose access to your authenticator app, and they will not
                    be shown again.
                </p>
                <ul class="mb-4 grid grid-cols-2 gap-1.5 border border-border-soft bg-bg-subtle p-3 font-mono text-[12.5px] text-text-strong">
                    <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
                </ul>
                <Button variant="primary" tone="purple" @click="acknowledgedRecoveryCodes = true">
                    I've saved these codes
                </Button>
            </template>

            <!-- Already enabled (no fresh recovery codes to show this load). -->
            <template v-else-if="enabled">
                <div class="mb-4 flex items-center gap-2">
                    <Badge variant="success" pill>Enabled</Badge>
                    <span class="text-sm text-text-muted">Your account is protected by an authenticator app.</span>
                </div>

                <form class="flex max-w-xs flex-col gap-3" @submit.prevent="disable">
                    <div>
                        <label for="disable-password" class="mb-1 block text-sm font-semibold text-text-base">
                            Current password
                        </label>
                        <Input id="disable-password" v-model="disableForm.password" type="password" required />
                        <p v-if="disableForm.errors.password" class="mt-1 text-sm text-danger">
                            {{ disableForm.errors.password }}
                        </p>
                    </div>
                    <Button
                        type="submit"
                        variant="secondary"
                        tone="purple"
                        :disabled="disableForm.processing"
                        class="w-fit"
                    >
                        Disable two-factor authentication
                    </Button>
                </form>
            </template>

            <!-- A secret was generated but not yet confirmed: show the QR code. -->
            <template v-else-if="pendingSecret">
                <p class="mb-3 text-sm text-text-base">
                    Scan this QR code with your authenticator app (Google Authenticator, 1Password, etc.),
                    or enter the secret manually, then confirm with a generated code.
                </p>

                <img :src="qrCodeDataUri" alt="Two-factor authentication QR code" class="mb-3 size-[240px] border border-border-soft" />

                <p class="mb-4 font-mono text-[12.5px] text-text-muted">{{ pendingSecret }}</p>

                <form class="flex max-w-xs flex-col gap-3" @submit.prevent="confirm">
                    <div>
                        <label for="confirm-code" class="mb-1 block text-sm font-semibold text-text-base">
                            Code from your app
                        </label>
                        <Input id="confirm-code" v-model="confirmForm.code" type="text" autocomplete="one-time-code" required />
                        <p v-if="confirmForm.errors.code" class="mt-1 text-sm text-danger">
                            {{ confirmForm.errors.code }}
                        </p>
                    </div>
                    <Button
                        type="submit"
                        variant="primary"
                        tone="purple"
                        :disabled="confirmForm.processing"
                        class="w-fit"
                    >
                        Confirm and enable
                    </Button>
                </form>
            </template>

            <!-- Nothing set up yet. -->
            <template v-else>
                <p class="mb-4 text-sm text-text-muted">
                    Two-factor authentication is not enabled. Enabling it requires a code from an
                    authenticator app on every future login.
                </p>
                <Button variant="primary" tone="purple" :disabled="generateForm.processing" @click="generate">
                    Enable two-factor authentication
                </Button>
            </template>
        </Card>
    </AppLayout>
</template>
