<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    title: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-bg-page px-4">
        <div class="mb-6">
            <span class="text-2xl font-bold text-text-strong">Day Khata</span>
        </div>

        <div class="w-full max-w-sm border border-border bg-bg-surface p-6 shadow-sm">
            <slot />
        </div>
    </div>
</template>
