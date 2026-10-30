<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ChevronRight, Search, X } from '@lucide/vue';

/**
 * The sidebar's navigation: a filter box and the permission-filtered nav
 * sections (see lib/nav-items.js for the shape).
 *
 * With `accordion` on (the tenant app) every labelled section is a heading
 * that expands on click, one at a time; the section holding the current page
 * opens itself on every navigation, so the menu reads as a handful of
 * headings instead of ~30 links. A section without a label (the dashboard)
 * is always visible. With it off (the central app) sections are plain,
 * always-open lists.
 *
 * Typing in the filter box lists every matching page across all sections,
 * expanded, regardless of which section is open; Enter opens the first match.
 */
const props = defineProps({
    sections: { type: Array, default: () => [] },
    accordion: { type: Boolean, default: true },
});

const page = usePage();
const currentPath = computed(() => page.url.split('?')[0]);

function isActive(item) {
    const path = currentPath.value;
    return path === item.href || (!item.exact && item.href !== '/' && path.startsWith(`${item.href}/`));
}

/** A section's pages, whether it lists them directly or through categories. */
function itemsOf(section) {
    return section.categories ? section.categories.flatMap((category) => category.items) : section.items;
}

/** A section's pages as [{ label, items }] groups; label is null for a flat section. */
function groupsOf(section, items = null) {
    if (section.categories) {
        return section.categories
            .map((category) => ({ label: category.label, items: items ? category.items.filter((item) => items.includes(item)) : category.items }))
            .filter((group) => group.items.length > 0);
    }
    return [{ label: null, items: items ?? section.items }];
}

const anyItemActive = computed(() => props.sections.some((section) => itemsOf(section).some(isActive)));

/**
 * A section may declare `activePrefix` for pages that live under it but have
 * no link of their own (e.g. most reports): it then counts as the current
 * section, but only when no link anywhere matches the path.
 */
function ownsPathByPrefix(section) {
    return Boolean(section.activePrefix) && !anyItemActive.value && currentPath.value.startsWith(section.activePrefix);
}

function sectionHasActive(section) {
    return itemsOf(section).some(isActive) || ownsPathByPrefix(section);
}

function isLinkActive(section, item) {
    if (isActive(item)) return true;
    // Prefix-owned page: highlight the section's first link (the hub).
    return ownsPathByPrefix(section) && itemsOf(section)[0] === item;
}

function sectionKey(section, index) {
    return section.label || `section-${index}`;
}

function panelId(section, index) {
    return `nav-${String(sectionKey(section, index)).toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
}

const activeSectionKey = computed(() => {
    const index = props.sections.findIndex((section) => section.label && sectionHasActive(section));
    return index === -1 ? null : sectionKey(props.sections[index], index);
});

const openKey = ref(activeSectionKey.value);

watch(
    () => page.url,
    () => {
        if (activeSectionKey.value) openKey.value = activeSectionKey.value;
    },
);

function toggle(section, index) {
    const key = sectionKey(section, index);
    openKey.value = openKey.value === key ? null : key;
}

const query = ref('');
const normalizedQuery = computed(() => query.value.trim().toLowerCase());

/** Sections reduced to the pages matching the filter box (all of them when it is empty). */
const visibleSections = computed(() => {
    if (normalizedQuery.value === '') return props.sections;

    const result = [];
    for (const section of props.sections) {
        const matches = itemsOf(section).filter((item) => item.label.toLowerCase().includes(normalizedQuery.value));
        if (matches.length > 0) result.push({ ...section, matches });
    }
    return result;
});

const isFiltering = computed(() => normalizedQuery.value !== '');

function isExpanded(section, index) {
    if (!props.accordion || isFiltering.value || !section.label) return true;
    return openKey.value === sectionKey(section, index);
}

function groupsToShow(section) {
    return groupsOf(section, section.matches ?? null);
}

function openFirstMatch() {
    const first = visibleSections.value[0]?.matches?.[0];
    if (first) {
        query.value = '';
        router.visit(first.href);
    }
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="shrink-0 px-3 pt-3">
            <div class="relative">
                <Search class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-text-faint" />
                <input
                    v-model="query"
                    type="text"
                    aria-label="Filter menu"
                    placeholder="Filter menu..."
                    class="w-full border border-border bg-bg-subtle py-1.5 pr-8 pl-8 text-[12.5px] text-text-base placeholder:text-text-faint focus:border-primary focus:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                    @keydown.enter.prevent="openFirstMatch"
                    @keydown.esc="query = ''"
                />
                <button
                    v-if="query"
                    type="button"
                    class="absolute top-1/2 right-1.5 flex size-5 -translate-y-1/2 cursor-pointer items-center justify-center text-text-faint hover:text-text-strong"
                    aria-label="Clear filter"
                    @click="query = ''"
                >
                    <X class="size-3.5" />
                </button>
            </div>
        </div>

        <nav class="flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto px-3 py-3" aria-label="Main">
            <p v-if="isFiltering && visibleSections.length === 0" class="px-2.5 py-3 text-center text-[12.5px] text-text-faint">
                No pages match "{{ query.trim() }}"
            </p>

            <div v-for="(section, index) in visibleSections" :key="sectionKey(section, index)" class="flex flex-col">
                <template v-if="section.label">
                    <button
                        v-if="accordion && !isFiltering"
                        type="button"
                        :aria-expanded="isExpanded(section, index)"
                        :aria-controls="panelId(section, index)"
                        class="flex w-full cursor-pointer items-center justify-between px-2.5 py-2 text-[11px] font-bold tracking-wide uppercase transition-colors hover:text-text-strong focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary"
                        :class="isExpanded(section, index) ? 'text-text-strong' : 'text-text-muted'"
                        @click="toggle(section, index)"
                    >
                        <span class="flex items-center gap-1.5">
                            {{ section.label }}
                            <span
                                v-if="!isExpanded(section, index) && sectionHasActive(section)"
                                class="size-1.5 rounded-full bg-primary"
                                aria-label="Contains the current page"
                            ></span>
                        </span>
                        <ChevronRight class="size-3 shrink-0 transition-transform duration-150" :class="{ 'rotate-90': isExpanded(section, index) }" />
                    </button>
                    <p v-else class="px-2.5 pt-2 pb-1 text-[11px] font-bold tracking-wide text-text-muted uppercase">{{ section.label }}</p>
                </template>

                <div v-if="isExpanded(section, index)" :id="panelId(section, index)" class="flex flex-col gap-0.5 pb-1">
                    <template v-for="group in groupsToShow(section)" :key="group.label ?? 'items'">
                        <p v-if="group.label" class="px-2.5 pt-2 pb-0.5 text-[11px] font-semibold text-text-faint">{{ group.label }}</p>
                        <Link
                            v-for="item in group.items"
                            :key="item.href"
                            :href="item.href"
                            :aria-current="isLinkActive(section, item) ? 'page' : undefined"
                            class="flex cursor-pointer items-center gap-2.5 px-2.5 py-1.5 text-[13px] font-semibold transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary"
                            :class="isLinkActive(section, item) ? 'bg-primary-tint text-primary' : 'text-text-muted hover:bg-bg-subtle hover:text-text-strong'"
                        >
                            <component :is="item.icon" v-if="item.icon" class="size-4 shrink-0" />
                            {{ item.label }}
                        </Link>
                    </template>
                </div>
            </div>
        </nav>
    </div>
</template>
