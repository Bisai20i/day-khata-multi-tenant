<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps({
    variant: { type: String, default: 'panel' },
    title: { type: String, default: '' },
    class: { type: [String, Array, Object], default: '' },
});

const rootClasses = computed(() =>
    cn(
        'border-[1.5px] border-border p-3',
        props.variant === 'product'
            ? cn(
                  'bg-white transition-[transform,box-shadow,border-color] duration-200 [transition-timing-function:ease]',
                  'motion-safe:hover:-translate-y-0.5 hover:border-[#C4B5FD] hover:shadow-[0_4px_16px_rgba(102,0,255,.15)]',
                  'motion-safe:active:scale-[.97]',
              )
            : 'bg-bg-subtle',
        props.class,
    ),
);
</script>

<template>
    <div :class="rootClasses">
        <div
            v-if="variant === 'panel' && (title || $slots.title)"
            class="mb-2 text-[10px] font-bold tracking-[.8px] text-text-muted uppercase"
        >
            <slot name="title">{{ title }}</slot>
        </div>
        <slot />
    </div>
</template>
