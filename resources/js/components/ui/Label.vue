<script setup>
import { cn } from '@/lib/utils';

/**
 * The caption above a form control or filter. Deliberately small and muted
 * so the eye lands on the control's value, not on its caption. Spacing is
 * the caller's (`class="mb-1"`), since some labels share a row with a
 * button. Not for the text beside a checkbox - that stays a plain <label>.
 */
const props = defineProps({
    for: { type: String, default: undefined },
    class: { type: [String, Array, Object], default: '' },
});
</script>

<template>
    <label :for="props.for || undefined" :class="cn('block text-xs font-semibold text-text-muted', props.class)">
        <slot />
    </label>
</template>
