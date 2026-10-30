<script setup>
/**
 * One label/value pair of a manufacturing detail card: the small uppercase
 * caption used across the app's headers and totals, the value under it.
 * The default slot replaces the plain `value` (a badge, a formatted date).
 */
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: '' },
});
</script>

<template>
    <div class="min-w-0">
        <p class="text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">{{ label }}</p>
        <div class="mt-0.5 break-words text-sm text-text-strong">
            <slot>{{ value || '-' }}</slot>
        </div>
    </div>
</template>
