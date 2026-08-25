<script setup>
import { useToast } from '@/composables/useToast';

const { toasts, dismiss } = useToast();

const variantDotClasses = {
    success: 'bg-free-text',
    danger: 'bg-danger',
    default: 'bg-text-faint',
};
</script>

<template>
    <TransitionGroup
        tag="div"
        name="toast"
        class="pointer-events-none fixed inset-x-0 bottom-6 z-[100] flex flex-col items-center gap-2 px-4"
    >
        <div
            v-for="item in toasts"
            :key="item.id"
            role="status"
            class="pointer-events-auto flex items-center gap-2 bg-toast-bg px-4 py-2.5 text-[12.5px] font-semibold text-white shadow-[0_10px_25px_rgba(0,0,0,.15)]"
            @click="dismiss(item.id)"
        >
            <span
                v-if="item.variant !== 'default'"
                class="h-1.5 w-1.5 shrink-0 rounded-full"
                :class="variantDotClasses[item.variant] ?? variantDotClasses.default"
            />
            {{ item.message }}
        </div>
    </TransitionGroup>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(14px);
}

.toast-move {
    transition: transform 0.2s ease;
}

@media (prefers-reduced-motion: reduce) {
    .toast-enter-active,
    .toast-leave-active,
    .toast-move {
        transition: opacity 0.2s ease;
    }

    .toast-enter-from,
    .toast-leave-to {
        transform: none;
    }
}
</style>
