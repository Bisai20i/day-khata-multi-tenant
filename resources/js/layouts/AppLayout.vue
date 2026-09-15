<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Bell, ChevronDown, ChevronRight, FileSignature, Package, PackageSearch, Plus, ScanBarcode, Search, ShoppingCart, Users } from '@lucide/vue';
import { PopoverAnchor, PopoverContent, PopoverPortal, PopoverRoot } from 'reka-ui';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import Toaster from '@/components/ui/Toaster.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import logoMark from '@/assets/brand/logo-mark.png';
import { navGroups, centralNavItems } from '@/lib/nav-items';
import { useLayoutChrome } from '@/composables/useLayoutChrome';

const page = usePage();

// title comes from useLayoutChrome (set by whichever page is currently
// mounted inside our slot); fullscreen hides the sidebar/navbar entirely,
// used by Pos.vue's focus-mode toggle to get close to the legacy POS's
// distraction-free, full-page app shell without a separate layout.
const chrome = useLayoutChrome();

// Central pages authenticate as a platform admin, tenant pages as a tenant
// user - whichever the current guard resolved decides which nav list this
// visit gets. Previously each of the ~80 pages built + passed this down as
// a `nav-items` prop; now AppLayout (persistent across navigations, see
// useLayoutChrome's docblock) derives it itself from the shared page props.
const navItems = computed(() => {
    if (page.props.auth?.platformAdmin) return centralNavItems;
    if (page.props.auth?.user) return navGroups(page.props.auth.user.role?.slug === 'admin');
    return [];
});

/**
 * Either context's authenticated principal: a central platform admin or a
 * tenant user, whichever the current guard resolved. Both are shared as
 * `auth.platformAdmin` / `auth.user` by HandleInertiaRequests.
 */
const currentPrincipal = computed(() => page.props.auth?.platformAdmin ?? page.props.auth?.user ?? null);

// Tenant pages pass grouped navItems ([{ label, items }] and/or
// [{ label, categories: [{ label, items }] }]); Central pages still pass a
// flat legacy list ([{ label, href, icon }]). Normalize into sections here so
// the template only ever renders one shape: every section has either `items`
// (flat leaf pages) or `categories` (a collapsible middle tier), never both.
const sections = computed(() => {
    if (navItems.value.length > 0 && (Array.isArray(navItems.value[0]?.items) || Array.isArray(navItems.value[0]?.categories))) {
        return navItems.value;
    }
    return [{ label: null, items: navItems.value }];
});

function isActive(href) {
    return page.url === href || (href !== '/' && page.url.startsWith(`${href}/`));
}

// A category is a toggleable accordion only once it has something worth
// hiding; a single-item category stays a plain link.
function isCollapsible(category) {
    return Boolean(category.label) && category.items.length > 1;
}

function categoryHasActiveItem(category) {
    return category.items.some((item) => isActive(item.href));
}

const OPEN_CATEGORIES_STORAGE_KEY = 'day-khata:sidebar-open-categories';

function readStoredOpenCategories() {
    try {
        return JSON.parse(localStorage.getItem(OPEN_CATEGORIES_STORAGE_KEY)) ?? {};
    } catch {
        return {};
    }
}

/**
 * Open/closed state per collapsible category, keyed by
 * `${section.label}::${category.label}` (categories can share a label across
 * different sections, e.g. none today but this keeps them independent).
 * Seeded once here at module-level mount: a category the user previously
 * left open stays open across navigations via localStorage, and any
 * category not yet in storage defaults to open only if it contains the page
 * currently being viewed. Sections with flat `items` (no categories) have
 * nothing to seed.
 */
const storedOpenCategories = readStoredOpenCategories();
const openCategories = reactive({});
for (const section of sections.value) {
    for (const category of section.categories ?? []) {
        const key = `${section.label}::${category.label}`;
        openCategories[key] = key in storedOpenCategories ? storedOpenCategories[key] : categoryHasActiveItem(category);
    }
}

