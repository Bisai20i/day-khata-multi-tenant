<script setup>
import { DropdownMenuContent, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuTrigger } from 'reka-ui';
import { cn } from '@/lib/utils';

const props = defineProps({
    align: { type: String, default: 'start' },
    sideOffset: { type: Number, default: 6 },
    class: { type: [String, Array, Object], default: '' },
});
</script>

<template>
    <DropdownMenuRoot>
        <DropdownMenuTrigger as-child>
            <slot name="trigger" />
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <DropdownMenuContent
                :align="align"
                :side-offset="sideOffset"
                :class="cn(
                    'z-50 min-w-[180px] border-[1.5px] border-border bg-bg-surface py-1 shadow-[0_8px_24px_rgba(0,0,0,.12)] outline-none',
                    props.class,
                )"
            >
                <slot />
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
