<script setup>
import { computed } from 'vue';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const props = defineProps({
    as: { type: [String, Object, Function], default: 'button' },
    variant: { type: String, default: 'primary' },
    tone: { type: String, default: 'purple' },
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    class: { type: [String, Array, Object], default: '' },
});

const isButtonTag = computed(() => props.as === 'button');
const isInteractionBlocked = computed(() => props.disabled || props.loading);

const buttonVariants = cva(
    'inline-flex items-center justify-center gap-1.5 font-bold transition-all duration-150 ease-out whitespace-nowrap cursor-pointer',
    {
        variants: {
            variant: {
                primary: 'px-4 py-2.5 text-[13px]',
                secondary: 'border-[1.5px] px-4 py-2.5 text-[13px]',
                icon: 'h-8 w-8',
            },
        },
        defaultVariants: {
            variant: 'primary',
        },
    },
);

// Each tone carries its own primary (solid fill)/secondary (soft tint
// border)/icon styling, keyed by `variant`. `danger`/`success` reuse the
// same --color-danger/--color-success tokens Badge.vue already uses for its
// own danger/success variants - not a separate palette, the same one
// extended to buttons so "Delete" and "Resume" carry the same red/green
// meaning everywhere in the app, not just on badges.
const toneStyles = {
    purple: {
        primary: 'bg-primary text-white hover:bg-primary-dark',
        secondary: 'border-[#D8B4FE] bg-[#FAF5FF] text-primary hover:bg-[#F3E8FF]',
        icon: 'bg-primary-tint text-primary hover:bg-primary hover:text-white',
    },
    blue: {
        secondary: 'border-[#BAE6FD] bg-[#F0F9FF] text-accent hover:bg-[#E0F2FE]',
        icon: 'bg-[#F0F9FF] text-accent hover:bg-accent hover:text-white',
    },
    danger: {
        primary: 'bg-danger text-white hover:bg-[#B91C1C]',
        secondary: 'border-[#FECACA] bg-[#FEF2F2] text-danger hover:bg-danger-bg',
        icon: 'bg-danger-bg text-danger hover:bg-danger hover:text-white',
    },
    success: {
        primary: 'bg-success text-white hover:bg-[#14532D]',
        secondary: 'border-[#BBF7D0] bg-[#F0FDF4] text-success hover:bg-success-bg',
        icon: 'bg-success-bg text-success hover:bg-success hover:text-white',
    },
};

const classes = computed(() => {
    const tone = toneStyles[props.tone] ?? toneStyles.purple;
    const toneClass = tone[props.variant] ?? tone.secondary ?? toneStyles.purple.secondary;

    return cn(
        buttonVariants({ variant: props.variant }),
        toneClass,
        isInteractionBlocked.value ? 'pointer-events-none cursor-not-allowed opacity-50' : '',
        props.class,
    );
});

function onClick(event) {
    if (isInteractionBlocked.value && !isButtonTag.value) {
        event.preventDefault();
        event.stopPropagation();
    }
}
</script>

<template>
    <component
        :is="as"
        :type="isButtonTag ? type : undefined"
        :disabled="isButtonTag ? isInteractionBlocked : undefined"
        :aria-disabled="isInteractionBlocked"
        :aria-busy="loading"
        :class="classes"
        @click="onClick"
    >
        <span
            v-if="loading"
            class="day-khata-btn-spinner size-3.5 shrink-0 rounded-full border-2 border-current border-t-transparent opacity-80"
            aria-hidden="true"
        />
        <slot />
    </component>
</template>

<style scoped>
.day-khata-btn-spinner {
    animation: day-khata-btn-spin 0.6s linear infinite;
}

@keyframes day-khata-btn-spin {
    to {
        transform: rotate(360deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .day-khata-btn-spinner {
        animation: none;
    }
}
</style>
