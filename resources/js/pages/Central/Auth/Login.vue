<script setup>
import { useForm } from '@inertiajs/vue3';
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
    <AuthLayout title="Platform Admin Login">
        <h1 class="mb-4 text-base font-bold text-text-strong">Platform Admin Login</h1>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div>
                <label for="email" class="mb-1 block text-sm font-semibold text-text-base">Email</label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    placeholder="you@example.com"
                    autofocus
                    required
                />
                <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-semibold text-text-base">Password</label>
                <Input id="password" v-model="form.password" type="password" required />
                <p v-if="form.errors.password" class="mt-1 text-sm text-danger">{{ form.errors.password }}</p>
            </div>

            <label class="inline-flex items-center gap-2 text-sm text-text-muted">
                <input v-model="form.remember" type="checkbox" name="remember" />
                Remember me
            </label>

            <Button type="submit" variant="primary" tone="purple" :disabled="form.processing" class="w-full">
                Log in
            </Button>
        </form>
    </AuthLayout>
</template>