function isCategoryOpen(section, category) {
    return !isCollapsible(category) || Boolean(openCategories[`${section.label}::${category.label}`]);
}

function toggleCategory(section, category) {
    const key = `${section.label}::${category.label}`;
    openCategories[key] = !openCategories[key];
    try {
        localStorage.setItem(OPEN_CATEGORIES_STORAGE_KEY, JSON.stringify(openCategories));
    } catch {
        // Storage unavailable (private browsing, disabled) - state just won't persist.
    }
}

function logout() {
    router.post('/logout');
}

/**
 * Navbar quick-search (tenant app only, see the `v-if="page.props.auth?.user"`
 * gate around it in the template - central platform-admin has no equivalent
 * searchable domain data). This is a client-side command palette: it jumps
 * to pages/actions rather than searching customer/item records, so matching
 * happens in-memory against a static command list - no backend round trip.
 */
const QUICK_ACTIONS = [
    { label: 'New Sale', href: '/sales', icon: ShoppingCart, group: 'Quick Actions' },
    { label: 'New Purchase', href: '/purchases', icon: PackageSearch, group: 'Quick Actions' },
    { label: 'New Quotation', href: '/quotations', icon: FileSignature, group: 'Quick Actions' },
    { label: 'New Customer', href: '/customers', icon: Users, group: 'Quick Actions' },
    { label: 'New Item', href: '/items', icon: Package, group: 'Quick Actions' },
];

// Every sidebar destination, flattened out of `sections` (already normalized
// and admin-filtered by the page that built the `navItems` prop) into the
// same { label, href, icon, group } shape as the quick actions above. Each
// command's `group` is its category label when the section has categories,
// falling back to the section label for flat sections - so search results
// keep a sensible, specific group heading either way.
const navCommands = computed(() =>
    sections.value.flatMap((section) =>
        (section.categories ?? [{ label: section.label, items: section.items }]).flatMap((category) =>
            category.items.map((item) => ({ label: item.label, href: item.href, icon: item.icon, group: category.label ?? 'Navigate' })),
        ),
    ),
);

const allCommands = computed(() => [...QUICK_ACTIONS, ...navCommands.value]);

const searchQuery = ref('');
const searchOpen = ref(false);
const activeIndex = ref(0);

// Flat, in-order matches - used both for keyboard-navigation indices and as
// the source for the grouped view below.
const filteredCommands = computed(() => {
    const query = searchQuery.value.trim().toLowerCase();
    if (query === '') return [];
    return allCommands.value.filter(
        (command) => command.label.toLowerCase().includes(query) || command.group.toLowerCase().includes(query),
    );
});

// Same matches grouped by their nav group / "Quick Actions", each entry
// keeping its index into `filteredCommands` so the template can highlight
// the active (keyboard-selected) row without losing the grouping.
const groupedResults = computed(() => {
    const byGroup = new Map();
    filteredCommands.value.forEach((command, index) => {
        if (!byGroup.has(command.group)) byGroup.set(command.group, []);
        byGroup.get(command.group).push({ ...command, index });
    });
    return [...byGroup.entries()].map(([label, items]) => ({ label, items }));
});

function onSearchInput() {
    activeIndex.value = 0;
    searchOpen.value = searchQuery.value.trim() !== '';
}

function moveActiveIndex(delta) {
    const total = filteredCommands.value.length;
    if (total === 0) return;
    activeIndex.value = (activeIndex.value + delta + total) % total;
}

function selectCommand(command) {
    searchOpen.value = false;
    searchQuery.value = '';
    router.visit(command.href);
}

function selectActiveCommand() {
    const command = filteredCommands.value[activeIndex.value];
    if (command) selectCommand(command);
}
</script>

