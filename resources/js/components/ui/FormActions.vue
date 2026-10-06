<script setup>
/**
 * Sticky footer for a long form: status text on the left (e.g. "Unsaved
 * changes"), the submit/cancel buttons on the right. It sticks to the
 * bottom of the scrolling <main>, so the primary action is always in reach
 * without scrolling past every section. On phones the status line is
 * hidden and the buttons share the row equally.
 */
defineProps({
    status: { type: String, default: '' },
    emphasize: { type: Boolean, default: false },
});
</script>

<template>
    <div
        class="sticky bottom-0 z-10 -mx-4 -mb-4 flex items-center justify-between gap-3 border-t border-border bg-bg-surface/95 px-4 py-3 backdrop-blur-sm sm:-mx-6 sm:-mb-6 sm:px-6"
    >
        <p class="hidden min-w-0 text-[13px] sm:block" :class="emphasize ? 'font-semibold text-text-strong' : 'text-text-muted'" aria-live="polite">
            <slot name="status">{{ status }}</slot>
        </p>
        <div class="flex flex-1 items-center gap-2 sm:flex-none [&>*]:flex-1 sm:[&>*]:flex-none">
            <slot />
        </div>
    </div>
</template>
