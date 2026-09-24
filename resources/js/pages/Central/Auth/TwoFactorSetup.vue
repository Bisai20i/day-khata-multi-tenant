<script setup>
import { ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue';
import { useToast } from '@/composables/useToast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    enabled: { type: Boolean, required: true },
    pendingSecret: { type: String, default: null },
    qrCodeDataUri: { type: String, default: null },
    recoveryCodes: { type: Array, default: null },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('Two-Factor Authentication');

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

function copyRecoveryCodes() {
    navigator.clipboard?.writeText(props.recoveryCodes.join('
')).then(() => toast({ message: 'Recovery codes copied.', variant: 'success' }));
}

function downloadRecoveryCodes() {
    const url = URL.createObjectURL(new Blob([props.recoveryCodes.join('
') + '
'], { type: 'text/plain' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'recovery-codes.txt';
    link.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <Card variant="panel" title="Two-Factor Authentication" class="max-w-lg">
            <!-- Just confirmed: show the one-time recovery codes. -->
            <template v-if="recoveryCodes && !acknowledgedRecoveryCodes">
                <p class="mb-1 text-xs font-bold uppercase text-text-muted">Step 3 of 3 - Save your recovery codes</p>
                <p class="mb-3 bg-warning-bg px-3 py-2 text-sm font-semibold text-warning-text" role="alert">
                    These codes are shown only once. Store them somewhere safe before continuing.
                </p>
                <p class="mb-3 text-sm text-text-base">
                    Two-factor authentication is enabled. Save these recovery codes somewhere safe - each
                    one can be used once if you lose access to your authenticator app, and they will not
                    be shown again.
                </p>
                <ul class="mb-4 grid grid-cols-2 gap-1.5 border border-border-soft bg-bg-subtle p-3 font-mono text-[12.5px] text-text-strong">
                    <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
                </ul>
                <div class="mb-4 flex flex-wrap gap-2">
                    <Button variant="secondary" tone="purple" type="button" @click="copyRecoveryCodes">Copy codes</Button>
                    <Button variant="secondary" tone="purple" type="button" @click="downloadRecoveryCodes">Download .txt</Button>
                </div>
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
                            Current password <span class="text-danger">*</span>
                        </label>
                        <Input id="disable-password" v-model="disableForm.password" type="password" placeholder="Enter your password" required />
                        <p v-if="disableForm.errors.password" class="mt-1 text-sm text-danger">
                            {{ disableForm.errors.password }}
                        </p>
                    </div>
                    <Button
                        type="submit"
                        variant="secondary"
                        tone="purple"
                        :loading="disableForm.processing"
                        class="w-fit"
                    >
                        Disable two-factor authentication
                    </Button>
                </form>
            </template>

            <!-- A secret was generated but not yet confirmed: show the QR code. -->
            <template v-else-if="pendingSecret">
                <p class="mb-1 text-xs font-bold uppercase text-text-muted">Step 1 of 3 - Scan the QR code</p>
                <p class="mb-3 text-sm text-text-base">
                    Open an authenticator app (Google Authenticator, 1Password, etc.) and scan this code, or enter the secret key manually.
                </p>

                <img :src="qrCodeDataUri" alt="Two-factor authentication QR code" class="mb-3 size-[240px] border border-border-soft" />

                <p class="mb-4 font-mono text-[12.5px] text-text-muted">Secret key: {{ pendingSecret }}</p>
                <p class="mb-2 text-xs font-bold uppercase text-text-muted">Step 2 of 3 - Enter the code</p>

                <form class="flex max-w-xs flex-col gap-3" @submit.prevent="confirm">
                    <div>
                        <label for="confirm-code" class="mb-1 block text-sm font-semibold text-text-base">
                            Code from your app <span class="text-danger">*</span>
                        </label>
                        <Input id="confirm-code" v-model="confirmForm.code" type="text" placeholder="123456" autocomplete="one-time-code" inputmode="numeric" required />
                        <p v-if="confirmForm.errors.code" class="mt-1 text-sm text-danger">
                            {{ confirmForm.errors.code }}
                        </p>
                    </div>
                    <Button
                        type="submit"
                        variant="primary"
                        tone="purple"
                        :loading="confirmForm.processing"
                        class="w-fit"
                    >
                        Confirm and turn on
                    </Button>
                </form>
            </template>

            <!-- Nothing set up yet. -->
            <template v-else>
                <p class="mb-4 text-sm text-text-muted">
                    Two-factor authentication is not enabled. Enabling it requires a code from an
                    authenticator app on every future login.
                </p>
                <Button variant="primary" tone="purple" :loading="generateForm.processing" @click="generate">
                    Enable two-factor authentication
                </Button>
            </template>
        </Card>
</template>
