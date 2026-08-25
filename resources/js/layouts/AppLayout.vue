<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import Toaster from '@/components/ui/Toaster.vue';

const props = defineProps({
    title: {
        type: String,
        default: '',
    },
    navItems: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();

/**
 * Either context's authenticated principal: a central platform admin or a
 * tenant user, whichever the current guard resolved. Both are shared as
 * `auth.platformAdmin` / `auth.user` by HandleInertiaRequests.
 */
const currentPrincipal = computed(() => page.props.auth?.platformAdmin ?? page.props.auth?.user ?? null);

function isActive(href) {
    return page.url === href || (href !== '/' && page.url.startsWith(`${href}/`));
}

function logout() {
    router.post('/logout');
}
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-screen bg-bg-page">
        <aside class="flex w-60 shrink-0 flex-col border-r border-border bg-bg-surface">
            <div class="flex h-14 items-center border-b border-border px-5">
                <span class="text-base font-bold text-text-strong">Day Khata</span>
            </div>

            <nav class="flex-1 overflow-y-auto py-3">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-2.5 px-5 py-2.5 text-sm font-semibold transition-colors"
                    :class="
                        isActive(item.href)
                            ? 'bg-primary-tint text-primary'
                            : 'text-text-muted hover:bg-bg-subtle hover:text-text-strong'
                    "
                >
                    <component :is="item.icon" v-if="item.icon" class="size-4 shrink-0" />
                    {{ item.label }}
                </Link>
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-14 shrink-0 items-center justify-between border-b border-border bg-bg-surface px-6">
                <h1 class="text-sm font-bold text-text-strong">{{ title }}</h1>

                <div class="flex items-center gap-4">
                    <div v-if="currentPrincipal" class="text-right leading-tight">
                        <p class="text-sm font-semibold text-text-strong">{{ currentPrincipal.name }}</p>
                        <p class="text-xs text-text-muted">{{ currentPrincipal.email }}</p>
                    </div>

                    <Button variant="secondary" tone="purple" type="button" @click="logout">Log out</Button>
                </div>
            </header>

            <main class="flex-1 bg-bg-page p-6">
                <slot />
            </main>
        </div>
    </div>

    <Toaster />
</template>
