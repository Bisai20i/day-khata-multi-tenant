<script setup>
import { useForm } from '@inertiajs/vue3';
import { Lock, Mail } from '@lucide/vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <AuthLayout title="Sign in" tagline="Sales, stock, and khata - all in one place.">
        <h1 class="mb-1 text-xl font-bold text-text-strong">Sign in to your account</h1>
        <p class="mb-6 text-sm text-text-muted">Enter your email and password to open your shop's books.</p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div>
                <label for="email" class="mb-1 block text-sm font-semibold text-text-base">Email <span class="text-danger">*</span></label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    placeholder="you@example.com"
                    :icon="Mail"
                    autofocus
                    autocomplete="username"
                    required
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-semibold text-text-base">Password <span class="text-danger">*</span></label>
                <Input id="password" v-model="form.password" type="password" :icon="Lock" autocomplete="current-password" placeholder="Your password" required />
                <p v-if="form.errors.password" class="mt-1 text-sm text-danger">{{ form.errors.password }}</p>
            </div>

            <label class="inline-flex items-center gap-2 text-sm text-text-muted">
                <input v-model="form.remember" type="checkbox" name="remember" />
                Keep me signed in on this device
            </label>

            <Button type="submit" variant="primary" tone="purple" :loading="form.processing" class="w-full">
                {{ form.processing ? 'Signing in...' : 'Sign in' }}
            </Button>
        </form>
    </AuthLayout>
</template>
