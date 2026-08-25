<script setup>
import { X } from '@lucide/vue';
import { cn } from '@/lib/utils';

const props = defineProps({
    variant: { type: String, default: 'neutral' },
    pill: { type: Boolean, default: false },
    closable: { type: Boolean, default: false },
    class: { type: [String, Array, Object], default: '' },
});

const emit = defineEmits(['close']);

const variantClasses = {
    tax: 'bg-tax-bg text-tax-text',
    free: 'bg-free-bg text-free-text',
    danger: 'bg-danger-bg text-danger',
    warning: 'bg-warning-bg text-warning-text',
    success: 'bg-success-bg text-success',
    neutral: 'bg-bg-muted text-text-muted',
};
</script>

<template>
    <span
        :class="cn(
            'inline-flex items-center gap-1 font-bold leading-none',
            pill ? 'px-2.5 py-1 text-[11px] tracking-normal' : 'px-[5px] py-[2px] text-[8.5px] tracking-[.3px]',
            variantClasses[variant],
            props.class,
        )"
    >
        <slot />
        <button
            v-if="pill && closable"
            type="button"
            class="inline-flex items-center text-current transition-colors duration-150 hover:text-danger"
            @click="emit('close')"
        >
            <X class="h-3 w-3" />
        </button>
    </span>
</template>
