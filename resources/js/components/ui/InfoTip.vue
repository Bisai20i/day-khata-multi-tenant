<script setup>
import { TooltipContent, TooltipPortal, TooltipProvider, TooltipRoot, TooltipTrigger } from 'reka-ui';
import { Info } from '@lucide/vue';

/**
 * A small info icon that shows a longer, wrapping help text on hover or
 * keyboard focus. Use it where a help line under the control would crowd the
 * form; Tooltip.vue stays the one for short single-line labels.
 */
defineProps({
    text: { type: String, required: true },
    side: { type: String, default: 'top' },
});
</script>

<template>
    <TooltipProvider :delay-duration="100">
        <TooltipRoot>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="flex shrink-0 cursor-help items-center text-text-faint outline-none hover:text-primary focus-visible:text-primary"
                    aria-label="More information"
                >
                    <Info class="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            </TooltipTrigger>
            <TooltipPortal>
                <TooltipContent
                    :side="side"
                    align="start"
                    :side-offset="6"
                    :avoid-collisions="true"
                    :collision-padding="8"
                    class="z-50 max-w-64 bg-toast-bg px-2.5 py-1.5 text-[12px] leading-relaxed font-medium text-white"
                >
                    {{ text }}
                </TooltipContent>
            </TooltipPortal>
        </TooltipRoot>
    </TooltipProvider>
</template>
