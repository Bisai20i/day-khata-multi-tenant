<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import { UserPlus } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { useLayoutChrome } from '@/composables/useLayoutChrome';
import Card from '@/components/ui/Card.vue';
import Input from '@/components/ui/Input.vue';
import Select from '@/components/ui/Select.vue';
import Button from '@/components/ui/Button.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

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
            description="Create an account that can sign in to this platform panel and manage tenants."
            back-href="/platform-admins"
            back-label="All platform admins"
        />

        <Card variant="panel" class="max-w-lg">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <h3 class="text-sm font-bold text-text-strong">Account details</h3>

                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold text-text-base">Full name <span class="text-danger">*</span></label>
                    <Input id="name" v-model="form.name" type="text" placeholder="e.g. Jane Doe" autocomplete="off" required />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="email" class="mb-1 block text-sm font-semibold text-text-base">Email address <span class="text-danger">*</span></label>
                    <Input id="email" v-model="form.email" type="email" placeholder="jane@example.com" autocomplete="off" required />
                    <p class="mt-1 text-xs text-text-muted">They will use this email to sign in.</p>
                    <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label for="role" class="mb-1 block text-sm font-semibold text-text-base">Role <span class="text-danger">*</span></label>
                    <Select id="role" v-model="form.role" :options="roleOptions" />
                    <p class="mt-1 text-xs text-text-muted">
                        Owners can manage platform admins and all tenants. Support staff have more limited access.
                    </p>
                    <p v-if="form.errors.role" class="mt-1 text-sm text-danger">{{ form.errors.role }}</p>
                </div>

                <h3 class="mt-2 border-t border-border-soft pt-4 text-sm font-bold text-text-strong">Password</h3>

                <div>
                    <label for="password" class="mb-1 block text-sm font-semibold text-text-base">Password <span class="text-danger">*</span></label>
                    <Input id="password" v-model="form.password" type="password" placeholder="At least 8 characters" autocomplete="new-password" required />
                    <p class="mt-1 text-xs text-text-muted">Use at least 8 characters.</p>
                    <p v-if="form.errors.password" class="mt-1 text-sm text-danger">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label for="password_confirmation" class="mb-1 block text-sm font-semibold text-text-base">Confirm password <span class="text-danger">*</span></label>
                    <Input id="password_confirmation" v-model="form.password_confirmation" type="password" placeholder="Re-enter the password" autocomplete="new-password" required />
                </div>

                <div class="flex gap-2">
                    <Button type="submit" variant="primary" tone="purple" :loading="form.processing" class="flex-1">
                        <UserPlus class="size-4" />
                        Create admin
                    </Button>
                    <Button :as="Link" href="/platform-admins" variant="secondary" tone="purple" class="flex-1 justify-center">
                        Cancel
                    </Button>
                </div>
            </form>
        </Card>
    </div>
</template>
