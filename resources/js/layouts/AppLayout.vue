<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Bell, ChevronDown, Search } from '@lucide/vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
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

// Tenant pages pass grouped navItems ([{ label, items }]); Central pages still
// pass a flat legacy list ([{ label, href, icon }]). Normalize both into
// groups here so the template only ever renders one shape.
const groups = computed(() => {
    if (props.navItems.length > 0 && Array.isArray(props.navItems[0]?.items)) {
        return props.navItems;
    }
    return [{ label: null, items: props.navItems }];
});

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
        <aside class="flex w-[264px] shrink-0 flex-col border-r border-border bg-bg-surface">
            <div class="flex h-[72px] items-center gap-2.5 border-b border-border px-5">
                <div class="flex size-[30px] shrink-0 items-center justify-center bg-primary text-sm font-bold text-white shadow-primary-sm">
                    DK
                </div>
                <div class="min-w-0 leading-tight">
                    <p class="text-sm font-bold text-text-strong">Day Khata</p>
                    <p
                        v-if="page.props.tenant?.company_name"
                        class="truncate text-[10px] font-bold tracking-wide text-text-faint uppercase"
                    >
                        {{ page.props.tenant.company_name }}
                    </p>
                </div>
            </div>

            <nav class="flex flex-1 flex-col gap-[18px] overflow-y-auto px-3 py-4">
                <div v-for="(group, index) in groups" :key="group.label ?? index" class="flex flex-col gap-1">
                    <p
                        v-if="group.label"
                        class="px-2.5 pb-1 text-[10px] font-bold tracking-wide text-text-faint uppercase"
                    >
                        {{ group.label }}
                    </p>

                    <Link
                        v-for="item in group.items"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center gap-2.5 px-2.5 py-2.5 text-sm font-semibold transition-colors"
                        :class="
                            isActive(item.href)
                                ? 'bg-primary-tint text-primary'
                                : 'text-text-muted hover:bg-bg-subtle hover:text-text-strong'
                        "
                    >
                        <component :is="item.icon" v-if="item.icon" class="size-[17px] shrink-0" />
                        {{ item.label }}
                    </Link>
                </div>
            </nav>

            <div class="flex items-center gap-2.5 border-t border-border px-4 py-4">
                <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-tint text-sm font-bold text-primary">
                    {{ currentPrincipal?.name?.charAt(0)?.toUpperCase() }}
                </div>
                <div class="min-w-0 leading-tight">
                    <p class="overflow-hidden text-ellipsis whitespace-nowrap text-sm font-semibold text-text-strong">
                        {{ currentPrincipal?.name }}
                    </p>
                    <p class="overflow-hidden text-ellipsis whitespace-nowrap text-xs text-text-muted">
                        {{ currentPrincipal?.email }}
                    </p>
                </div>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-16 shrink-0 items-center justify-between border-b border-border bg-bg-surface px-6">
                <h1 class="text-sm font-bold text-text-strong">{{ title }}</h1>

                <div class="mx-auto flex max-w-[340px] flex-1 items-center">
                    <div class="relative w-full">
                        <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-text-faint" />
                        <input
                            type="text"
                            placeholder="Search..."
                            disabled
                            class="w-full border border-border bg-bg-subtle py-1.5 pr-3 pl-8 text-[12.5px] text-text-base placeholder:text-text-faint"
                        />
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <Tooltip label="Notifications">
                        <button type="button" class="flex items-center justify-center text-text-muted">
                            <Bell class="size-5" />
                        </button>
                    </Tooltip>

                    <div class="h-[22px] w-px bg-border"></div>

                    <DropdownMenu align="end">
                        <template #trigger>
                            <button type="button" class="flex items-center gap-2.5">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-tint text-sm font-bold text-primary">
                                    {{ currentPrincipal?.name?.charAt(0)?.toUpperCase() }}
                                </div>
                                <span class="text-sm font-semibold text-text-strong">{{ currentPrincipal?.name }}</span>
                                <ChevronDown class="size-4 text-text-muted" />
                            </button>
                        </template>

                        <DropdownMenuItem v-if="page.props.auth?.platformAdmin" @select="() => router.visit('/two-factor')">
                            Two-Factor Authentication
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="logout">Log out</DropdownMenuItem>
                    </DropdownMenu>
                </div>
            </header>

            <main class="flex-1 bg-bg-page p-6">
                <slot />
            </main>
        </div>
    </div>

    <Toaster />
</template>