<template>
    <Head :title="chrome.title" />

    <div class="flex h-screen overflow-hidden bg-bg-page">
        <aside v-if="!chrome.fullscreen" class="flex w-[264px] shrink-0 flex-col border-r border-border bg-bg-surface">
            <div class="flex h-[72px] shrink-0 items-center gap-2.5 border-b border-border px-5">
                <img :src="logoMark" alt="Day Khata" class="size-[30px] shrink-0 object-contain" />
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

            <nav class="flex min-h-0 flex-1 flex-col gap-[18px] overflow-y-auto px-3 py-4">
                <div v-for="(section, index) in sections" :key="section.label ?? index" class="flex flex-col gap-1">
                    <p v-if="section.label" class="px-2.5 pb-1 text-[10px] font-bold tracking-wide text-text-faint uppercase">
                        {{ section.label }}
                    </p>

                    <!-- Sections with a `categories` middle tier: each category is its
                         own collapsible accordion (the 3rd tier legacy had). -->
                    <template v-if="section.categories">
                        <div v-for="category in section.categories" :key="category.label" class="flex flex-col gap-1">
                            <button
                                v-if="isCollapsible(category)"
                                type="button"
                                class="flex cursor-pointer items-center justify-between px-2.5 pt-1 pb-1 text-[11.5px] font-semibold text-text-muted transition-colors hover:text-text-strong"
                                @click="toggleCategory(section, category)"
                            >
                                <span>{{ category.label }}</span>
                                <ChevronRight
                                    class="size-3 shrink-0 transition-transform duration-150"
                                    :class="{ 'rotate-90': openCategories[`${section.label}::${category.label}`] }"
                                />
                            </button>
                            <p v-else-if="category.label" class="px-2.5 pt-1 pb-1 text-[11.5px] font-semibold text-text-muted">
                                {{ category.label }}
                            </p>

                            <template v-if="isCategoryOpen(section, category)">
                                <Link
                                    v-for="item in category.items"
                                    :key="item.href"
                                    :href="item.href"
                                    class="flex items-center gap-2.5 py-2.5 pr-2.5 pl-4 text-sm font-semibold transition-colors"
                                    :class="
                                        isActive(item.href)
                                            ? 'bg-primary-tint text-primary'
                                            : 'text-text-muted hover:bg-bg-subtle hover:text-text-strong'
                                    "
                                >
                                    <component :is="item.icon" v-if="item.icon" class="size-[17px] shrink-0" />
                                    {{ item.label }}
                                </Link>
                            </template>
                        </div>
                    </template>

                    <!-- Flat sections (too small to be worth a category tier): pages
                         listed directly under the section label. -->
                    <template v-else>
                        <Link
                            v-for="item in section.items"
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
                    </template>
                </div>
            </nav>

            <div class="flex shrink-0 items-center gap-2.5 border-t border-border px-4 py-4">
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

        <div class="flex min-h-0 min-w-0 flex-1 flex-col">
            <header v-if="!chrome.fullscreen" class="flex h-16 shrink-0 items-center justify-between border-b border-border bg-bg-surface px-6">
                <h1 class="text-sm font-bold text-text-strong">{{ chrome.title }}</h1>

                <div v-if="page.props.auth?.user" class="mx-auto flex max-w-[340px] flex-1 items-center">
                    <PopoverRoot v-model:open="searchOpen">
                        <PopoverAnchor as-child>
                            <div class="relative w-full">
                                <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-text-faint" />
                                <input
                                    v-model="searchQuery"
                                    type="text"
                                    placeholder="Search pages, actions…"
                                    class="w-full border border-border bg-bg-subtle py-1.5 pr-3 pl-8 text-[12.5px] text-text-base placeholder:text-text-faint focus:border-primary focus:bg-white focus:outline-none"
                                    @input="onSearchInput"
                                    @focus="() => { if (searchQuery.trim() !== '') searchOpen = true; }"
                                    @keydown.esc="searchOpen = false"
                                    @keydown.down.prevent="moveActiveIndex(1)"
                                    @keydown.up.prevent="moveActiveIndex(-1)"
                                    @keydown.enter.prevent="selectActiveCommand"
                                />
                            </div>
                        </PopoverAnchor>

                        <PopoverPortal>
                            <PopoverContent
                                class="z-50 w-[var(--reka-popover-trigger-width)] border-[1.5px] border-border bg-white shadow-[0_8px_24px_rgba(0,0,0,.12)]"
                                position="popper"
                                side="bottom"
                                align="start"
                                :side-offset="4"
                                @open-auto-focus.prevent
                            >
                                <div class="max-h-80 overflow-y-auto p-1">
                                    <p v-if="filteredCommands.length === 0" class="px-2.5 py-3 text-center text-[12.5px] text-text-faint">
                                        No results found.
                                    </p>
                                    <template v-else>
                                        <div v-for="group in groupedResults" :key="group.label" class="flex flex-col">
                                            <p class="px-2.5 pt-2 pb-1 text-[10px] font-bold tracking-wide text-text-faint uppercase">{{ group.label }}</p>
                                            <button
                                                v-for="command in group.items"
                                                :key="`${command.group}-${command.href}-${command.label}`"
                                                type="button"
                                                class="flex w-full items-center gap-2.5 px-2.5 py-2 text-left text-[13px] outline-none"
                                                :class="
                                                    command.index === activeIndex
                                                        ? 'bg-primary-tint text-primary'
                                                        : 'hover:bg-primary-tint hover:text-primary'
                                                "
                                                @mouseenter="activeIndex = command.index"
                                                @click="selectCommand(command)"
                                            >
                                                <component :is="command.icon" v-if="command.icon" class="size-3.5 shrink-0 text-text-faint" />
                                                <span class="min-w-0 flex-1 truncate font-medium text-text-base">{{ command.label }}</span>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </PopoverContent>
                        </PopoverPortal>
                    </PopoverRoot>
                </div>
                <div v-else class="mx-auto flex max-w-[340px] flex-1 items-center"></div>

                <div class="flex items-center gap-4">
                    <DropdownMenu v-if="page.props.auth?.user" align="end">
                        <template #trigger>
                            <button type="button" title="Quick Create" class="flex items-center justify-center text-text-muted">
                                <Plus class="size-5" />
                            </button>
                        </template>

                        <DropdownMenuItem @select="() => router.visit('/sales')">New Sale</DropdownMenuItem>
                        <DropdownMenuItem @select="() => router.visit('/purchases')">New Purchase</DropdownMenuItem>
                        <DropdownMenuItem @select="() => router.visit('/quotations')">New Quotation</DropdownMenuItem>
                        <DropdownMenuItem @select="() => router.visit('/customers')">New Customer</DropdownMenuItem>
                        <DropdownMenuItem @select="() => router.visit('/items')">New Item</DropdownMenuItem>
                    </DropdownMenu>

                    <Tooltip v-if="page.props.auth?.user" label="POS">
                        <Link href="/pos" class="flex items-center justify-center text-text-muted">
                            <ScanBarcode class="size-5" />
                        </Link>
                    </Tooltip>

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

                        <DropdownMenuItem v-if="page.props.auth?.user" @select="() => router.visit('/profile')">
                            My Profile
                        </DropdownMenuItem>
                        <DropdownMenuItem v-if="page.props.auth?.platformAdmin" @select="() => router.visit('/two-factor')">
                            Two-Factor Authentication
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="logout">Log out</DropdownMenuItem>
                    </DropdownMenu>
                </div>
            </header>

            <main :class="['min-h-0 flex-1 overflow-y-auto bg-bg-page', chrome.fullscreen ? 'p-4' : 'p-6']">
                <slot />
            </main>
        </div>
    </div>

    <Toaster />
    <ConfirmDialog />
</template>
