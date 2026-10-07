<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';

/**
 * Shared page heading for the central (platform-admin) pages: a title, a
 * one-line plain-language description of what the page is for, an optional
 * "back to <parent>" link, and a slot for the primary action(s) on the right.
 * The optional `meta` slot sits inline after the title (e.g. a status badge).
 * Actions wrap under the title on narrow screens instead of overflowing.
 */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    backHref: { type: String, default: '' },
    backLabel: { type: String, default: '' },
});
</script>

<template>
    <div class="mb-5 sm:mb-6">
        <Link
            v-if="backHref"
            :href="backHref"
            class="mb-2 inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
            {{ backLabel || 'Back' }}
        </Link>
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between">
            <div class="min-w-0 sm:flex-1">
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <h2 class="text-lg font-bold break-words text-text-strong sm:text-xl">{{ title }}</h2>
                    <slot name="meta" />
                </div>
                <p v-if="description" class="mt-1 max-w-3xl text-sm text-text-muted">{{ description }}</p>
            </div>
            <div v-if="$slots.default" class="flex flex-wrap items-center gap-2">
                <slot />
            </div>
        </div>
    </div>
</template>
