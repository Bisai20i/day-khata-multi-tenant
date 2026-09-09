<script setup>
import { TooltipContent, TooltipPortal, TooltipProvider, TooltipRoot, TooltipTrigger } from 'reka-ui';

defineProps({
    label: { type: String, required: true },
    side: { type: String, default: 'top' },
    sideOffset: { type: Number, default: 7 },
});
</script>

<template>
    <TooltipProvider :delay-duration="200" :disable-hoverable-content="true">
        <TooltipRoot>
            <TooltipTrigger as-child>
                <slot />
            </TooltipTrigger>
            <TooltipPortal>
                <TooltipContent
                    :side="side"
                    :side-offset="sideOffset"
                    :avoid-collisions="true"
                    :collision-padding="8"
                    class="pointer-events-none z-50 bg-toast-bg px-2 py-1 text-[11px] font-semibold whitespace-nowrap text-white"
                >
                    {{ label }}
                </TooltipContent>
            </TooltipPortal>
        </TooltipRoot>
    </TooltipProvider>
</template>
