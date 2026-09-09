<script setup>
import { Head } from '@inertiajs/vue3';
import logoFull from '@/assets/brand/logo-full.png';
import logoMark from '@/assets/brand/logo-mark.png';

defineProps({
    title: {
        type: String,
        default: '',
    },
    /**
     * One-line description of what this login unlocks, shown on the ledger
     * panel (desktop only). Each Auth page supplies its own so Tenant vs
     * Platform Admin logins read as genuinely different audiences, not the
     * same copy twice.
     */
    tagline: {
        type: String,
        default: 'Sales, stock, and khata - all in one place.',
    },
});
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-screen bg-bg-surface">
        <!--
            The ledger panel: a page ruled like the physical day-book "khata"
            this product is named after - thin horizontal rules and a red
            margin line, the one bold device this screen spends its
            boldness on. Desktop-only; the form panel carries the mobile
            experience alone rather than squeezing this in.
        -->
        <div class="relative hidden w-[42%] max-w-[480px] shrink-0 overflow-hidden border-r border-border lg:flex lg:flex-col lg:justify-between">
            <div
                class="pointer-events-none absolute inset-0"
                style="
                    background-image: repeating-linear-gradient(
                        to bottom,
                        transparent,
                        transparent 34px,
                        var(--color-border) 35px
                    );
                "
            ></div>
            <div class="pointer-events-none absolute top-0 bottom-0 left-14 w-px bg-danger/25"></div>

            <div class="relative flex flex-1 flex-col justify-center px-14 py-16">
                <img :src="logoFull" alt="Day Khata" class="mb-10 h-9 w-auto object-contain" />
                <p class="max-w-[19ch] text-2xl leading-snug font-bold text-text-strong">
                    {{ tagline }}
                </p>
            </div>
        </div>

        <div class="flex min-w-0 flex-1 flex-col items-center justify-center px-4 py-12">
            <img :src="logoMark" alt="Day Khata" class="mb-6 h-10 w-auto object-contain lg:hidden" />

            <div class="w-full max-w-sm">
                <slot />
            </div>
        </div>
    </div>
</template>
