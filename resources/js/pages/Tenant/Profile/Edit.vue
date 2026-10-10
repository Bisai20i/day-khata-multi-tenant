<script setup>
import { watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Check, KeyRound } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Input from '@/components/ui/Input.vue';
import InfoTip from '@/components/ui/InfoTip.vue';
import Label from '@/components/ui/Label.vue';
import { useToast } from '@/composables/useToast';

defineOptions({ layout: AppLayout });

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const { toast } = useToast();
useLayoutChrome('My Profile');

// Profile-info and change-password submit to two different routes, so both
// get their own form/error set - a mistyped current password shouldn't
// clear a half-edited name/email field, or vice versa.
watch(
    () => page.props.flash?.status,
    (status) => {
        if (status) toast({ message: status, variant: 'success' });
    },
    { immediate: true },
);

const profileForm = useForm({
    name: props.user.name ?? '',
    email: props.user.email ?? '',
});

function submitProfile() {
    profileForm.put('/profile', {
        onSuccess: () => toast({ message: 'Account details saved.', variant: 'success' }),
    });
}

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submitPassword() {
    passwordForm.put('/profile/password', {
        onSuccess: () => {
            passwordForm.reset();
            toast({ message: 'Password updated.', variant: 'success' });
        },
    });
}
</script>

<template>
    <div>
        <PageHeader title="My Profile" description="Manage your sign-in details and password." />

        <div class="flex flex-col gap-4">
            <Card variant="panel">
                <div class="mb-3 flex items-center gap-1">
                    <h3 class="text-sm font-bold text-text-strong">Account details</h3>
                    <InfoTip text="Your name and the email you use to sign in." />
                </div>
                <form class="flex flex-col gap-4" @submit.prevent="submitProfile">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <Label for="name" class="mb-1">
                                Name <span class="text-danger">*</span>
                            </Label>
                            <Input id="name" v-model="profileForm.name" type="text" autocomplete="name" placeholder="e.g. Jane Doe" required />
                            <p v-if="profileForm.errors.name" class="mt-1 text-sm text-danger">{{ profileForm.errors.name }}</p>
                        </div>
                        <div>
                            <Label for="email" class="mb-1">
                                Email <span class="text-danger">*</span>
                            </Label>
                            <Input id="email" v-model="profileForm.email" type="email" autocomplete="username" placeholder="you@example.com" required />
                            <p v-if="profileForm.errors.email" class="mt-1 text-sm text-danger">{{ profileForm.errors.email }}</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-end">
                        <Button variant="primary" tone="purple" type="submit" :loading="profileForm.processing">
                            <Check class="size-4" />
                            Save account details
                        </Button>
                    </div>
                </form>
            </Card>

            <Card variant="panel">
                <div class="mb-3 flex items-center gap-1">
                    <h3 class="text-sm font-bold text-text-strong">Change password</h3>
                    <InfoTip text="Use at least 8 characters. You will stay signed in after changing it." />
                </div>
                <form class="flex flex-col gap-4" @submit.prevent="submitPassword">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <Label for="current_password" class="mb-1">
                                Current password <span class="text-danger">*</span>
                            </Label>
                            <Input
                                id="current_password"
                                v-model="passwordForm.current_password"
                                type="password"
                                placeholder="Enter your current password"
                                autocomplete="current-password"
                                required
                            />
                            <p v-if="passwordForm.errors.current_password" class="mt-1 text-sm text-danger">
                                {{ passwordForm.errors.current_password }}
                            </p>
                        </div>
                        <div>
                            <Label for="password" class="mb-1">
                                New password <span class="text-danger">*</span>
                            </Label>
                            <Input
                                id="password"
                                v-model="passwordForm.password"
                                type="password"
                                placeholder="At least 8 characters"
                                autocomplete="new-password"
                                required
                            />
                            <p v-if="passwordForm.errors.password" class="mt-1 text-sm text-danger">{{ passwordForm.errors.password }}</p>
                        </div>
                        <div>
                            <Label for="password_confirmation" class="mb-1">
                                Confirm new password <span class="text-danger">*</span>
                            </Label>
                            <Input
                                id="password_confirmation"
                                v-model="passwordForm.password_confirmation"
                                type="password"
                                placeholder="Re-enter the new password"
                                autocomplete="new-password"
                                required
                            />
                            <p v-if="passwordForm.errors.password_confirmation" class="mt-1 text-sm text-danger">
                                {{ passwordForm.errors.password_confirmation }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center justify-end">
                        <Button variant="primary" tone="purple" type="submit" :loading="passwordForm.processing">
                            <KeyRound class="size-4" />
                            Update password
                        </Button>
                    </div>
                </form>
            </Card>
        </div>
    </div>
</template>
