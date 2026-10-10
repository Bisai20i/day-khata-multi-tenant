<script setup>
import { computed, useSlots } from 'vue';
import { cn } from '@/lib/utils';

/**
 * A bordered box. A panel's `title` (prop or slot) is its section heading
 * and renders just above the box, never inside it. `class` always styles
 * the box itself, so with a title put outer spacing or grid placement on a
 * wrapper element instead.
 */
const props = defineProps({
    variant: { type: String, default: 'panel' },
    title: { type: String, default: '' },
    class: { type: [String, Array, Object], default: '' },
});

const slots = useSlots();
const hasHeading = computed(() => props.variant === 'panel' && Boolean(props.title || slots.title));

const rootClasses = computed(() =>
    cn(
        'border-[1.5px] border-border p-3',
        props.variant === 'product'
            ? cn(
                  'bg-white transition-[transform,box-shadow,border-color] duration-200 [transition-timing-function:ease]',
                  'hover:border-[#C4B5FD] hover:shadow-[0_4px_16px_rgba(102,0,255,.15)]',
                  'motion-safe:active:scale-[.97]',
              )
            : 'bg-bg-subtle shadow-xs',
        hasHeading.value && 'flex-1',
        props.class,
    ),
);
</script>

<template>
    <div v-if="hasHeading" class="flex min-w-0 flex-col">
        <div class="mb-1.5 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase">
            <slot name="title">{{ title }}</slot>
        </div>
        <div :class="rootClasses">
            <slot />
        </div>
    </div>
    <div v-else :class="rootClasses">
        <slot />
    </div>
</template>
