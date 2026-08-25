<script setup>
import { cn } from '@/lib/utils';

const props = defineProps({
    class: { type: [String, Array, Object], default: '' },
});
</script>

<template>
    <div
        :class="cn(
            'mb-1.5 border-[1.5px] border-border bg-white p-[9px] transition-[border-color,box-shadow] duration-200 [transition-timing-function:ease]',
            'hover:border-[#C4B5FD] hover:shadow-[0_4px_16px_rgba(102,0,255,.15)]',
            props.class,
        )"
    >
        <div class="flex items-center justify-between gap-2">
            <slot name="primary" />
        </div>
        <div class="mt-1.5 flex items-center justify-between gap-2">
            <slot name="secondary" />
        </div>
    </div>
</template>
