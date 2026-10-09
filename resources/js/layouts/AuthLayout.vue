<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import logoFull from '@/assets/brand/logo-full.png';
import logoMark from '@/assets/brand/logo-mark.png';

const page = usePage();

/**
 * Set only on a tenant domain (shared `tenant` prop is null on the central
 * one), so the business name and the "Powered by" credit show on a shop's
 * own login and never on the Platform Admin login.
 */
const companyName = computed(() => page.props.tenant?.company_name ?? '');

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

            <div class="relative flex flex-1 flex-col justify-center py-16 pr-14 pl-20">
                <img :src="logoFull" alt="Day Khata" class="mb-10 h-12 w-auto self-start object-contain" />
                <template v-if="companyName">
                    <p class="mb-3 text-3xl leading-tight font-bold break-words text-text-strong">
                        {{ companyName }}
                    </p>
                    <p class="max-w-[28ch] text-base leading-snug text-text-muted">
                        {{ tagline }}
                    </p>
                </template>
                <p v-else class="max-w-[19ch] text-2xl leading-snug font-bold text-text-strong">
                    {{ tagline }}
                </p>
            </div>
        </div>

        <div class="flex min-w-0 flex-1 flex-col px-4">
            <div class="flex flex-1 flex-col items-center justify-center py-12">
                <img :src="logoMark" alt="Day Khata" class="mb-6 h-12 w-auto object-contain lg:hidden" />
                <p v-if="companyName" class="mb-6 max-w-sm text-center text-lg font-bold break-words text-text-strong lg:hidden">
                    {{ companyName }}
                </p>

                <div class="w-full max-w-sm">
                    <slot />
                </div>
            </div>

            <p v-if="companyName" class="pb-6 text-center text-xs text-text-muted">
                Powered by <span class="font-semibold text-text-base">Day Khata</span>
            </p>
        </div>
    </div>
</template>
