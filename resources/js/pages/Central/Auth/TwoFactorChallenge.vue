<script setup>
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';

const form = useForm({
    code: '',
});

function submit() {
    form.post('/two-factor-challenge', {
        onFinish: () => form.reset('code'),
    });
}
</script>

<template>
    <AuthLayout title="Two-Factor Authentication">
        <h1 class="mb-2 text-base font-bold text-text-strong">Two-Factor Authentication</h1>
        <p class="mb-4 text-sm text-text-muted">
            Enter the 6-digit code from your authenticator app, or one of your recovery codes.
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div>
                <label for="code" class="mb-1 block text-sm font-semibold text-text-base">Code</label>
                <Input id="code" v-model="form.code" type="text" autofocus autocomplete="one-time-code" required />
                <p v-if="form.errors.code" class="mt-1 text-sm text-danger">{{ form.errors.code }}</p>
            </div>

            <Button type="submit" variant="primary" tone="purple" :disabled="form.processing" class="w-full">
                Verify
            </Button>
        </form>
    </AuthLayout>
</template>
