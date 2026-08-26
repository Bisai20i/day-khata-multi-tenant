<script setup>
defineProps({
    label: { type: String, required: true },
    side: { type: String, default: 'top' },
});

const sideClasses = {
    top: 'bottom-full left-1/2 mb-[7px] -translate-x-1/2 translate-y-1',
    bottom: 'top-full left-1/2 mt-[7px] -translate-x-1/2 -translate-y-1',
};
</script>

<template>
    <span class="group/tooltip relative inline-flex">
        <slot />
        <span
            role="tooltip"
            class="pointer-events-none absolute z-30 bg-toast-bg px-2 py-1 text-[11px] font-semibold whitespace-nowrap text-white opacity-0 transition-[opacity,transform] duration-150 ease-out group-hover/tooltip:translate-y-0 group-hover/tooltip:opacity-100 group-focus-within/tooltip:translate-y-0 group-focus-within/tooltip:opacity-100"
            :class="sideClasses[side] ?? sideClasses.top"
        >
            {{ label }}
        </span>
    </span>
</template>
