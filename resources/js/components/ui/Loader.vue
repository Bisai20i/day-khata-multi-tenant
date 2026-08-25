<script setup>
defineProps({
    overlay: { type: Boolean, default: false },
    message: { type: String, default: '' },
});
</script>

<template>
    <div
        v-if="overlay"
        class="fixed inset-0 z-50 flex items-center justify-center bg-overlay-strong"
    >
        <div class="flex flex-col items-center gap-3 bg-bg-surface px-8 py-6 shadow-[0_10px_25px_rgba(0,0,0,.15)]">
            <span class="day-khata-spinner h-8 w-8 rounded-full border-4 border-border border-t-primary" aria-hidden="true" />
            <p v-if="message" class="text-[12.5px] font-semibold text-text-muted">{{ message }}</p>
        </div>
    </div>
    <div v-else class="flex flex-col items-center justify-center gap-2 py-4">
        <span class="day-khata-spinner h-8 w-8 rounded-full border-4 border-border border-t-primary" aria-hidden="true" />
        <p v-if="message" class="text-[12.5px] font-semibold text-text-muted">{{ message }}</p>
    </div>
</template>

<style scoped>
.day-khata-spinner {
    animation: day-khata-spin 0.9s linear infinite;
}

@keyframes day-khata-spin {
    to {
        transform: rotate(360deg);
    }
}

@media (prefers-reduced-motion: reduce) {
    .day-khata-spinner {
        animation: none;
    }
}
</style>
