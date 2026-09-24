<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';

/**
 * Shared page heading for the central (platform-admin) pages: a title, a
 * one-line plain-language description of what the page is for, an optional
 * "back to <parent>" link, and a slot for the primary action(s) on the right.
 */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    backHref: { type: String, default: '' },
    backLabel: { type: String, default: '' },
});
</script>

<template>
    <div class="mb-5">
        <Link
            v-if="backHref"
            :href="backHref"
            class="mb-2 inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
            {{ backLabel || 'Back' }}
        </Link>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-lg font-bold text-text-strong">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-sm text-text-muted">{{ description }}</p>
            </div>
            <div v-if="$slots.default" class="flex shrink-0 flex-wrap items-center gap-2">
                <slot />
            </div>
        </div>
    </div>
</template>
