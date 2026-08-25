<script setup>
import { DropdownMenuItem as RekaDropdownMenuItem } from 'reka-ui';
import { cn } from '@/lib/utils';

const props = defineProps({
    disabled: { type: Boolean, default: false },
    class: { type: [String, Array, Object], default: '' },
});

const emit = defineEmits(['select']);
</script>

<template>
    <RekaDropdownMenuItem
        :disabled="disabled"
        :class="cn(
            'relative flex cursor-pointer select-none items-center gap-2 px-2.5 py-2 text-[13px] text-text-base outline-none transition-colors duration-150',
            'data-[highlighted]:bg-primary-tint data-[highlighted]:text-primary',
            'data-[disabled]:pointer-events-none data-[disabled]:cursor-not-allowed data-[disabled]:text-text-faint',
            props.class,
        )"
        @select="emit('select', $event)"
    >
        <slot />
    </RekaDropdownMenuItem>
</template>
