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
    class: { type: [String, Array, Object], default: '' },
});

const isButtonTag = computed(() => props.as === 'button');

const buttonVariants = cva(
    'inline-flex items-center justify-center gap-1.5 font-bold transition-all duration-150 ease-out whitespace-nowrap',
    {
        variants: {
            variant: {
                primary: 'bg-primary px-4 py-2.5 text-[13px] text-white shadow-[0_4px_14px_rgba(102,0,255,.35)] hover:bg-primary-dark',
                secondary: 'border-[1.5px] px-4 py-2.5 text-[13px]',
                icon: 'h-8 w-8 bg-primary-tint text-primary hover:bg-primary hover:text-white',
            },
        },
        defaultVariants: {
            variant: 'primary',
        },
    },
);

const toneClasses = {
    purple: 'border-[#D8B4FE] bg-[#FAF5FF] text-primary hover:bg-[#F3E8FF]',
    blue: 'border-[#BAE6FD] bg-[#F0F9FF] text-accent hover:bg-[#E0F2FE]',
};

const classes = computed(() =>
    cn(
        buttonVariants({ variant: props.variant }),
        props.variant === 'secondary' ? toneClasses[props.tone] : '',
        props.disabled ? 'pointer-events-none opacity-50' : '',
        props.class,
    ),
);

function onClick(event) {
    if (props.disabled && !isButtonTag.value) {
        event.preventDefault();
        event.stopPropagation();
    }
}
</script>

<template>
    <component
        :is="as"
        :type="isButtonTag ? type : undefined"
        :disabled="isButtonTag ? disabled : undefined"
        :aria-disabled="disabled"
        :class="classes"
        @click="onClick"
    >
        <slot />
    </component>
</template>
