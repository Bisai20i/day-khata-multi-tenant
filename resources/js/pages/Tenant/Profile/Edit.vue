<script setup>
import { computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Check, KeyRound } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import Card from '@/components/ui/Card.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import { useToast } from '@/composables/useToast';
import { navGroups } from '@/lib/nav-items.js';

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const { toast } = useToast();

const isAdmin = computed(() => page.props.auth?.user?.role?.slug === 'admin');

const navItems = computed(() => navGroups(isAdmin.value));

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
    profileForm.put('/profile');
}

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submitPassword() {
    passwordForm.put('/profile/password', {
        onSuccess: () => passwordForm.reset(),
    });
}
</script>

<template>
    <AppLayout title="My Profile" :nav-items="navItems">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-bold text-text-strong">My Profile</h2>
        </div>

        <div class="flex flex-col gap-4">
            <Card variant="panel">
                <h3 class="mb-3 text-sm font-bold text-text-strong">Profile Information</h3>
                <form class="flex flex-col gap-4" @submit.prevent="submitProfile">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="name" class="mb-1 block text-sm font-semibold text-text-base">
                                Name <span class="text-danger">*</span>
                            </label>
                            <Input id="name" v-model="profileForm.name" type="text" placeholder="e.g. Jane Doe" required />
                            <p v-if="profileForm.errors.name" class="mt-1 text-sm text-danger">{{ profileForm.errors.name }}</p>
                        </div>
                        <div>
                            <label for="email" class="mb-1 block text-sm font-semibold text-text-base">
                                Email <span class="text-danger">*</span>
                            </label>
                            <Input id="email" v-model="profileForm.email" type="email" placeholder="you@example.com" required />
                            <p v-if="profileForm.errors.email" class="mt-1 text-sm text-danger">{{ profileForm.errors.email }}</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-end">
                        <Button variant="primary" tone="purple" type="submit" :loading="profileForm.processing">
                            <Check class="size-4" />
                            Save changes
                        </Button>
                    </div>
                </form>
            </Card>

            <Card variant="panel">
                <h3 class="mb-3 text-sm font-bold text-text-strong">Change Password</h3>
                <form class="flex flex-col gap-4" @submit.prevent="submitPassword">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label for="current_password" class="mb-1 block text-sm font-semibold text-text-base">
                                Current password <span class="text-danger">*</span>
                            </label>
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
                            <label for="password" class="mb-1 block text-sm font-semibold text-text-base">
                                New password <span class="text-danger">*</span>
                            </label>
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
                            <label for="password_confirmation" class="mb-1 block text-sm font-semibold text-text-base">
                                Confirm new password <span class="text-danger">*</span>
                            </label>
                            <Input
                                id="password_confirmation"
                                v-model="passwordForm.password_confirmation"
                                type="password"
                                placeholder="Re-enter the new password"
                                autocomplete="new-password"
                                required
                            />
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
    </AppLayout>
</template>
